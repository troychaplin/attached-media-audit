<?php
namespace Attached_Media_Audit\Scanner;

use Attached_Media_Audit\DB\Index_Table;

class Batch_Runner {

	const CRON_HOOK     = 'media_audit_full_scan';
	const BATCH_SIZE    = 50;
	const CURSOR_KEY    = 'media_audit_cursor';
	const PROGRESS_KEY  = 'media_audit_progress';
	const INDEX_BUILT_KEY = 'media_audit_index_built';

	/** Post types scanned for media references. */
	const SCAN_POST_TYPES = array( 'post', 'page', 'wp_template', 'wp_template_part' );

	/** Statuses considered "live". The count denominator and the scan loop both
	 * use this exact list so progress can reach 100%. Excludes trash/auto-draft. */
	const SCAN_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/** Trigger a fresh full scan (clears index and cursor). */
	public static function start_fresh(): void {
		self::unschedule();
		Index_Table::truncate();
		delete_transient( self::CURSOR_KEY );
		delete_option( self::INDEX_BUILT_KEY );

		$total = self::get_total_post_count();
		update_option( self::PROGRESS_KEY, array(
			'status'   => 'scanning',
			'progress' => 0,
			'total'    => $total,
		), false );

		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	/** Called by WP-Cron. Processes one batch, then reschedules itself if more remain. */
	public static function run_batch(): void {
		$after_id = (int) get_transient( self::CURSOR_KEY );
		$total    = self::get_total_post_count();

		// Cache file sizes a bounded number at a time on every tick. Each call
		// only touches attachments still missing the cached meta, so once the
		// library is fully cached this is a cheap no-op. Doing the whole library
		// on batch 0 risked exceeding max_execution_time on large sites.
		self::cache_attachment_file_sizes( self::BATCH_SIZE );

		$scanner = new Post_Scanner( self::get_all_attachment_ids() );
		$ids     = self::get_batch( $after_id );

		$last_id = $after_id;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$scanner->scan( $post );
			}
			$last_id = $id;
		}

		$progress = self::get_progress();
		$done     = (int) ( $progress['progress'] ?? 0 ) + count( $ids );

		if ( count( $ids ) < self::BATCH_SIZE ) {
			// Final (short) batch — done.
			delete_transient( self::CURSOR_KEY );
			update_option( self::INDEX_BUILT_KEY, true, false );
			update_option( self::PROGRESS_KEY, array(
				'status'   => 'complete',
				'progress' => $total,
				'total'    => $total,
			), false );
		} else {
			// Keyset cursor: store the last ID seen so the next batch resumes at
			// ID > cursor. Insensitive to inserts/deletes outside the processed
			// range, so concurrent edits cannot cause skips.
			set_transient( self::CURSOR_KEY, $last_id, HOUR_IN_SECONDS );
			update_option( self::PROGRESS_KEY, array(
				'status'   => 'scanning',
				'progress' => min( $done, $total ),
				'total'    => $total,
			), false );
			wp_schedule_single_event( time() + 1, self::CRON_HOOK );
		}
	}

	/**
	 * Fetch the next batch of scannable post IDs after a given ID (keyset paging).
	 *
	 * @param int $after_id
	 * @return int[]
	 */
	private static function get_batch( int $after_id ): array {
		global $wpdb;
		$type_ph   = implode( ',', array_fill( 0, count( self::SCAN_POST_TYPES ), '%s' ) );
		$status_ph = implode( ',', array_fill( 0, count( self::SCAN_STATUSES ), '%s' ) );
		$args      = array_merge( self::SCAN_POST_TYPES, self::SCAN_STATUSES, array( $after_id, self::BATCH_SIZE ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ({$type_ph})
				AND post_status IN ({$status_ph})
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				...$args
			)
		);
		// phpcs:enable

		return array_map( 'intval', $ids );
	}

	public static function get_progress(): array {
		$default = array( 'status' => 'idle', 'progress' => 0, 'total' => 0 );
		return (array) get_option( self::PROGRESS_KEY, $default );
	}

	/** Re-index a single post (called from save_post hook). */
	public static function reindex_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'attachment' === $post->post_type ) {
			return;
		}

		// Trashing/auto-drafting fires save_post; purge rather than re-index so
		// the post's attachments stop being counted as used.
		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			Index_Table::delete_for_post( $post_id );
			return;
		}

		$scanner = new Post_Scanner( self::get_all_attachment_ids() );
		$scanner->scan( $post );
	}

	private static function get_total_post_count(): int {
		global $wpdb;
		$type_ph   = implode( ',', array_fill( 0, count( self::SCAN_POST_TYPES ), '%s' ) );
		$status_ph = implode( ',', array_fill( 0, count( self::SCAN_STATUSES ), '%s' ) );
		$args      = array_merge( self::SCAN_POST_TYPES, self::SCAN_STATUSES );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type IN ({$type_ph})
				AND post_status IN ({$status_ph})",
				...$args
			)
		);
		// phpcs:enable
	}

	/**
	 * Cache file-size meta for attachments still missing it.
	 *
	 * @param int $limit Maximum attachments to process this call (0 = no limit).
	 */
	private static function cache_attachment_file_sizes( int $limit = 0 ): void {
		global $wpdb;
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';
		// Only process attachments that don't already have the cached meta.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_Attached_Media_Audit_filesize'
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
			AND pm.meta_id IS NULL{$limit_sql}"
		);
		// phpcs:enable

		foreach ( $ids as $id ) {
			$id        = (int) $id;
			$file_size = 0;
			$meta      = wp_get_attachment_metadata( $id );
			if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
				$file_size = (int) $meta['filesize'];
			}
			if ( ! $file_size ) {
				$path = get_attached_file( $id );
				if ( $path && file_exists( $path ) ) {
					$file_size = (int) filesize( $path );
				}
			}
			if ( $file_size > 0 ) {
				update_post_meta( $id, '_Attached_Media_Audit_filesize', $file_size );
			}
		}
	}

	private static function get_all_attachment_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);
		return array_map( 'intval', $ids );
	}
}

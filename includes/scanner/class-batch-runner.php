<?php
namespace Smart_Media_Audit\Scanner;

use Smart_Media_Audit\DB\Index_Table;

class Batch_Runner {

	const CRON_HOOK     = 'smart_media_audit_full_scan';
	const BATCH_SIZE    = 50;
	/** Filesize backfill and summary rebuild touch one row each — safe to chunk larger. */
	const FILESIZE_BATCH = 200;
	const SUMMARY_BATCH  = 200;
	const CURSOR_KEY    = 'smart_media_audit_cursor';
	const SUMMARY_CURSOR_KEY = 'smart_media_audit_summary_cursor';
	const PHASE_KEY     = 'smart_media_audit_phase';
	const PROGRESS_KEY  = 'smart_media_audit_progress';
	const INDEX_BUILT_KEY = 'smart_media_audit_index_built';
	const ATTACHMENT_IDS_KEY = 'smart_media_audit_attachment_ids';

	/** Scan phases, run in order. Each is bounded per cron tick. */
	const PHASE_POSTS     = 'posts';
	const PHASE_FILESIZES = 'filesizes';
	const PHASE_SUMMARY   = 'summary';

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

	/** Trigger a fresh full scan (clears index and cursors). */
	public static function start_fresh(): void {
		self::unschedule();
		Index_Table::truncate();
		delete_transient( self::CURSOR_KEY );
		delete_transient( self::SUMMARY_CURSOR_KEY );
		delete_transient( self::ATTACHMENT_IDS_KEY );
		delete_option( self::INDEX_BUILT_KEY );

		// Start at the posts phase. The previous summary stays visible until the
		// summary phase truncates and rebuilds it.
		set_transient( self::PHASE_KEY, self::PHASE_POSTS, DAY_IN_SECONDS );

		$total = self::get_total_post_count();
		update_option( self::PROGRESS_KEY, array(
			'status'   => 'scanning',
			'progress' => 0,
			'total'    => $total,
		), false );

		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	/**
	 * Schedule a background summary rebuild without re-scanning post content.
	 * Used by the upgrade migration: the index is already populated, so we drain
	 * file sizes then chunk-build the summary — no heavy work on the init request.
	 */
	public static function schedule_summary_rebuild(): void {
		self::unschedule();
		delete_transient( self::SUMMARY_CURSOR_KEY );
		set_transient( self::PHASE_KEY, self::PHASE_FILESIZES, DAY_IN_SECONDS );

		$total = self::get_total_post_count();
		update_option( self::PROGRESS_KEY, array(
			'status'   => 'scanning',
			'progress' => $total,
			'total'    => $total,
		), false );

		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	/**
	 * Called by WP-Cron. Runs one bounded slice of the current phase, then
	 * reschedules itself until all phases complete. Phases run in order:
	 * posts (index) -> filesizes (backfill meta) -> summary (chunked rebuild).
	 */
	public static function run_batch(): void {
		$phase = (string) ( get_transient( self::PHASE_KEY ) ?: self::PHASE_POSTS );

		switch ( $phase ) {
			case self::PHASE_FILESIZES:
				self::run_filesize_phase();
				break;
			case self::PHASE_SUMMARY:
				self::run_summary_phase();
				break;
			case self::PHASE_POSTS:
			default:
				self::run_posts_phase();
				break;
		}
	}

	/** Phase 1: scan a bounded batch of posts into the index (keyset paging). */
	private static function run_posts_phase(): void {
		$after_id = (int) get_transient( self::CURSOR_KEY );
		$total    = self::get_total_post_count();

		// Per-post writes skip the incremental summary refresh; the summary phase
		// rebuilds the whole projection in chunks once the index is populated.
		Index_Table::$defer_summary = true;

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
			// Posts exhausted — advance to the filesize backfill phase.
			delete_transient( self::CURSOR_KEY );
			set_transient( self::PHASE_KEY, self::PHASE_FILESIZES, DAY_IN_SECONDS );
			self::set_scanning_progress( $total, $total );
		} else {
			// Keyset cursor: resume at ID > cursor next tick. Insensitive to
			// inserts/deletes outside the processed range.
			set_transient( self::CURSOR_KEY, $last_id, HOUR_IN_SECONDS );
			self::set_scanning_progress( min( $done, $total ), $total );
		}
		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	/** Phase 2: backfill cached file sizes a bounded chunk at a time. */
	private static function run_filesize_phase(): void {
		$total = self::get_total_post_count();
		self::cache_attachment_file_sizes( self::FILESIZE_BATCH );

		if ( self::count_attachments_missing_filesize() > 0 ) {
			// More to cache — stay in this phase.
			self::set_scanning_progress( $total, $total );
		} else {
			// File sizes complete — start the summary rebuild from a clean slate.
			Index_Table::truncate_summary();
			set_transient( self::SUMMARY_CURSOR_KEY, 0, HOUR_IN_SECONDS );
			set_transient( self::PHASE_KEY, self::PHASE_SUMMARY, DAY_IN_SECONDS );
			self::set_scanning_progress( $total, $total );
		}
		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	/** Phase 3: rebuild the summary projection in bounded keyset chunks. */
	private static function run_summary_phase(): void {
		$total    = self::get_total_post_count();
		$after_id = (int) get_transient( self::SUMMARY_CURSOR_KEY );
		$ids      = self::get_attachment_ids_after( $after_id, self::SUMMARY_BATCH );

		if ( $ids ) {
			Index_Table::refresh_summary_for_attachments( $ids );
		}

		if ( count( $ids ) < self::SUMMARY_BATCH ) {
			// Final chunk — scan complete.
			delete_transient( self::SUMMARY_CURSOR_KEY );
			delete_transient( self::PHASE_KEY );
			update_option( self::INDEX_BUILT_KEY, true, false );
			update_option( self::PROGRESS_KEY, array(
				'status'   => 'complete',
				'progress' => $total,
				'total'    => $total,
			), false );
		} else {
			set_transient( self::SUMMARY_CURSOR_KEY, end( $ids ), HOUR_IN_SECONDS );
			self::set_scanning_progress( $total, $total );
			wp_schedule_single_event( time() + 1, self::CRON_HOOK );
		}
	}

	/** Persist a "scanning" progress snapshot. */
	private static function set_scanning_progress( int $progress, int $total ): void {
		update_option( self::PROGRESS_KEY, array(
			'status'   => 'scanning',
			'progress' => $progress,
			'total'    => $total,
		), false );
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
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
		// Single saves run in their own request; make sure the deferral flag a
		// concurrent scan tick might have set in this process doesn't suppress
		// the incremental summary refresh.
		Index_Table::$defer_summary = false;

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

		// Lazy mode: validate only this post's candidate IDs (one small query)
		// instead of loading every attachment ID on the site.
		$scanner = new Post_Scanner();
		$scanner->scan( $post );
	}

	private static function get_total_post_count(): int {
		global $wpdb;
		$type_ph   = implode( ',', array_fill( 0, count( self::SCAN_POST_TYPES ), '%s' ) );
		$status_ph = implode( ',', array_fill( 0, count( self::SCAN_STATUSES ), '%s' ) );
		$args      = array_merge( self::SCAN_POST_TYPES, self::SCAN_STATUSES );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
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

	/** Count attachments that still lack the cached file-size meta. */
	private static function count_attachments_missing_filesize(): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_smart_media_audit_filesize'
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
			AND pm.meta_id IS NULL"
		);
		// phpcs:enable
	}

	/**
	 * Keyset page of attachment IDs after a cursor, for the chunked summary phase.
	 *
	 * @param int $after_id
	 * @param int $limit
	 * @return int[]
	 */
	private static function get_attachment_ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'attachment' AND post_status = 'inherit'
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				$after_id,
				$limit
			)
		);
		// phpcs:enable
		return array_map( 'intval', $ids );
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_smart_media_audit_filesize'
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
				update_post_meta( $id, '_smart_media_audit_filesize', $file_size );
			}
		}
	}

	/**
	 * Return all attachment IDs on the site, cached for the scan's duration.
	 *
	 * Previously re-queried (and array_flipped) on every cron tick and every
	 * post save. The cache is invalidated when an attachment is added or
	 * deleted (see Plugin::init), so a newly uploaded attachment is still
	 * picked up as a valid reference target.
	 *
	 * @return int[]
	 */
	private static function get_all_attachment_ids(): array {
		$cached = get_transient( self::ATTACHMENT_IDS_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);
		$ids = array_map( 'intval', $ids );

		set_transient( self::ATTACHMENT_IDS_KEY, $ids, HOUR_IN_SECONDS );
		return $ids;
	}

	/** Drop the cached attachment-ID set (on attachment add/delete). */
	public static function flush_attachment_ids(): void {
		delete_transient( self::ATTACHMENT_IDS_KEY );
	}
}

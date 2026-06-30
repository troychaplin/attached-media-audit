<?php
namespace Smart_Media_Audit\Scanner;

use Smart_Media_Audit\DB\Index_Table;

class Post_Scanner {

	/**
	 * Lookup set of valid attachment IDs (id => true), or null for lazy mode.
	 * Batch scanning preloads the whole set (fast across many posts); single-post
	 * re-indexing leaves this null and validates only that post's candidates.
	 *
	 * @var array<int,true>|null
	 */
	private ?array $known;

	public function __construct( ?array $all_attachment_ids = null ) {
		$this->known = is_array( $all_attachment_ids ) ? array_flip( $all_attachment_ids ) : null;
	}

	/**
	 * Scan a single post and upsert its attachment references into the index.
	 */
	public function scan( \WP_Post $post ): void {
		// 1. Collect raw candidate references in priority order (the first type
		//    seen for an ID wins). Parsers emit candidates; validation happens
		//    once, below, so the meta parser doesn't need the full ID set.
		$candidates = array();

		$thumbnail_id = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
		if ( $thumbnail_id > 0 ) {
			$candidates[] = array( 'id' => $thumbnail_id, 'type' => 'featured_image', 'alt' => false );
		}
		foreach ( Block_Parser::extract( $post->post_content ) as $entry ) {
			$candidates[] = array( 'id' => (int) $entry['id'], 'type' => 'block', 'alt' => (bool) $entry['missing_alt'] );
		}
		foreach ( Classic_Parser::extract( $post->post_content ) as $entry ) {
			$candidates[] = array( 'id' => (int) $entry['id'], 'type' => 'classic', 'alt' => (bool) $entry['missing_alt'] );
		}
		foreach ( Meta_Parser::extract( $post->ID ) as $id ) {
			$candidates[] = array( 'id' => (int) $id, 'type' => 'postmeta', 'alt' => false );
		}

		// 2. Resolve which candidate IDs are real attachments. Only index those —
		//    this prevents phantom rows from stale wp-image-{id} classes, deleted
		//    attachments, or dangling _thumbnail_id from corrupting the counts.
		$ids = array_values( array_unique( array_filter(
			array_map( static fn( $c ) => (int) $c['id'], $candidates ),
			static fn( $id ) => $id > 0
		) ) );
		$known = $this->known ?? self::validate_attachment_ids( $ids );

		// 3. Build the index rows (dedupe by ID; missing_alt is OR'd across all
		//    occurrences of the same attachment).
		$rows         = array();
		$seen         = array();
		$missing_alts = array();
		foreach ( $candidates as $candidate ) {
			$id = (int) $candidate['id'];
			if ( $id <= 0 || ! isset( $known[ $id ] ) ) {
				continue;
			}
			if ( $candidate['alt'] ) {
				$missing_alts[ $id ] = true;
			} elseif ( ! array_key_exists( $id, $missing_alts ) ) {
				$missing_alts[ $id ] = false;
			}
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$rows[]      = array(
				'attachment_id'  => $id,
				'reference_type' => $candidate['type'],
			);
		}

		foreach ( $rows as &$row ) {
			$row['missing_alt'] = (int) ( $missing_alts[ $row['attachment_id'] ] ?? 0 );
		}
		unset( $row );

		Index_Table::replace_for_post( $post->ID, $rows );
	}

	/**
	 * Return the subset of the given IDs that are real attachments, as an
	 * id => true lookup set. One query — used in lazy (single-post) mode.
	 *
	 * @param int[] $ids
	 * @return array<int,true>
	 */
	private static function validate_attachment_ids( array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		global $wpdb;
		// Safe to inline: every element is an intval'd integer.
		$in = implode( ', ', array_map( 'intval', $ids ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			WHERE ID IN ({$in}) AND post_type = 'attachment' AND post_status = 'inherit'"
		);
		// phpcs:enable
		return array_flip( array_map( 'intval', $found ) );
	}
}

<?php
namespace WP_Media_Audit\Scanner;

use WP_Media_Audit\DB\Index_Table;

class Post_Scanner {

	/** @var int[] All attachment IDs on the site — loaded once per batch. */
	private array $all_attachment_ids;

	/** @var array<int,true> Lookup set of valid attachment IDs. */
	private array $known;

	public function __construct( array $all_attachment_ids ) {
		$this->all_attachment_ids = $all_attachment_ids;
		$this->known              = array_flip( $all_attachment_ids );
	}

	/**
	 * Scan a single post and upsert its attachment references into the index.
	 */
	public function scan( \WP_Post $post ): void {
		$rows         = array();
		$seen         = array();
		$missing_alts = array(); // id => bool, tracks alt status across all occurrences
		$known        = $this->known;

		// Only index IDs that resolve to a real attachment. This prevents phantom
		// rows from stale wp-image-{id} classes, blocks whose attachment was
		// deleted, or a dangling _thumbnail_id — which would otherwise corrupt
		// the used/unused counts.
		$add = function( int $id, string $type, bool $alt_missing = false ) use ( &$rows, &$seen, &$missing_alts, $known ) {
			if ( $id <= 0 || ! isset( $known[ $id ] ) ) {
				return;
			}
			// Track missing_alt with OR semantics across all occurrences.
			if ( $alt_missing ) {
				$missing_alts[ $id ] = true;
			} elseif ( ! array_key_exists( $id, $missing_alts ) ) {
				$missing_alts[ $id ] = false;
			}
			if ( isset( $seen[ $id ] ) ) {
				return;
			}
			$seen[ $id ] = true;
			$rows[]      = array(
				'attachment_id'  => $id,
				'reference_type' => $type,
			);
		};

		// 1. Featured image.
		$thumbnail_id = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
		if ( $thumbnail_id > 0 ) {
			$add( $thumbnail_id, 'featured_image' );
		}

		// 2. Gutenberg blocks.
		foreach ( Block_Parser::extract( $post->post_content ) as $entry ) {
			$add( $entry['id'], 'block', $entry['missing_alt'] );
		}

		// 3. Classic HTML + shortcodes.
		foreach ( Classic_Parser::extract( $post->post_content ) as $entry ) {
			$add( $entry['id'], 'classic', $entry['missing_alt'] );
		}

		// 4. Registered postmeta keys.
		foreach ( Meta_Parser::extract( $post->ID, $this->all_attachment_ids ) as $id ) {
			$add( $id, 'postmeta' );
		}

		// Apply the aggregated missing_alt status to each index row.
		foreach ( $rows as &$row ) {
			$row['missing_alt'] = (int) ( $missing_alts[ $row['attachment_id'] ] ?? 0 );
		}
		unset( $row );

		Index_Table::replace_for_post( $post->ID, $rows );
	}
}

<?php
namespace WP_Media_Audit\Scanner;

class Block_Parser {

	/** Map of block name → attribute key(s) holding attachment IDs. */
	const BLOCK_MAP = array(
		'core/image'      => array( 'id' ),
		'core/cover'      => array( 'id' ),
		'core/file'       => array( 'id' ),
		'core/video'      => array( 'id' ),
		'core/audio'      => array( 'id' ),
		'core/media-text' => array( 'mediaId' ),
		'core/gallery'    => array( 'ids' ),
	);

	/** Map of block name → attribute key holding the alt text (only for visual media blocks). */
	const ALT_MAP = array(
		'core/image'      => 'alt',
		'core/media-text' => 'mediaAlt',
	);

	/**
	 * Parse all blocks (including innerBlocks) and return attachment rows.
	 *
	 * @param string $post_content
	 * @return array<array{id: int, missing_alt: bool}>
	 */
	public static function extract( string $post_content ): array {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return array();
		}

		$blocks = parse_blocks( $post_content );
		$rows   = array();
		self::walk( $blocks, $rows );

		// Deduplicate by ID, keeping missing_alt=true if any occurrence lacks alt.
		$deduped = array();
		foreach ( $rows as $row ) {
			$id = $row['id'];
			if ( ! isset( $deduped[ $id ] ) ) {
				$deduped[ $id ] = $row;
			} elseif ( $row['missing_alt'] ) {
				$deduped[ $id ]['missing_alt'] = true;
			}
		}

		return array_values( $deduped );
	}

	private static function walk( array $blocks, array &$rows ): void {
		foreach ( $blocks as $block ) {
			$name  = $block['blockName'] ?? '';
			$attrs = $block['attrs'] ?? array();

			if ( isset( self::BLOCK_MAP[ $name ] ) ) {
				$alt_key     = self::ALT_MAP[ $name ] ?? null;
				$missing_alt = ( null !== $alt_key ) && '' === ( $attrs[ $alt_key ] ?? '' );

				foreach ( self::BLOCK_MAP[ $name ] as $key ) {
					if ( ! isset( $attrs[ $key ] ) ) {
						continue;
					}
					$val = $attrs[ $key ];
					if ( is_array( $val ) ) {
						// Gallery IDs: no per-image alt available in block attrs.
						foreach ( $val as $id ) {
							$id = (int) $id;
							if ( $id > 0 ) {
								$rows[] = array( 'id' => $id, 'missing_alt' => false );
							}
						}
					} elseif ( is_numeric( $val ) && $val > 0 ) {
						$rows[] = array( 'id' => (int) $val, 'missing_alt' => $missing_alt );
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $rows );
			}
		}
	}
}

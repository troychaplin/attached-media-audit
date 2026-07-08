<?php
namespace Attached_Media_Audit\Scanner;

class Classic_Parser {

	/**
	 * Extract attachment rows from classic HTML content and shortcodes.
	 *
	 * @param string $post_content
	 * @return array<array{id: int, missing_alt: bool}>
	 */
	public static function extract( string $post_content ): array {
		$rows = array();

		// Find all <img> tags and check both the attachment ID and whether alt is present.
		if ( preg_match_all( '/<img\s[^>]*>/i', $post_content, $img_matches ) ) {
			foreach ( $img_matches[0] as $img_tag ) {
				if ( preg_match( '/class=["\'][^"\']*wp-image-(\d+)[^"\']*["\']/', $img_tag, $id_match ) ) {
					$id = (int) $id_match[1];
					if ( $id > 0 ) {
						// alt="" counts as missing; absent alt also counts as missing.
						$has_alt = (bool) preg_match( '/\balt=["\']([^"\']+)["\']/', $img_tag );
						$rows[]  = array( 'id' => $id, 'missing_alt' => ! $has_alt );
					}
				}
			}
		}

		// [gallery ids="1,2,3"] — per-image alt not available in shortcode attrs.
		foreach ( self::parse_shortcode( $post_content, 'gallery', 'ids' ) as $id ) {
			$rows[] = array( 'id' => $id, 'missing_alt' => false );
		}

		// [caption id="attachment_123"] — alt status already captured from inner <img> above.
		if ( preg_match_all( '/\[caption[^\]]*\bid="attachment_(\d+)"/', $post_content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$rows[] = array( 'id' => (int) $id, 'missing_alt' => false );
			}
		}

		// Find <a href> links pointing to the uploads directory.
		$upload_dir  = wp_get_upload_dir();
		$uploads_url = trailingslashit( $upload_dir['baseurl'] );

		if ( preg_match_all( '/<a\s[^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i', $post_content, $a_matches ) ) {
			foreach ( $a_matches[1] as $url ) {
				if ( strpos( $url, $uploads_url ) === 0 ) {
					$id = attachment_url_to_postid( $url );
					if ( $id > 0 ) {
						$rows[] = array( 'id' => $id, 'missing_alt' => false );
					}
				}
			}
		}

		// Deduplicate by ID, keeping missing_alt=true if any occurrence lacks alt.
		$deduped = array();
		foreach ( $rows as $row ) {
			$id = $row['id'];
			if ( $id <= 0 ) {
				continue;
			}
			if ( ! isset( $deduped[ $id ] ) ) {
				$deduped[ $id ] = $row;
			} elseif ( $row['missing_alt'] ) {
				$deduped[ $id ]['missing_alt'] = true;
			}
		}

		return array_values( $deduped );
	}

	private static function parse_shortcode( string $content, string $tag, string $attr ): array {
		$ids     = array();
		$pattern = get_shortcode_regex( array( $tag ) );

		if ( preg_match_all( "/{$pattern}/", $content, $matches ) ) {
			foreach ( $matches[3] as $attr_string ) {
				$attrs = shortcode_parse_atts( $attr_string );
				if ( ! empty( $attrs[ $attr ] ) ) {
					foreach ( explode( ',', $attrs[ $attr ] ) as $id ) {
						$id = (int) trim( $id );
						if ( $id > 0 ) {
							$ids[] = $id;
						}
					}
				}
			}
		}

		return $ids;
	}
}

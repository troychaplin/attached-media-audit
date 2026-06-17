<?php
namespace Attached_Media_Audit\Rest;

use Attached_Media_Audit\DB\Index_Table;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Media_Controller extends WP_REST_Controller {

	protected $namespace = 'attached-media-audit/v1';
	protected $rest_base = 'media';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
	}

	public function get_items_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	public function get_items( $request ) {
		$page           = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$per_page       = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$search         = sanitize_text_field( $request->get_param( 'search' ) ?: '' );
		$orderby        = sanitize_key( $request->get_param( 'orderby' ) ?: 'date' );
		$raw_order      = strtoupper( $request->get_param( 'order' ) ?: '' );
		$order          = in_array( $raw_order, array( 'ASC', 'DESC' ), true ) ? $raw_order : 'DESC';
		$media_type     = sanitize_text_field( $request->get_param( 'media_type' ) ?: '' );
		$reference_type = sanitize_key( $request->get_param( 'reference_type' ) ?: '' );
		$usage_filter   = sanitize_key( $request->get_param( 'usage_filter' ) ?: '' );

		$result = Index_Table::get_attachments_rest(
			search: $search,
			per_page: $per_page,
			page: $page,
			orderby: $orderby,
			order: $order,
			media_type: $media_type,
			reference_type: $reference_type,
			usage_filter: $usage_filter,
		);

		// Prime the post + meta caches for the whole page in two batched queries
		// so the per-row lookups in prepare_item() (image src, attachment URL,
		// title, edit link) don't each trigger a cold DB read — the N+1 that a
		// raw $wpdb result set otherwise incurs vs. WP_Query.
		$ids = array_map( static fn( $row ) => (int) $row->ID, $result['items'] );
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
			update_meta_cache( 'post', $ids );
		}

		$items = array_map( array( $this, 'prepare_item' ), $result['items'] );

		return new WP_REST_Response( array(
			'items' => $items,
			'total' => (int) $result['total'],
			'pages' => (int) ceil( $result['total'] / $per_page ),
		) );
	}

	private function prepare_item( object $row ): array {
		$id   = (int) $row->ID;
		$mime = $row->post_mime_type ?? '';

		if ( str_starts_with( $mime, 'image/' ) ) {
			$media_type = 'Image';
		} elseif ( str_starts_with( $mime, 'video/' ) ) {
			$media_type = 'Video';
		} elseif ( str_starts_with( $mime, 'audio/' ) ) {
			$media_type = 'Audio';
		} else {
			$media_type = 'Document';
		}

		$thumb_src     = wp_get_attachment_image_src( $id, array( 60, 60 ) );
		$thumbnail_url = $thumb_src ? $thumb_src[0] : '';

		// File size and alt text are already selected by the items query
		// (joined from postmeta), so no per-row get_post_meta() is needed here.
		$cached    = isset( $row->file_size ) ? (int) $row->file_size : 0;
		$file_size = $cached;
		if ( ! $file_size ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
				$file_size = (int) $meta['filesize'];
			}
		}
		if ( ! $file_size ) {
			$path = get_attached_file( $id );
			if ( $path && file_exists( $path ) ) {
				$file_size = (int) filesize( $path );
			}
		}
		if ( $file_size > 0 && ! $cached ) {
			update_post_meta( $id, '_Attached_Media_Audit_filesize', $file_size );
		}

		$alt_text = $row->alt_text ?? '';

		return array(
			'id'                  => $id,
			'title'               => get_the_title( $id ),
			'mime_type'           => $mime,
			'media_type'          => $media_type,
			'thumbnail_url'       => $thumbnail_url,
			'file_url'            => (string) wp_get_attachment_url( $id ),
			'edit_url'            => (string) get_edit_post_link( $id, 'raw' ),
			'file_size'           => $file_size,
			'alt_text'            => (string) $alt_text,
			'content_alt_missing' => (bool) ( $row->content_alt_missing ?? false ),
			'date'                => get_post_field( 'post_date', $id ),
			'usage_count'         => (int) $row->usage_count,
		);
	}

	public function get_collection_params(): array {
		return array(
			'page'           => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
			'per_page'       => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
			'search'         => array( 'type' => 'string', 'default' => '' ),
			'orderby'        => array(
				'type'    => 'string',
				'default' => 'date',
				'enum'    => array( 'title', 'date', 'usage', 'file_size' ),
			),
			'order'          => array(
				'type'    => 'string',
				'default' => 'DESC',
				'enum'    => array( 'ASC', 'DESC' ),
			),
			'media_type'     => array( 'type' => 'string', 'default' => '' ),
			'reference_type' => array( 'type' => 'string', 'default' => '' ),
			'usage_filter'   => array( 'type' => 'string', 'default' => '' ),
		);
	}
}

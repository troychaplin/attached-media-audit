<?php
namespace Smart_Media_Audit\Admin;

use Smart_Media_Audit\DB\Index_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class List_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'media_item',
			'plural'   => 'media_items',
			'ajax'     => false,
		) );
	}

	public function get_columns(): array {
		return array(
			'thumbnail'  => __( 'Preview', 'smart-media-audit' ),
			'post_title' => __( 'File Name', 'smart-media-audit' ),
			'mime_type'  => __( 'Type', 'smart-media-audit' ),
			'usage'      => __( 'Used In', 'smart-media-audit' ),
			'post_date'  => __( 'Date', 'smart-media-audit' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'post_title' => array( 'post_title', false ),
			'post_date'  => array( 'post_date', true ),
			'usage'      => array( 'usage', false ),
		);
	}

	public function prepare_items(): void {
		$per_page = 20;
		$paged    = $this->get_pagenum();
		$filter   = sanitize_key( $_GET['filter'] ?? 'all' );
		$search   = sanitize_text_field( $_GET['s'] ?? '' );

		// Whitelist sort params; get_attachments() maps them to safe columns.
		$orderby = sanitize_key( $_GET['orderby'] ?? 'post_date' );
		$order   = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';

		$result = Index_Table::get_attachments( $filter, $search, $per_page, $paged, $orderby, $order );

		// Explicitly set column headers. A custom add_submenu_page screen has no
		// registered column filters, so the screen-based fallback in
		// get_column_info() returns zero columns — which renders no <thead> and
		// no row cells. Setting this directly is required.
		$this->_column_headers = array(
			$this->get_columns(),
			array(),                          // hidden
			$this->get_sortable_columns(),
			'post_title',                     // primary column
		);

		$this->items = $result['items'];
		$this->set_pagination_args( array(
			'total_items' => $result['total'],
			'per_page'    => $per_page,
		) );
	}

	protected function column_default( $item, $column_name ): string {
		return esc_html( $item->$column_name ?? '' );
	}

	protected function column_thumbnail( $item ): string {
		$thumb = wp_get_attachment_image( $item->ID, array( 60, 60 ), true, array(
			'style' => 'width:60px;height:60px;object-fit:cover;border-radius:3px;',
		) );
		return $thumb ?: '<span class="dashicons dashicons-media-default" style="font-size:40px;width:40px;color:#c3c4c7;"></span>';
	}

	protected function column_post_title( $item ): string {
		$url  = wp_get_attachment_url( $item->ID );
		$edit = get_edit_post_link( $item->ID );
		$name = esc_html( $item->post_title ?: basename( $url ) );

		return sprintf(
			'<strong><a href="%s">%s</a></strong><div class="row-actions"><span><a href="%s" target="_blank">%s</a> | <a href="%s">%s</a></span></div>',
			esc_url( $edit ),
			$name,
			esc_url( $url ),
			__( 'View', 'smart-media-audit' ),
			esc_url( $edit ),
			__( 'Edit', 'smart-media-audit' )
		);
	}

	protected function column_mime_type( $item ): string {
		$parts = explode( '/', $item->post_mime_type );
		return esc_html( strtoupper( $parts[1] ?? $parts[0] ) );
	}

	protected function column_usage( $item ): string {
		$count = (int) $item->usage_count;
		if ( 0 === $count ) {
			return '<span class="smart-media-audit-unused">' . __( 'Unused', 'smart-media-audit' ) . '</span>';
		}

		return sprintf(
			'<button class="button-link smart-media-audit-locations-toggle" data-id="%d" aria-expanded="false">%s</button>'
			. '<span class="smart-media-audit-locations-row" id="smart-media-audit-loc-%d" hidden></span>',
			esc_attr( $item->ID ),
			sprintf( _n( '%d post', '%d posts', $count, 'smart-media-audit' ), $count ),
			esc_attr( $item->ID )
		);
	}

	protected function column_post_date( $item ): string {
		return esc_html( date_i18n( get_option( 'date_format' ), strtotime( $item->post_date ) ) );
	}

	/** Render the "no items" message. */
	public function no_items(): void {
		esc_html_e( 'No media items found.', 'smart-media-audit' );
	}

	/**
	 * Output filter-tab nav links (All / Used / Unused).
	 */
	public function get_views(): array {
		$current = sanitize_key( $_GET['filter'] ?? 'all' );
		$counts  = Index_Table::get_counts();
		$search  = sanitize_text_field( $_GET['s'] ?? '' );

		// Preserve an active search when switching tabs (false omits it when empty).
		$base = add_query_arg(
			array(
				'page' => 'smart-media-audit',
				's'    => '' !== $search ? $search : false,
			),
			admin_url( 'upload.php' )
		);

		$tabs = array(
			'all'    => array( 'label' => __( 'All', 'smart-media-audit' ),    'count' => $counts['total'] ),
			'used'   => array( 'label' => __( 'Used', 'smart-media-audit' ),   'count' => $counts['used'] ),
			'unused' => array( 'label' => __( 'Unused', 'smart-media-audit' ), 'count' => $counts['unused'] ),
		);

		$views = array();
		foreach ( $tabs as $key => $tab ) {
			$url     = add_query_arg( 'filter', $key, $base );
			$class   = $key === $current ? ' class="current"' : '';
			$views[ $key ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$class,
				esc_html( $tab['label'] ),
				(int) $tab['count']
			);
		}

		return $views;
	}
}

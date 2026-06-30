<?php
namespace Smart_Media_Audit\Admin;

use Smart_Media_Audit\Scanner\Batch_Runner;
use Smart_Media_Audit\DB\Index_Table;

class Ajax_Handler {

	public static function register(): void {
		add_action( 'wp_ajax_smart_media_audit_progress',    array( __CLASS__, 'handle_progress' ) );
		add_action( 'wp_ajax_smart_media_audit_scan',        array( __CLASS__, 'handle_scan' ) );
		add_action( 'wp_ajax_smart_media_audit_locations',   array( __CLASS__, 'handle_locations' ) );
		add_action( 'wp_ajax_smart_media_audit_clear_index', array( __CLASS__, 'handle_clear_index' ) );
	}

	public static function handle_progress(): void {
		check_ajax_referer( 'smart_media_audit_nonce', 'nonce' );
		// A nonce is anti-CSRF, not authorization — gate on capability too.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		wp_send_json_success( Batch_Runner::get_progress() );
	}

	public static function handle_scan(): void {
		check_ajax_referer( 'smart_media_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		Batch_Runner::start_fresh();
		wp_send_json_success( array( 'message' => 'Scan started' ) );
	}

	public static function handle_locations(): void {
		check_ajax_referer( 'smart_media_audit_nonce', 'nonce' );
		// Without this, any logged-in user with a valid nonce could enumerate
		// titles of private/draft posts via the locations data.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() is sanitization; already behind check_ajax_referer().
		if ( ! $attachment_id ) {
			wp_send_json_error( 'Missing attachment_id' );
		}

		$result   = Index_Table::get_locations( $attachment_id );
		$rows     = $result['rows'];
		$has_more = $result['has_more'];

		// Prime the post cache for the whole set in one query so the per-row
		// get_edit_post_link() calls below don't each trigger a cold lookup.
		$ids = wp_list_pluck( $rows, 'ID' );
		if ( $ids ) {
			_prime_post_caches( array_map( 'intval', $ids ), false, false );
		}

		// Build a capability-aware edit URL server-side so the client doesn't
		// assume a hardcoded /wp-admin path.
		$locations = array_map(
			static function ( $loc ) {
				return array(
					'ID'             => (int) $loc->ID,
					'post_title'     => $loc->post_title,
					'post_type'      => $loc->post_type,
					'reference_type' => $loc->reference_type,
					'edit_url'       => get_edit_post_link( (int) $loc->ID, 'raw' ) ?: '',
				);
			},
			$rows
		);

		wp_send_json_success( array(
			'locations' => $locations,
			'has_more'  => $has_more,
			'limit'     => Index_Table::LOCATIONS_LIMIT,
		) );
	}

	public static function handle_clear_index(): void {
		check_ajax_referer( 'smart_media_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		Batch_Runner::unschedule();
		Index_Table::truncate();
		Index_Table::truncate_summary();
		delete_transient( Batch_Runner::CURSOR_KEY );
		delete_transient( Batch_Runner::SUMMARY_CURSOR_KEY );
		delete_transient( Batch_Runner::PHASE_KEY );
		delete_transient( Batch_Runner::ATTACHMENT_IDS_KEY );
		delete_option( Batch_Runner::INDEX_BUILT_KEY );
		update_option( Batch_Runner::PROGRESS_KEY, array(
			'status'   => 'idle',
			'progress' => 0,
			'total'    => 0,
		), false );
		wp_send_json_success( array( 'message' => 'Index cleared' ) );
	}
}

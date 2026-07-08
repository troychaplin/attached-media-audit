<?php
namespace Attached_Media_Audit\Admin;

use Attached_Media_Audit\Scanner\Batch_Runner;

class Admin_Menu {

	public static function register(): void {
		add_action( 'admin_menu',            array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'upload.php',
			__( 'Media Audit', 'attached-media-audit' ),
			__( 'Media Audit', 'attached-media-audit' ),
			'manage_options',
			'attached-media-audit',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'media_page_attached-media-audit' !== $hook ) {
			return;
		}

		$asset_file = ATTACHED_MEDIA_AUDIT_DIR . 'build/attached-media-audit-admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset   = require $asset_file;
		$js_file = ATTACHED_MEDIA_AUDIT_DIR . 'build/attached-media-audit-admin.js';
		$version = defined( 'WP_DEBUG' ) && WP_DEBUG
			? filemtime( $js_file )
			: $asset['version'];

		wp_enqueue_script(
			'attached-media-audit-admin',
			plugin_dir_url( ATTACHED_MEDIA_AUDIT_FILE ) . 'build/attached-media-audit-admin.js',
			$asset['dependencies'],
			$version,
			true
		);

		wp_enqueue_style(
			'attached-media-audit-admin',
			plugin_dir_url( ATTACHED_MEDIA_AUDIT_FILE ) . 'build/attached-media-audit-admin.css',
			array( 'wp-components' ),
			$version
		);

		wp_add_inline_script(
			'attached-media-audit-admin',
			'window.wpAttachedMediaAudit = ' . wp_json_encode( array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'attached_media_audit_nonce' ),
				'restUrl'         => rest_url( 'attached-media-audit/v1/media' ),
				'restNonce'       => wp_create_nonce( 'wp_rest' ),
				'initialProgress' => Batch_Runner::get_progress(),
				'indexBuilt'      => (bool) get_option( Batch_Runner::INDEX_BUILT_KEY, false ),
			) ) . ';',
			'before'
		);
	}

	public static function render_page(): void {
		require_once ATTACHED_MEDIA_AUDIT_DIR . 'views/admin-page.php';
	}
}

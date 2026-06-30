<?php
namespace Smart_Media_Audit\Admin;

use Smart_Media_Audit\Scanner\Batch_Runner;

class Admin_Menu {

	public static function register(): void {
		add_action( 'admin_menu',            array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'upload.php',
			__( 'Media Audit', 'smart-media-audit' ),
			__( 'Media Audit', 'smart-media-audit' ),
			'manage_options',
			'smart-media-audit',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'media_page_smart-media-audit' !== $hook ) {
			return;
		}

		$asset_file = SMART_MEDIA_AUDIT_DIR . 'build/smart-media-audit-admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset   = require $asset_file;
		$js_file = SMART_MEDIA_AUDIT_DIR . 'build/smart-media-audit-admin.js';
		$version = defined( 'WP_DEBUG' ) && WP_DEBUG
			? filemtime( $js_file )
			: $asset['version'];

		wp_enqueue_script(
			'smart-media-audit-admin',
			plugin_dir_url( SMART_MEDIA_AUDIT_FILE ) . 'build/smart-media-audit-admin.js',
			$asset['dependencies'],
			$version,
			true
		);

		wp_enqueue_style(
			'smart-media-audit-admin',
			plugin_dir_url( SMART_MEDIA_AUDIT_FILE ) . 'build/smart-media-audit-admin.css',
			array( 'wp-components' ),
			$version
		);

		wp_add_inline_script(
			'smart-media-audit-admin',
			'window.wpSmartMediaAudit = ' . wp_json_encode( array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'smart_media_audit_nonce' ),
				'restUrl'         => rest_url( 'smart-media-audit/v1/media' ),
				'restNonce'       => wp_create_nonce( 'wp_rest' ),
				'initialProgress' => Batch_Runner::get_progress(),
				'indexBuilt'      => (bool) get_option( Batch_Runner::INDEX_BUILT_KEY, false ),
			) ) . ';',
			'before'
		);
	}

	public static function render_page(): void {
		require_once SMART_MEDIA_AUDIT_DIR . 'views/admin-page.php';
	}
}

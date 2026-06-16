<?php
namespace WP_Media_Audit\Admin;

use WP_Media_Audit\Scanner\Batch_Runner;

class Admin_Menu {

	public static function register(): void {
		add_action( 'admin_menu',            array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'upload.php',
			__( 'Media Audit', 'wp-media-audit' ),
			__( 'Media Audit', 'wp-media-audit' ),
			'manage_options',
			'wp-media-audit',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'media_page_wp-media-audit' !== $hook ) {
			return;
		}

		$asset_file = WP_MEDIA_AUDIT_DIR . 'build/media-audit-admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			'wp-media-audit-admin',
			plugin_dir_url( WP_MEDIA_AUDIT_FILE ) . 'build/media-audit-admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'wp-media-audit-admin',
			plugin_dir_url( WP_MEDIA_AUDIT_FILE ) . 'build/media-audit-admin.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_add_inline_script(
			'wp-media-audit-admin',
			'window.wpMediaAudit = ' . wp_json_encode( array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'media_audit_nonce' ),
				'restUrl'         => rest_url( 'wp-media-audit/v1/media' ),
				'restNonce'       => wp_create_nonce( 'wp_rest' ),
				'initialProgress' => Batch_Runner::get_progress(),
			) ) . ';',
			'before'
		);
	}

	public static function render_page(): void {
		require_once WP_MEDIA_AUDIT_DIR . 'views/admin-page.php';
	}
}

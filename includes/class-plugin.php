<?php
namespace WP_Media_Audit;

use WP_Media_Audit\Admin\Admin_Menu;
use WP_Media_Audit\Admin\Ajax_Handler;
use WP_Media_Audit\Rest\Media_Controller;
use WP_Media_Audit\Scanner\Batch_Runner;
use WP_Media_Audit\DB\Index_Table;

class Plugin {

	private static ?Plugin $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function maybe_upgrade_db(): void {
		$installed = get_option( 'wp_media_audit_db_version', '0' );
		if ( version_compare( $installed, WP_MEDIA_AUDIT_VERSION, '<' ) ) {
			Index_Table::create();
			update_option( 'wp_media_audit_db_version', WP_MEDIA_AUDIT_VERSION );
		}
	}

	public function init(): void {
		$this->maybe_upgrade_db();
		Admin_Menu::register();
		Ajax_Handler::register();
		add_action( 'rest_api_init', array( new Media_Controller(), 'register_routes' ) );

		add_action( Batch_Runner::CRON_HOOK, array( Batch_Runner::class, 'run_batch' ) );

		add_action( 'save_post', function( int $post_id, \WP_Post $post ) {
			if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
				return;
			}
			if ( 'attachment' === $post->post_type ) {
				return;
			}
			Batch_Runner::reindex_post( $post_id );
		}, 10, 2 );

		// Purge index rows that originate from a post when it is trashed or
		// permanently deleted, so its attachments stop counting as "used".
		add_action( 'trashed_post', array( Index_Table::class, 'delete_for_post' ) );
		add_action( 'before_delete_post', array( Index_Table::class, 'delete_for_post' ) );

		// Purge rows that reference an attachment when the attachment is deleted,
		// otherwise the orphaned row would skew the used/unused counts.
		add_action( 'delete_attachment', array( Index_Table::class, 'delete_for_attachment' ) );
	}
}

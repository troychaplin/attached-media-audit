<?php
namespace Smart_Media_Audit;

use Smart_Media_Audit\Admin\Admin_Menu;
use Smart_Media_Audit\Admin\Ajax_Handler;
use Smart_Media_Audit\Rest\Media_Controller;
use Smart_Media_Audit\Scanner\Batch_Runner;
use Smart_Media_Audit\DB\Index_Table;

class Plugin {

	private static ?Plugin $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function maybe_upgrade_db(): void {
		$installed = get_option( 'smart_media_audit_db_version', '0' );
		if ( version_compare( $installed, SMART_MEDIA_AUDIT_VERSION, '<' ) ) {
			$is_upgrade = version_compare( $installed, '2.1.0', '<' ) && '0' !== $installed;
			Index_Table::create();
			// 2.1.0 introduced the summary projection. On an existing install
			// that already has index data, populate it via a background, chunked
			// rebuild so the init request stays fast on large libraries. Fresh
			// installs have nothing to project and skip this.
			if ( $is_upgrade && Index_Table::has_index_rows() ) {
				Batch_Runner::schedule_summary_rebuild();
			}
			update_option( 'smart_media_audit_db_version', SMART_MEDIA_AUDIT_VERSION );
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

		// Invalidate the cached attachment-ID set whenever the library changes,
		// so the scanner validates references against current attachments. A new
		// upload also gets a summary row (usage 0) so it appears in the list.
		add_action( 'add_attachment', function( int $post_id ) {
			Batch_Runner::flush_attachment_ids();
			Index_Table::refresh_summary_for_attachments( array( $post_id ) );
		} );
		add_action( 'delete_attachment', array( Batch_Runner::class, 'flush_attachment_ids' ) );
	}
}

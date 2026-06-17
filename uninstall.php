<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/db/class-index-table.php';

Attached_Media_Audit\DB\Index_Table::drop();
delete_option( 'media_audit_progress' );
delete_option( 'media_audit_index_built' );
delete_option( 'Attached_Media_Audit_db_version' );
delete_transient( 'media_audit_cursor' );
delete_transient( 'media_audit_attachment_ids' );

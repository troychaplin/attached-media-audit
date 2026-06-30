<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/db/class-index-table.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/scanner/class-batch-runner.php';

use Smart_Media_Audit\DB\Index_Table;
use Smart_Media_Audit\Scanner\Batch_Runner;

Index_Table::drop();

// Reference the constants so these keys can never drift from what the plugin writes.
delete_option( Batch_Runner::PROGRESS_KEY );
delete_option( Batch_Runner::INDEX_BUILT_KEY );
delete_option( 'smart_media_audit_db_version' );
delete_transient( Batch_Runner::CURSOR_KEY );
delete_transient( Batch_Runner::SUMMARY_CURSOR_KEY );
delete_transient( Batch_Runner::PHASE_KEY );
delete_transient( Batch_Runner::ATTACHMENT_IDS_KEY );

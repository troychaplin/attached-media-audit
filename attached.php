<?php
/**
 * Plugin Name:       Attached: Media Audit
 * Description:       Audit and manage WordPress media files from one dashboard.
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Version:           2.0.1
 * Author:            Troy Chaplin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       attached-media-audit
 *
 * @package Attached_Media_Audit
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ATTACHED_MEDIA_AUDIT_VERSION', '2.0.1' );
define( 'ATTACHED_MEDIA_AUDIT_FILE', __FILE__ );
define( 'ATTACHED_MEDIA_AUDIT_DIR', plugin_dir_path( __FILE__ ) );

require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/db/class-index-table.php';
require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/scanner/class-block-parser.php';
require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/scanner/class-classic-parser.php';
require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/scanner/class-meta-parser.php';
require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/scanner/class-post-scanner.php';
require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/scanner/class-batch-runner.php';
require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/admin/class-ajax-handler.php';
require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/admin/class-admin-menu.php';
require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/rest/class-media-controller.php';
require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/class-activator.php';
require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/class-deactivator.php';
require_once ATTACHED_MEDIA_AUDIT_DIR . 'includes/class-plugin.php';

register_activation_hook( ATTACHED_MEDIA_AUDIT_FILE, array( 'Attached_Media_Audit\Activator', 'activate' ) );
register_deactivation_hook( ATTACHED_MEDIA_AUDIT_FILE, array( 'Attached_Media_Audit\Deactivator', 'deactivate' ) );

Attached_Media_Audit\Plugin::get_instance()->init();
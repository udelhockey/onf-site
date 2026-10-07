<?php
/**
 * Plugin Name:       ONF Core
 * Description:       Open Net Foundation site features: players, events, funds, gifts, donors, registration hooks, and admin branding.
 * Version:           0.7.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Madkel
 * Text Domain:       onf-core
 */

defined( 'ABSPATH' ) || exit;

define( 'ONF_CORE_VERSION', '0.7.0' );
define( 'ONF_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ONF_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once ONF_CORE_DIR . 'includes/login-branding.php';
require_once ONF_CORE_DIR . 'includes/install.php';
require_once ONF_CORE_DIR . 'includes/settings.php';
require_once ONF_CORE_DIR . 'includes/post-types.php';
require_once ONF_CORE_DIR . 'includes/series.php';
require_once ONF_CORE_DIR . 'includes/fields.php';
require_once ONF_CORE_DIR . 'includes/entries.php';
require_once ONF_CORE_DIR . 'includes/gifts.php';
require_once ONF_CORE_DIR . 'includes/donors.php';
require_once ONF_CORE_DIR . 'includes/receipts.php';
require_once ONF_CORE_DIR . 'includes/stripe.php';
require_once ONF_CORE_DIR . 'includes/donate.php';
require_once ONF_CORE_DIR . 'includes/shortcodes.php';

if ( is_admin() ) {
	require_once ONF_CORE_DIR . 'includes/admin/players.php';
	require_once ONF_CORE_DIR . 'includes/admin/events.php';
	require_once ONF_CORE_DIR . 'includes/admin/gifts.php';
	require_once ONF_CORE_DIR . 'includes/admin/donors.php';
	require_once ONF_CORE_DIR . 'includes/admin/settings.php';
	require_once ONF_CORE_DIR . 'includes/admin/migrate.php';
	require_once ONF_CORE_DIR . 'includes/admin/import.php';
	require_once ONF_CORE_DIR . 'includes/admin/totals.php';
	require_once ONF_CORE_DIR . 'includes/admin/report.php';
}

register_activation_hook( __FILE__, 'onf_core_activate' );

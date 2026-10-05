<?php
/**
 * Plugin Name:       ONF Core
 * Description:       Open Net Foundation site features: players, events, registration hooks, and admin branding.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Madkel
 * Text Domain:       onf-core
 */

defined( 'ABSPATH' ) || exit;

define( 'ONF_CORE_VERSION', '0.1.0' );
define( 'ONF_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ONF_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once ONF_CORE_DIR . 'includes/login-branding.php';

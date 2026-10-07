<?php
/**
 * ONF block theme. Design lives in theme.json; this file only loads the small stylesheet,
 * registers pattern categories and points the favicon at the skater mark.
 */

defined( 'ABSPATH' ) || exit;

define( 'ONF_THEME_VERSION', '0.1.0' );

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'onf-theme', get_stylesheet_uri(), array(), ONF_THEME_VERSION );
	}
);

// Same small stylesheet in the editor, so blocks look the same while editing.
add_action(
	'after_setup_theme',
	static function () {
		add_editor_style( 'style.css' );
	}
);

add_action(
	'init',
	static function () {
		register_block_pattern_category( 'onf', array( 'label' => __( 'Open Net Foundation', 'onf-theme' ) ) );
		register_block_pattern_category( 'onf-pages', array( 'label' => __( 'ONF page layouts', 'onf-theme' ) ) );
	}
);

// Favicon: the skater mark, unless a Site Icon has been set in Settings.
add_action(
	'wp_head',
	static function () {
		if ( ! has_site_icon() ) {
			echo '<link rel="icon" type="image/svg+xml" href="' . esc_url( get_theme_file_uri( 'assets/logos/mark-navy.svg' ) ) . '">' . "\n";
		}
	},
	5
);

/**
 * A theme logo file's URL, for patterns.
 */
function onf_theme_logo( $file ) {
	return esc_url( get_theme_file_uri( 'assets/logos/' . $file ) );
}

/**
 * Where Donate buttons go: the page using the "Donate page" template (via ONF Core), else /donation/.
 */
function onf_theme_donate_url() {
	return function_exists( 'onf_donate_page_url' ) ? onf_donate_page_url() : home_url( '/donation/' );
}

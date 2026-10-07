<?php
/**
 * ONF block theme. Design lives in theme.json; this file only loads the small stylesheet,
 * registers pattern categories and points the favicon at the skater mark.
 */

defined( 'ABSPATH' ) || exit;

define( 'ONF_THEME_VERSION', '0.1.1' );

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

/*
 * Elementor Pro's Theme Builder (still installed until cutover) swaps in its own page layout wherever
 * one of its templates matches — on staging, "player -general" matches every player page — because a
 * block theme registers no Elementor locations. For pages this theme owns (players, events, funds,
 * the events list and series pages) keep our block template. Old Elementor-built pages are untouched.
 */
add_filter(
	'template_include',
	static function ( $template ) {
		$GLOBALS['onf_theme_block_template'] = $template; // What WordPress picked, before Elementor (priority 11).
		return $template;
	},
	10
);
add_filter(
	'template_include',
	static function ( $template ) {
		$ours = $GLOBALS['onf_theme_block_template'] ?? '';
		if ( $ours && $template !== $ours && onf_theme_owns_request() ) {
			return $ours;
		}
		return $template;
	},
	99
);

function onf_theme_owns_request() {
	return is_singular( array( 'player', 'onf_event', 'onf_fund' ) )
		|| is_post_type_archive( 'onf_event' )
		|| is_tax( 'onf_series' );
}

/**
 * Where Donate buttons go: the page using the "Donate page" template (via ONF Core), else /donation/.
 */
function onf_theme_donate_url() {
	return function_exists( 'onf_donate_page_url' ) ? onf_donate_page_url() : home_url( '/donation/' );
}

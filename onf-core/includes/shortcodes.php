<?php
/**
 * [iframe] replacement for the removed "iframe" plugin. Player pages embed EliteProspects stats with
 * [iframe width="100%" height="300" src="https://www.eliteprospects.com/iframe_player_stats_small.php?player=…"].
 * Only allow-listed hosts are embedded; anything else renders nothing.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		if ( ! shortcode_exists( 'iframe' ) ) {
			add_shortcode( 'iframe', 'onf_iframe_shortcode' );
		}
	},
	20
);

function onf_iframe_allowed_hosts() {
	return apply_filters( 'onf_iframe_allowed_hosts', array( 'www.eliteprospects.com', 'eliteprospects.com' ) );
}

function onf_iframe_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'src'    => '',
			'width'  => '100%',
			'height' => '300',
		),
		$atts,
		'iframe'
	);
	$src  = esc_url_raw( $atts['src'], array( 'https' ) );
	$host = wp_parse_url( $src, PHP_URL_HOST );
	if ( ! $src || ! in_array( $host, onf_iframe_allowed_hosts(), true ) ) {
		return '';
	}
	$width  = preg_match( '/^\d+%?$/', $atts['width'] ) ? $atts['width'] : '100%';
	$height = preg_match( '/^\d+$/', $atts['height'] ) ? $atts['height'] : '300';
	return sprintf(
		'<iframe class="onf-embed onf-embed--stats" src="%s" width="%s" height="%s" loading="lazy" title="%s" referrerpolicy="no-referrer-when-downgrade"></iframe>',
		esc_url( $src ),
		esc_attr( $width ),
		esc_attr( $height ),
		esc_attr__( 'Player statistics from EliteProspects', 'onf-core' )
	);
}

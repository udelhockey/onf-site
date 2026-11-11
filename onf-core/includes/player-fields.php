<?php
/**
 * What player pages show. Every profile field is always collected (player edit screen, registration);
 * whether it appears on the site is decided twice:
 * 1. Site-wide (Gifts → Settings → Player pages): unticked = collected only, never shown.
 * 2. Per event ("Show on player pages" on the event): e.g. last team and EliteProspects stats for the
 *    junior events, not for the alumni games. An event without its own choice uses the newest event in
 *    the same series that has one (so a new year carries over last year's), else everything allowed.
 * A player page follows the event it is about (onf_player_focus_event()).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Things a player page can show, in display order: key => label.
 */
function onf_player_display_items() {
	return array(
		'number'            => __( 'Jersey number', 'onf-core' ),
		'position'          => __( 'Position', 'onf-core' ),
		'shot'              => __( 'Shoots', 'onf-core' ),
		'hometown'          => __( 'Hometown', 'onf-core' ),
		'size'              => __( 'Height / weight', 'onf-core' ),
		'college'           => __( 'College (with class year)', 'onf-core' ),
		'high_school'       => __( 'High school (with grad year)', 'onf-core' ),
		'current_team'      => __( 'Current team', 'onf-core' ),
		'last_team'         => __( 'Last team', 'onf-core' ),
		'favorite_nhl_team' => __( 'Favorite NHL team', 'onf-core' ),
		'sponsor'           => __( 'Sponsor', 'onf-core' ),
		'stats'             => __( 'EliteProspects stats', 'onf-core' ),
	);
}

/**
 * Items allowed on player pages site-wide.
 *
 * @return string[]
 */
function onf_player_items_sitewide() {
	$saved = onf_setting( 'player_show' );
	$all   = array_keys( onf_player_display_items() );
	return is_array( $saved ) ? array_values( array_intersect( $all, $saved ) ) : $all;
}

/**
 * An event's own choice, if it has one.
 *
 * @return string[]|null
 */
function onf_event_player_items_own( int $event_id ) {
	$saved = get_post_meta( $event_id, '_onf_player_show', true );
	return is_array( $saved ) ? $saved : null;
}

/**
 * What an event's player pages show, and where that choice comes from.
 *
 * @return array [ items => string[]|null (null = everything allowed site-wide), from => event ID (0 = none) ]
 */
function onf_event_player_items( int $event_id ) {
	$own = $event_id ? onf_event_player_items_own( $event_id ) : null;
	if ( null !== $own ) {
		return array(
			'items' => $own,
			'from'  => $event_id,
		);
	}
	$series = $event_id ? onf_event_series_id( $event_id ) : 0;
	if ( $series ) {
		$others = array_filter( onf_series_event_ids( $series ), static fn( $id ) => $id !== $event_id && null !== onf_event_player_items_own( $id ) );
		usort( $others, static fn( $a, $b ) => onf_event_year( $b ) <=> onf_event_year( $a ) ?: $b <=> $a );
		if ( $others ) {
			return array(
				'items' => onf_event_player_items_own( $others[0] ),
				'from'  => (int) $others[0],
			);
		}
	}
	return array(
		'items' => null,
		'from'  => 0,
	);
}

/**
 * Should this player's page show this item?
 */
function onf_player_shows( int $player_id, string $item ) {
	static $cache = array();
	if ( ! isset( $cache[ $player_id ] ) ) {
		$event               = onf_player_focus_event( $player_id );
		$event_items         = onf_event_player_items( $event )['items'];
		$cache[ $player_id ] = null === $event_items ? onf_player_items_sitewide() : array_values( array_intersect( onf_player_items_sitewide(), $event_items ) );
	}
	return in_array( $item, $cache[ $player_id ], true );
}

/**
 * "University of Delaware ’98", "Salesianum ’14": a school with a two-digit year when there is one.
 */
function onf_school_with_year( string $school, string $year ) {
	$school = trim( $school );
	if ( '' === $school ) {
		return '';
	}
	return preg_match( '/^(19|20)\d{2}$/', trim( $year ) ) ? $school . ' ’' . substr( trim( $year ), 2 ) : $school;
}

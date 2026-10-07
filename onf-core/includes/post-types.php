<?php
/**
 * Post types: player (one permanent page per person), event, fund.
 * `player` keeps its existing name so IDs and URLs don't change; it used to be registered by CPT UI.
 */

defined( 'ABSPATH' ) || exit;

// Stop CPT UI registering `player`; we do it here. The CPT UI plugin can be removed once nothing else uses it.
add_filter( 'cptui_disable_player_cpt', '__return_true' );

add_action( 'init', 'onf_register_post_types' );

function onf_register_post_types() {
	register_post_type(
		'player',
		array(
			'labels'       => array(
				'name'          => __( 'Players', 'onf-core' ),
				'singular_name' => __( 'Player', 'onf-core' ),
				'add_new_item'  => __( 'Add New Player', 'onf-core' ),
				'edit_item'     => __( 'Edit Player', 'onf-core' ),
			),
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => false,
			'rewrite'      => array(
				'slug'       => 'player',
				'with_front' => false,
			),
			'menu_icon'    => 'dashicons-id-alt',
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
		)
	);

	register_post_type(
		'onf_event',
		array(
			'labels'       => array(
				'name'          => __( 'Events', 'onf-core' ),
				'singular_name' => __( 'Event', 'onf-core' ),
				'add_new_item'  => __( 'Add New Event', 'onf-core' ),
				'edit_item'     => __( 'Edit Event', 'onf-core' ),
			),
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => 'events',
			'rewrite'      => array(
				'slug'       => 'events',
				'with_front' => false,
			),
			'menu_icon'    => 'dashicons-calendar-alt',
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
		)
	);

	register_post_type(
		'onf_fund',
		array(
			'labels'       => array(
				'name'          => __( 'Funds', 'onf-core' ),
				'singular_name' => __( 'Fund', 'onf-core' ),
				'add_new_item'  => __( 'Add New Fund', 'onf-core' ),
				'edit_item'     => __( 'Edit Fund', 'onf-core' ),
			),
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => false,
			'rewrite'      => array(
				'slug'       => 'funds',
				'with_front' => false,
			),
			'menu_icon'    => 'dashicons-heart',
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
		)
	);
}

/**
 * Event statuses, in lifecycle order.
 */
function onf_event_statuses() {
	return array(
		'registration' => __( 'Registration open', 'onf-core' ),
		'fundraising'  => __( 'Fundraising', 'onf-core' ),
		'closed'       => __( 'Closed', 'onf-core' ),
	);
}

/**
 * Archived players: kept with all their history, hidden from the everyday Players list and pickers.
 */
function onf_is_archived( int $player_id ) {
	return '1' === (string) get_post_meta( $player_id, '_onf_archived', true );
}

function onf_set_archived( int $player_id, bool $archived ) {
	if ( 'player' !== get_post_type( $player_id ) ) {
		return;
	}
	if ( $archived ) {
		update_post_meta( $player_id, '_onf_archived', '1' );
	} else {
		delete_post_meta( $player_id, '_onf_archived' );
	}
}

/**
 * Yoast Duplicate Post: when a player page is copied, don't copy its old web addresses or ONF's own data
 * (GiveWP links, archived/merged flags, private contact details). The copy starts as a clean new player.
 */
add_filter(
	'duplicate_post_meta_keys_filter',
	static function ( $keys ) {
		$private = array( '_wp_old_slug', 'email', 'phone', 'notes', 'birthday' );
		return array_values(
			array_filter(
				(array) $keys,
				static fn( $key ) => ! in_array( $key, $private, true ) && ! str_starts_with( (string) $key, '_onf_' )
			)
		);
	}
);

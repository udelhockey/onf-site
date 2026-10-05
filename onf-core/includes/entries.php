<?php
/**
 * Entries: a player taking part in an event. One row per player per event.
 */

defined( 'ABSPATH' ) || exit;

function onf_entries_table() {
	global $wpdb;
	return $wpdb->prefix . 'onf_entries';
}

/**
 * Add a player to an event. Does nothing if they're already in it.
 *
 * @return bool True if a new entry was created.
 */
function onf_add_entry( int $player_id, int $event_id, array $args = array() ) {
	global $wpdb;
	if ( 'player' !== get_post_type( $player_id ) || 'onf_event' !== get_post_type( $event_id ) ) {
		return false;
	}
	$goal = $args['goal'] ?? get_post_meta( $event_id, 'player_goal', true );

	$exists = $wpdb->get_var(
		$wpdb->prepare( 'SELECT id FROM ' . onf_entries_table() . ' WHERE player_id = %d AND event_id = %d', $player_id, $event_id )
	);
	if ( $exists ) {
		return false;
	}

	$created = $wpdb->insert(
		onf_entries_table(),
		array(
			'player_id'  => $player_id,
			'event_id'   => $event_id,
			'goal'       => is_numeric( $goal ) ? $goal : null,
			'sponsor'    => sanitize_text_field( $args['sponsor'] ?? '' ),
			'status'     => $args['status'] ?? 'active',
			'created_at' => current_time( 'mysql' ),
		)
	);
	if ( $created ) {
		onf_flush_totals();
		do_action( 'onf_entry_added', $player_id, $event_id );
	}
	return (bool) $created;
}

function onf_remove_entry( int $player_id, int $event_id ) {
	global $wpdb;
	$deleted = $wpdb->delete(
		onf_entries_table(),
		array(
			'player_id' => $player_id,
			'event_id'  => $event_id,
		),
		array( '%d', '%d' )
	);
	if ( $deleted ) {
		onf_flush_totals();
	}
	return (bool) $deleted;
}

function onf_update_entry( int $player_id, int $event_id, array $data ) {
	global $wpdb;
	$row = array();
	if ( array_key_exists( 'goal', $data ) ) {
		$row['goal'] = is_numeric( $data['goal'] ) ? $data['goal'] : null;
	}
	if ( isset( $data['sponsor'] ) ) {
		$row['sponsor'] = sanitize_text_field( $data['sponsor'] );
	}
	if ( ! $row ) {
		return false;
	}
	return (bool) $wpdb->update(
		onf_entries_table(),
		$row,
		array(
			'player_id' => $player_id,
			'event_id'  => $event_id,
		)
	);
}

/**
 * A player's events, newest event first.
 *
 * @return object[] Entry rows with event_title added.
 */
function onf_get_player_entries( int $player_id ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT e.*, p.post_title AS event_title FROM ' . onf_entries_table() . " e
			JOIN {$wpdb->posts} p ON p.ID = e.event_id
			WHERE e.player_id = %d ORDER BY p.post_date DESC",
			$player_id
		)
	);
}

/**
 * An event's roster, alphabetical.
 *
 * @return object[] Entry rows with player_name added.
 */
function onf_get_event_entries( int $event_id ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT e.*, p.post_title AS player_name FROM ' . onf_entries_table() . " e
			JOIN {$wpdb->posts} p ON p.ID = e.player_id
			WHERE e.event_id = %d ORDER BY p.post_title",
			$event_id
		)
	);
}

// Keep the table clean when a player or event is permanently deleted.
add_action(
	'before_delete_post',
	static function ( $post_id ) {
		global $wpdb;
		$type = get_post_type( $post_id );
		if ( 'player' === $type ) {
			$wpdb->delete( onf_entries_table(), array( 'player_id' => $post_id ), array( '%d' ) );
		} elseif ( 'onf_event' === $type ) {
			$wpdb->delete( onf_entries_table(), array( 'event_id' => $post_id ), array( '%d' ) );
		}
	}
);

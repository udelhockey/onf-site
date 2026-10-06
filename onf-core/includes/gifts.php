<?php
/**
 * Gifts and donors. Every gift is tagged with a player, event and/or fund;
 * every total on the site is a sum over those tags.
 */

defined( 'ABSPATH' ) || exit;

function onf_gifts_table() {
	global $wpdb;
	return $wpdb->prefix . 'onf_gifts';
}

function onf_gift_sources() {
	return array(
		'stripe'        => __( 'Stripe', 'onf-core' ),
		'manual'        => __( 'Manual', 'onf-core' ),
		'givewp_import' => __( 'GiveWP import', 'onf-core' ),
	);
}

function onf_gift_statuses() {
	return array(
		'completed' => __( 'Completed', 'onf-core' ),
		'pending'   => __( 'Pending', 'onf-core' ),
		'refunded'  => __( 'Refunded', 'onf-core' ),
	);
}

function onf_gift_methods() {
	return array(
		'card'  => __( 'Card', 'onf-core' ),
		'check' => __( 'Check', 'onf-core' ),
		'cash'  => __( 'Cash', 'onf-core' ),
		'other' => __( 'Other', 'onf-core' ),
	);
}

/**
 * Record a gift and link it to a donor (see onf_resolve_gift_donor()).
 *
 * @param array $data Column => value. `amount` is required; `donor_id` links an existing donor.
 * @return int|WP_Error New gift ID.
 */
function onf_insert_gift( array $data ) {
	global $wpdb;

	$amount = isset( $data['amount'] ) ? round( (float) $data['amount'], 2 ) : 0;
	if ( $amount <= 0 ) {
		return new WP_Error( 'onf_gift_amount', __( 'Amount must be more than zero.', 'onf-core' ) );
	}

	$now = current_time( 'mysql' );
	$row = array(
		'amount'                => $amount,
		'fee_covered'           => round( (float) ( $data['fee_covered'] ?? 0 ), 2 ),
		'type'                  => in_array( $data['type'] ?? '', array( 'donation', 'event_payment' ), true ) ? $data['type'] : 'donation',
		'player_id'             => absint( $data['player_id'] ?? 0 ),
		'event_id'              => absint( $data['event_id'] ?? 0 ),
		'fund_id'               => absint( $data['fund_id'] ?? 0 ),
		'donor_first_name'      => sanitize_text_field( $data['donor_first_name'] ?? '' ),
		'donor_last_name'       => sanitize_text_field( $data['donor_last_name'] ?? '' ),
		'donor_email'           => sanitize_email( $data['donor_email'] ?? '' ),
		'donor_company'         => sanitize_text_field( $data['donor_company'] ?? '' ),
		'display_name'          => sanitize_text_field( $data['display_name'] ?? '' ),
		'anonymous'             => empty( $data['anonymous'] ) ? 0 : 1,
		'message'               => sanitize_textarea_field( $data['message'] ?? '' ),
		'source'                => array_key_exists( $data['source'] ?? '', onf_gift_sources() ) ? $data['source'] : 'manual',
		'method'                => array_key_exists( $data['method'] ?? '', onf_gift_methods() ) ? $data['method'] : '',
		'reference'             => sanitize_text_field( $data['reference'] ?? '' ),
		'stripe_session_id'     => sanitize_text_field( $data['stripe_session_id'] ?? '' ) ?: null, // NULL: unique key ignores it.
		'stripe_payment_intent' => sanitize_text_field( $data['stripe_payment_intent'] ?? '' ),
		'givewp_id'             => absint( $data['givewp_id'] ?? 0 ),
		'receipt_number'        => sanitize_text_field( $data['receipt_number'] ?? '' ),
		'status'                => array_key_exists( $data['status'] ?? '', onf_gift_statuses() ) ? $data['status'] : 'completed',
		'gift_date'             => ! empty( $data['gift_date'] ) ? $data['gift_date'] : $now,
		'created_at'            => $now,
		'updated_at'            => $now,
	);

	$row['donor_id'] = onf_resolve_gift_donor( absint( $data['donor_id'] ?? 0 ), $row, (array) ( $data['donor_details'] ?? array() ) );

	if ( ! $wpdb->insert( onf_gifts_table(), $row ) ) {
		return new WP_Error( 'onf_gift_db', __( 'The gift could not be saved.', 'onf-core' ) );
	}
	$gift_id = (int) $wpdb->insert_id;

	if ( 'completed' === $row['status'] ) {
		onf_assign_receipt_number( $gift_id );
	}
	onf_flush_totals();
	do_action( 'onf_gift_recorded', $gift_id, $row );
	return $gift_id;
}

function onf_set_gift_status( int $gift_id, string $status ) {
	global $wpdb;
	if ( ! array_key_exists( $status, onf_gift_statuses() ) ) {
		return false;
	}
	$updated = $wpdb->update(
		onf_gifts_table(),
		array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		),
		array( 'id' => $gift_id )
	);
	if ( $updated ) {
		if ( 'completed' === $status ) {
			onf_assign_receipt_number( $gift_id );
		}
		onf_flush_totals();
		do_action( 'onf_gift_status_changed', $gift_id, $status );
	}
	return (bool) $updated;
}

/**
 * Give a completed gift the next receipt number (prefix + sequence), once.
 * GiveWP-imported gifts keep their own numbers.
 */
function onf_assign_receipt_number( int $gift_id ) {
	global $wpdb;
	$gift = onf_get_gift( $gift_id );
	if ( ! $gift || '' !== $gift->receipt_number || 'givewp_import' === $gift->source ) {
		return;
	}
	add_option( 'onf_receipt_next', 26704, '', false );
	// Atomic increment, so two gifts at the same moment never share a number.
	$wpdb->query( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = 'onf_receipt_next'" );
	$number = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ) - 1;
	wp_cache_delete( 'onf_receipt_next', 'options' );

	$receipt = onf_setting( 'receipt_prefix' ) . str_pad( (string) $number, (int) onf_setting( 'receipt_padding' ), '0', STR_PAD_LEFT );
	$wpdb->update( onf_gifts_table(), array( 'receipt_number' => $receipt ), array( 'id' => $gift_id ) );
}

/**
 * What a gift was for, in words: "Anthony Petrucci (2026 Herb Mitchell Cup)", "Food Bank of Delaware", …
 */
function onf_gift_for_label( $gift ) {
	$player = $gift->player_id ? get_the_title( $gift->player_id ) : '';
	$event  = $gift->event_id ? get_the_title( $gift->event_id ) : '';
	$fund   = $gift->fund_id ? get_the_title( $gift->fund_id ) : '';
	$main   = $player ? $player : $fund;
	if ( $main && $event ) {
		return "$main ($event)";
	}
	return $main ? $main : ( $event ? $event : __( 'General donation', 'onf-core' ) );
}

/**
 * Change what a gift is for and how it shows. Amount/date/method/reference only for manual gifts
 * (Stripe and imported gifts must keep matching their payment record).
 *
 * @return true|WP_Error
 */
function onf_update_gift( int $gift_id, array $data ) {
	global $wpdb;
	$gift = onf_get_gift( $gift_id );
	if ( ! $gift ) {
		return new WP_Error( 'onf_gift_missing', __( 'Gift not found.', 'onf-core' ) );
	}
	$row = array(
		'player_id'    => 'player' === get_post_type( absint( $data['player_id'] ?? 0 ) ) ? absint( $data['player_id'] ) : 0,
		'event_id'     => 'onf_event' === get_post_type( absint( $data['event_id'] ?? 0 ) ) ? absint( $data['event_id'] ) : 0,
		'fund_id'      => 'onf_fund' === get_post_type( absint( $data['fund_id'] ?? 0 ) ) ? absint( $data['fund_id'] ) : 0,
		'display_name' => sanitize_text_field( $data['display_name'] ?? '' ),
		'anonymous'    => empty( $data['anonymous'] ) ? 0 : 1,
		'message'      => sanitize_textarea_field( $data['message'] ?? '' ),
		'updated_at'   => current_time( 'mysql' ),
	);
	if ( 'manual' === $gift->source ) {
		$amount = round( (float) ( $data['amount'] ?? 0 ), 2 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'onf_gift_amount', __( 'Amount must be more than zero.', 'onf-core' ) );
		}
		$row['amount']    = $amount;
		$row['method']    = array_key_exists( $data['method'] ?? '', onf_gift_methods() ) ? $data['method'] : $gift->method;
		$row['reference'] = sanitize_text_field( $data['reference'] ?? '' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $data['gift_date'] ?? '' ) ) ) {
			$row['gift_date'] = $data['gift_date'] . ' ' . substr( $gift->gift_date, 11, 8 );
		}
	}
	$wpdb->update( onf_gifts_table(), $row, array( 'id' => $gift_id ) );
	onf_flush_totals();
	do_action( 'onf_gift_updated', $gift_id );
	return true;
}

function onf_get_gift( int $gift_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . onf_gifts_table() . ' WHERE id = %d', $gift_id ) );
}

/**
 * Sum of completed donations matching the given tags.
 * Cached; the cache is cleared whenever a gift or entry changes.
 *
 * @param array $tags Any of player_id, event_id, fund_id. Empty = foundation-wide.
 */
function onf_total( array $tags = array() ) {
	global $wpdb;
	$tags  = array_intersect_key( array_map( 'absint', $tags ), array_flip( array( 'player_id', 'event_id', 'fund_id' ) ) );
	$key   = 'onf_total_' . get_option( 'onf_totals_version', 1 ) . '_' . md5( wp_json_encode( $tags ) );
	$total = get_transient( $key );
	if ( false !== $total ) {
		return (float) $total;
	}

	$where = array( "status = 'completed'", "type = 'donation'" );
	foreach ( $tags as $column => $id ) {
		$where[] = $wpdb->prepare( "$column = %d", $id ); // Column names come from the allow-list above.
	}
	$total = (float) $wpdb->get_var( 'SELECT COALESCE(SUM(amount), 0) FROM ' . onf_gifts_table() . ' WHERE ' . implode( ' AND ', $where ) );

	set_transient( $key, $total, DAY_IN_SECONDS );
	return $total;
}

function onf_player_total( int $player_id, int $event_id = 0 ) {
	return onf_total( array_filter( array( 'player_id' => $player_id, 'event_id' => $event_id ) ) );
}

function onf_event_total( int $event_id ) {
	return onf_total( array( 'event_id' => $event_id ) );
}

function onf_fund_total( int $fund_id ) {
	return onf_total( array( 'fund_id' => $fund_id ) );
}

/**
 * Invalidate every cached total by bumping the version in their keys.
 * Old transients expire on their own.
 */
function onf_flush_totals() {
	update_option( 'onf_totals_version', (int) get_option( 'onf_totals_version', 1 ) + 1, false );
}

function onf_money( $amount ) {
	return '$' . number_format( (float) $amount, 2 );
}

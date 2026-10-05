<?php
/**
 * Donors: one row per person or business. Email is the match key when present;
 * check/cash givers may have none. Gifts keep their own copy of the donor's name
 * and email as given at the time, so receipts stay accurate if a donor is edited.
 */

defined( 'ABSPATH' ) || exit;

function onf_donors_table() {
	global $wpdb;
	return $wpdb->prefix . 'onf_donors';
}

/**
 * Editable donor fields => label.
 */
function onf_donor_fields() {
	return array(
		'first_name' => __( 'First name', 'onf-core' ),
		'last_name'  => __( 'Last name', 'onf-core' ),
		'email'      => __( 'Email', 'onf-core' ),
		'company'    => __( 'Company', 'onf-core' ),
		'phone'      => __( 'Phone', 'onf-core' ),
		'address1'   => __( 'Address', 'onf-core' ),
		'address2'   => __( 'Address line 2', 'onf-core' ),
		'city'       => __( 'City', 'onf-core' ),
		'state'      => __( 'State', 'onf-core' ),
		'zip'        => __( 'ZIP', 'onf-core' ),
		'notes'      => __( 'Notes (private)', 'onf-core' ),
	);
}

function onf_sanitize_donor_field( string $field, $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';
	if ( 'email' === $field ) {
		return strtolower( sanitize_email( $value ) );
	}
	return 'notes' === $field ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
}

function onf_get_donor( int $donor_id ) {
	global $wpdb;
	return $donor_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . onf_donors_table() . ' WHERE id = %d', $donor_id ) ) : null;
}

function onf_get_donor_by_email( string $email ) {
	global $wpdb;
	$email = strtolower( sanitize_email( $email ) );
	return $email ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . onf_donors_table() . ' WHERE email = %s', $email ) ) : null;
}

function onf_donor_name( $donor ) {
	$name = trim( $donor->first_name . ' ' . $donor->last_name );
	if ( '' === $name ) {
		$name = $donor->company ? $donor->company : (string) $donor->email;
	}
	return '' !== $name ? $name : sprintf( '#%d', $donor->id );
}

/**
 * Create a donor.
 *
 * @return int|WP_Error Donor ID.
 */
function onf_create_donor( array $data ) {
	global $wpdb;
	$row = array();
	foreach ( array_keys( onf_donor_fields() ) as $field ) {
		$row[ $field ] = onf_sanitize_donor_field( $field, $data[ $field ] ?? '' );
	}
	if ( '' === $row['email'] ) {
		$row['email'] = null; // NULLs don't clash on the unique key.
	} elseif ( onf_get_donor_by_email( $row['email'] ) ) {
		return new WP_Error( 'onf_donor_email', __( 'Another donor already has that email.', 'onf-core' ) );
	}
	if ( '' === $row['first_name'] . $row['last_name'] . $row['company'] . (string) $row['email'] ) {
		return new WP_Error( 'onf_donor_empty', __( 'Enter a name, company or email.', 'onf-core' ) );
	}
	$row['created_at'] = current_time( 'mysql' );
	$row['updated_at'] = $row['created_at'];
	if ( ! $wpdb->insert( onf_donors_table(), $row ) ) {
		return new WP_Error( 'onf_donor_db', __( 'The donor could not be saved.', 'onf-core' ) );
	}
	return (int) $wpdb->insert_id;
}

/**
 * Update a donor's details. Only the fields given are changed.
 *
 * @return true|WP_Error
 */
function onf_update_donor( int $donor_id, array $data ) {
	global $wpdb;
	$row = array();
	foreach ( array_keys( onf_donor_fields() ) as $field ) {
		if ( array_key_exists( $field, $data ) ) {
			$row[ $field ] = onf_sanitize_donor_field( $field, $data[ $field ] );
		}
	}
	if ( array_key_exists( 'email', $row ) ) {
		if ( '' === $row['email'] ) {
			$row['email'] = null;
		} else {
			$other = onf_get_donor_by_email( $row['email'] );
			if ( $other && (int) $other->id !== $donor_id ) {
				/* translators: %s: donor name */
				return new WP_Error( 'onf_donor_email', sprintf( __( 'That email belongs to %s. Merge the two donors instead.', 'onf-core' ), onf_donor_name( $other ) ) );
			}
		}
	}
	$row['updated_at'] = current_time( 'mysql' );
	$wpdb->update( onf_donors_table(), $row, array( 'id' => $donor_id ) );
	return true;
}

/**
 * Fill a donor's blank fields; never overwrites.
 */
function onf_fill_donor_blanks( $donor, array $data ) {
	$update = array();
	foreach ( array_keys( onf_donor_fields() ) as $field ) {
		if ( 'email' !== $field && '' === (string) $donor->$field && ! empty( $data[ $field ] ) ) {
			$update[ $field ] = $data[ $field ];
		}
	}
	if ( $update ) {
		onf_update_donor( (int) $donor->id, $update );
	}
}

/**
 * Find or create the donor for a gift being recorded, and complete the gift's donor copy.
 * 1. A chosen donor_id wins. 2. Else match by email. 3. Else, if the gift has a name, a new donor (no email).
 *
 * @param array $row Gift row (by reference: blank donor fields are filled from the donor).
 * @return int Donor ID, or 0 for a gift with no donor details.
 */
function onf_resolve_gift_donor( int $donor_id, array &$row ) {
	$details = array(
		'first_name' => $row['donor_first_name'],
		'last_name'  => $row['donor_last_name'],
		'email'      => $row['donor_email'],
		'company'    => $row['donor_company'],
	);

	$donor = $donor_id ? onf_get_donor( $donor_id ) : null;
	if ( ! $donor && $row['donor_email'] ) {
		$donor = onf_get_donor_by_email( $row['donor_email'] );
	}

	if ( $donor ) {
		onf_fill_donor_blanks( $donor, $details );
		if ( ! $donor->email && $row['donor_email'] && ! onf_get_donor_by_email( $row['donor_email'] ) ) {
			onf_update_donor( (int) $donor->id, array( 'email' => $row['donor_email'] ) );
		}
		foreach ( array( 'first_name', 'last_name', 'email', 'company' ) as $field ) {
			if ( '' === $row[ 'donor_' . $field ] ) {
				$row[ 'donor_' . $field ] = (string) $donor->$field;
			}
		}
		return (int) $donor->id;
	}

	if ( '' === $row['donor_first_name'] . $row['donor_last_name'] . $row['donor_company'] . $row['donor_email'] ) {
		return 0;
	}
	$new = onf_create_donor( $details );
	return is_wp_error( $new ) ? 0 : $new;
}

/**
 * Move all of one donor's gifts to another, fill the target's blanks, delete the source.
 *
 * @return true|WP_Error
 */
function onf_merge_donors( int $from_id, int $into_id ) {
	global $wpdb;
	$from = onf_get_donor( $from_id );
	$into = onf_get_donor( $into_id );
	if ( ! $from || ! $into || $from_id === $into_id ) {
		return new WP_Error( 'onf_donor_merge', __( 'Choose two different donors to merge.', 'onf-core' ) );
	}

	$wpdb->update( onf_gifts_table(), array( 'donor_id' => $into_id ), array( 'donor_id' => $from_id ) );

	$fill = array();
	foreach ( array_keys( onf_donor_fields() ) as $field ) {
		$fill[ $field ] = (string) $from->$field;
	}
	if ( $from->notes && $into->notes ) {
		onf_update_donor( $into_id, array( 'notes' => $into->notes . "\n" . $from->notes ) );
	}
	$wpdb->delete( onf_donors_table(), array( 'id' => $from_id ) );

	onf_fill_donor_blanks( onf_get_donor( $into_id ), $fill );
	// The merged-away email isn't lost: it stays on the gifts. Keep it in notes too.
	if ( $from->email && $from->email !== $into->email ) {
		$into = onf_get_donor( $into_id );
		if ( ! $into->email ) {
			onf_update_donor( $into_id, array( 'email' => $from->email ) );
		} else {
			/* translators: %s: email address */
			onf_update_donor( $into_id, array( 'notes' => trim( $into->notes . "\n" . sprintf( __( 'Also used: %s', 'onf-core' ), $from->email ) ) ) );
		}
	}

	do_action( 'onf_donors_merged', $from_id, $into_id );
	return true;
}

/**
 * Delete a donor. Only allowed when they have no gifts.
 *
 * @return true|WP_Error
 */
function onf_delete_donor( int $donor_id ) {
	global $wpdb;
	$gifts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . onf_gifts_table() . ' WHERE donor_id = %d', $donor_id ) );
	if ( $gifts ) {
		return new WP_Error( 'onf_donor_has_gifts', __( 'This donor has gifts. Merge them into another donor instead.', 'onf-core' ) );
	}
	$wpdb->delete( onf_donors_table(), array( 'id' => $donor_id ) );
	return true;
}

/**
 * Donors with giving stats. Totals count completed donations only.
 *
 * @param array $args id, search, orderby (name|total|last_gift|gifts|created), order, limit, offset.
 * @return object[]
 */
function onf_query_donors( array $args = array() ) {
	global $wpdb;
	$orderby = array(
		'name'      => 'd.last_name %1$s, d.first_name %1$s, d.company %1$s',
		'total'     => 'total %1$s',
		'last_gift' => 'last_gift %1$s',
		'gifts'     => 'gift_count %1$s',
		'created'   => 'd.created_at %1$s',
	);
	$order = 'ASC' === strtoupper( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
	$by    = sprintf( $orderby[ $args['orderby'] ?? '' ] ?? $orderby['total'], $order );

	$sql = 'SELECT d.*,
			COUNT(g.id) AS gift_count,
			COALESCE(SUM(CASE WHEN g.status = \'completed\' AND g.type = \'donation\' THEN g.amount END), 0) AS total,
			MIN(g.gift_date) AS first_gift,
			MAX(g.gift_date) AS last_gift
		FROM ' . onf_donors_table() . ' d
		LEFT JOIN ' . onf_gifts_table() . ' g ON g.donor_id = d.id
		WHERE ' . onf_donor_search_where( $args['search'] ?? '' ) . ( empty( $args['id'] ) ? '' : $wpdb->prepare( ' AND d.id = %d', $args['id'] ) ) . "
		GROUP BY d.id ORDER BY $by, d.id DESC";
	if ( ! empty( $args['limit'] ) ) {
		$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] ?? 0 );
	}
	return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- parts prepared/allow-listed.
}

function onf_count_donors( string $search = '' ) {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . onf_donors_table() . ' d WHERE ' . onf_donor_search_where( $search ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

function onf_donor_search_where( string $search ) {
	global $wpdb;
	if ( '' === $search ) {
		return '1=1';
	}
	$like = '%' . $wpdb->esc_like( $search ) . '%';
	return $wpdb->prepare(
		"(CONCAT(d.first_name, ' ', d.last_name) LIKE %s OR d.email LIKE %s OR d.company LIKE %s OR d.city LIKE %s)",
		$like,
		$like,
		$like,
		$like
	);
}

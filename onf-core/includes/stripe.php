<?php
/**
 * Stripe: Checkout Sessions (Stripe hosts the payment page; card data never touches this site)
 * and the webhook that records gifts and refunds.
 *
 * Webhook URL: /wp-json/onf/v1/stripe-webhook
 * Events: checkout.session.completed, checkout.session.async_payment_succeeded, charge.refunded
 */

defined( 'ABSPATH' ) || exit;

/**
 * Call the Stripe API.
 *
 * @return array|WP_Error Decoded response.
 */
function onf_stripe_request( $method, $path, array $params = array(), array $headers = array() ) {
	$secret = onf_stripe_secret();
	if ( '' === $secret ) {
		return new WP_Error( 'onf_stripe_key', __( 'Stripe is not set up yet (Gifts → Settings).', 'onf-core' ) );
	}
	$args = array(
		'method'  => $method,
		'timeout' => 30,
		'headers' => array_merge(
			array(
				'Authorization' => 'Bearer ' . $secret,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			$headers
		),
	);
	$url = 'https://api.stripe.com/v1/' . ltrim( $path, '/' );
	if ( 'GET' === $method ) {
		$url = $params ? $url . '?' . http_build_query( $params ) : $url;
	} else {
		$args['body'] = http_build_query( $params );
	}

	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code >= 400 || ! is_array( $body ) ) {
		$message = $body['error']['message'] ?? sprintf( 'Stripe error (HTTP %d)', $code );
		return new WP_Error( 'onf_stripe', $message );
	}
	return $body;
}

function onf_site_host() {
	return (string) wp_parse_url( home_url(), PHP_URL_HOST );
}

/**
 * Create a Checkout Session for a donation.
 *
 * @param array $d amount, fee, player_id, event_id, fund_id, first_name, last_name, email, company,
 *                 anonymous, message, return_url.
 * @return array|WP_Error Session (use ['url']).
 */
function onf_stripe_create_checkout( array $d ) {
	$gift = (object) array(
		'player_id' => $d['player_id'],
		'event_id'  => $d['event_id'],
		'fund_id'   => $d['fund_id'],
	);
	$for  = onf_gift_for_label( $gift );
	$name = sprintf( 'Donation to %s – %s', onf_setting( 'org_name' ), $for );

	$params = array(
		'mode'                => 'payment',
		'submit_type'         => 'donate',
		'customer_email'      => $d['email'],
		'customer_creation'   => 'if_required',
		'success_url'         => add_query_arg( 'onf_session', '{CHECKOUT_SESSION_ID}', $d['return_url'] ),
		'cancel_url'          => add_query_arg( 'onf_cancelled', '1', $d['return_url'] ),
		'line_items'          => array(
			array(
				'quantity'   => 1,
				'price_data' => array(
					'currency'     => 'usd',
					'unit_amount'  => (int) round( $d['amount'] * 100 ),
					'product_data' => array( 'name' => mb_substr( $name, 0, 250 ) ),
				),
			),
		),
		'metadata'            => array(
			'onf_site'      => onf_site_host(),
			'onf_amount'    => number_format( $d['amount'], 2, '.', '' ),
			'onf_fee'       => number_format( $d['fee'], 2, '.', '' ),
			'onf_player'    => (int) $d['player_id'],
			'onf_event'     => (int) $d['event_id'],
			'onf_fund'      => (int) $d['fund_id'],
			'onf_first'     => mb_substr( $d['first_name'], 0, 100 ),
			'onf_last'      => mb_substr( $d['last_name'], 0, 100 ),
			'onf_company'   => mb_substr( $d['company'], 0, 190 ),
			'onf_anonymous' => $d['anonymous'] ? '1' : '0',
			'onf_message'   => mb_substr( $d['message'], 0, 500 ),
		),
		'payment_intent_data' => array(
			'description' => mb_substr( $name, 0, 1000 ),
			'metadata'    => array(
				'onf_site' => onf_site_host(),
				'onf_for'  => mb_substr( $for, 0, 500 ),
			),
		),
	);
	if ( $d['fee'] > 0 ) {
		$params['line_items'][] = array(
			'quantity'   => 1,
			'price_data' => array(
				'currency'     => 'usd',
				'unit_amount'  => (int) round( $d['fee'] * 100 ),
				'product_data' => array( 'name' => __( 'Card processing fee (covered by you – thank you!)', 'onf-core' ) ),
			),
		);
	}

	return onf_stripe_request( 'POST', 'checkout/sessions', $params, array( 'Idempotency-Key' => wp_generate_uuid4() ) );
}

/**
 * Record a paid Checkout Session as a gift (once), then send its emails.
 * Called by the webhook and by the thank-you page, whichever comes first.
 *
 * @param array $s Checkout Session object.
 * @return int|WP_Error Gift ID.
 */
function onf_record_checkout_session( array $s ) {
	global $wpdb;
	$meta = $s['metadata'] ?? array();
	if ( ( $meta['onf_site'] ?? '' ) !== onf_site_host() ) {
		return new WP_Error( 'onf_other_site', 'Session belongs to another site.' );
	}
	if ( 'paid' !== ( $s['payment_status'] ?? '' ) ) {
		return new WP_Error( 'onf_unpaid', 'Session is not paid yet.' );
	}

	$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . onf_gifts_table() . ' WHERE stripe_session_id = %s', $s['id'] ) );
	if ( $existing ) {
		return (int) $existing;
	}

	$total  = ( (int) ( $s['amount_total'] ?? 0 ) ) / 100;
	$amount = (float) ( $meta['onf_amount'] ?? 0 );
	$fee    = (float) ( $meta['onf_fee'] ?? 0 );
	if ( abs( $amount + $fee - $total ) > 0.01 ) {
		// Trust what Stripe actually charged.
		$amount = $total - $fee > 0 ? $total - $fee : $total;
		$fee    = $total - $amount;
	}

	$customer = $s['customer_details'] ?? array();
	$address  = $customer['address'] ?? array();
	$intent   = $s['payment_intent'] ?? '';

	$gift_id = onf_insert_gift(
		array(
			'amount'                => $amount,
			'fee_covered'           => $fee,
			'player_id'             => $meta['onf_player'] ?? 0,
			'event_id'              => $meta['onf_event'] ?? 0,
			'fund_id'               => $meta['onf_fund'] ?? 0,
			'donor_first_name'      => $meta['onf_first'] ?? '',
			'donor_last_name'       => $meta['onf_last'] ?? '',
			'donor_email'           => $customer['email'] ?? '',
			'donor_company'         => $meta['onf_company'] ?? '',
			'anonymous'             => '1' === ( $meta['onf_anonymous'] ?? '0' ),
			'message'               => $meta['onf_message'] ?? '',
			'source'                => 'stripe',
			'method'                => 'card',
			'stripe_session_id'     => $s['id'],
			'stripe_payment_intent' => is_array( $intent ) ? ( $intent['id'] ?? '' ) : (string) $intent,
			'status'                => 'completed',
			'gift_date'             => wp_date( 'Y-m-d H:i:s', (int) ( $s['created'] ?? time() ) ),
			'donor_details'         => array(
				'phone'    => $customer['phone'] ?? '',
				'address1' => $address['line1'] ?? '',
				'address2' => $address['line2'] ?? '',
				'city'     => $address['city'] ?? '',
				'state'    => $address['state'] ?? '',
				'zip'      => $address['postal_code'] ?? '',
			),
		)
	);

	if ( is_wp_error( $gift_id ) ) {
		// Another request recorded it a moment ago (unique key on the session ID).
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . onf_gifts_table() . ' WHERE stripe_session_id = %s', $s['id'] ) );
		return $existing ? (int) $existing : $gift_id;
	}

	onf_send_gift_emails( $gift_id );
	return $gift_id;
}

/**
 * Check a webhook's Stripe-Signature header (HMAC-SHA256, 5-minute tolerance).
 */
function onf_stripe_verify_signature( $payload, $header, $secret ) {
	if ( '' === $secret || '' === $header ) {
		return false;
	}
	$timestamp  = 0;
	$signatures = array();
	foreach ( explode( ',', $header ) as $part ) {
		$pair = explode( '=', trim( $part ), 2 );
		if ( 2 !== count( $pair ) ) {
			continue;
		}
		if ( 't' === $pair[0] ) {
			$timestamp = (int) $pair[1];
		} elseif ( 'v1' === $pair[0] ) {
			$signatures[] = $pair[1];
		}
	}
	if ( ! $timestamp || abs( time() - $timestamp ) > 300 ) {
		return false;
	}
	$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	foreach ( $signatures as $signature ) {
		if ( hash_equals( $expected, $signature ) ) {
			return true;
		}
	}
	return false;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'onf/v1',
			'/stripe-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => 'onf_stripe_webhook',
				'permission_callback' => '__return_true', // Stripe can't log in; the signature check is the authentication.
			)
		);
	}
);

function onf_stripe_webhook( WP_REST_Request $request ) {
	$payload = $request->get_body();
	if ( ! onf_stripe_verify_signature( $payload, (string) $request->get_header( 'stripe_signature' ), onf_stripe_webhook_secret() ) ) {
		return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 400 );
	}
	$event = json_decode( $payload, true );
	if ( ! is_array( $event ) || ! isset( $event['type'], $event['data']['object'] ) ) {
		return new WP_REST_Response( array( 'error' => 'Bad payload' ), 400 );
	}
	// Only act on events from the mode this site is in.
	if ( (bool) ( $event['livemode'] ?? false ) !== ( 'live' === onf_stripe_mode() ) ) {
		return new WP_REST_Response( array( 'ignored' => 'mode' ), 200 );
	}

	$object = $event['data']['object'];
	switch ( $event['type'] ) {
		case 'checkout.session.completed':
		case 'checkout.session.async_payment_succeeded':
			$result = onf_record_checkout_session( $object );
			if ( is_wp_error( $result ) && ! in_array( $result->get_error_code(), array( 'onf_other_site', 'onf_unpaid' ), true ) ) {
				// Tell Stripe to retry later.
				return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
			}
			break;

		case 'charge.refunded':
			if ( ! empty( $object['refunded'] ) && ! empty( $object['payment_intent'] ) ) {
				global $wpdb;
				$gift_id = $wpdb->get_var(
					$wpdb->prepare( 'SELECT id FROM ' . onf_gifts_table() . ' WHERE stripe_payment_intent = %s', $object['payment_intent'] )
				);
				if ( $gift_id ) {
					onf_set_gift_status( (int) $gift_id, 'refunded' );
				}
			}
			break;
	}
	return new WP_REST_Response( array( 'received' => true ), 200 );
}

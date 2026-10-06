<?php
/**
 * Donation settings (Gifts → Settings). Stored in one option, `onf_settings`.
 * Stripe secrets are stored like any WordPress setting and never printed back in full.
 * Defaults match what GiveWP is configured to send today.
 */

defined( 'ABSPATH' ) || exit;

function onf_settings_defaults() {
	return array(
		// Stripe.
		'stripe_mode'                => 'test',
		'stripe_test_secret'         => '',
		'stripe_test_webhook_secret' => '',
		'stripe_live_secret'         => '',
		'stripe_live_webhook_secret' => '',

		// Donate form.
		'amounts'                    => '25, 50, 100, 250',
		'min_amount'                 => 5,
		'fee_percent'                => 2.9,
		'fee_fixed'                  => 0.30,

		// Organization (receipts).
		'org_name'                   => 'Open Net Foundation',
		'org_tagline'                => 'Putting a Check on Childhood Diseases',
		'org_ein'                    => '20-1472003',
		'org_address'                => "PO Box 5355\nWilmington, DE 19808",
		'org_email'                  => 'bob@opennetfoundation.org',

		// Emails.
		'from_name'                  => 'Open Net Foundation',
		'from_email'                 => 'bob@opennetfoundation.org',
		'admin_emails'               => "bob@opennetfoundation.org\nrroux@infosolutionsllc.com",
		'notify_admins'              => 1,
		'notify_players'             => 1,

		'receipt_subject'            => 'Donation Receipt',
		'receipt_body'               => "Dear {first_name},\n\nThank you for your {amount} donation to The Open Net Foundation{for_line}. Your generosity is appreciated!\n\nPlease save this e-mail as your receipt. Receipt number: {receipt_number}. A PDF copy is attached.\n\nThe Open Net Foundation Federal Non-Profit ID is {ein}. No goods or services were provided in exchange for this contribution.\n\nSincerely,\n{org_name}\n{site_url}\n\n{org_address}\n\nQuestions? {org_email}",

		'player_subject'             => 'New donation for {player}',
		'player_body'                => "Hi {player_first_name},\n\nA new donation has been made on your Open Net Foundation page{event_line}.\n\nDonor: {donor_shown}\nAmount: {amount}\n{message_line}\nYou have raised {player_event_total}{event_line} so far. Thank you!\n\n{org_name}",

		'admin_subject'              => 'New Donation - {receipt_number}',
		'admin_body'                 => "A new donation was made on the Open Net Foundation site.\n\nFor: {for}\nDonor: {donor_name} ({donor_email})\nAmount: {amount}{fee_line}\nSource: {source}\n{message_line}\n{org_name}",
	);
}

function onf_settings() {
	static $cache = null;
	if ( null === $cache ) {
		$saved = get_option( 'onf_settings', array() );
		$cache = array_merge( onf_settings_defaults(), is_array( $saved ) ? $saved : array() );
	}
	return $cache;
}

function onf_setting( $key ) {
	return onf_settings()[ $key ] ?? null;
}

/**
 * Live payments are only allowed on the real site, never on staging or a local copy.
 */
function onf_live_allowed() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return in_array( $host, array( 'opennetfoundation.org', 'www.opennetfoundation.org' ), true );
}

function onf_stripe_mode() {
	return ( 'live' === onf_setting( 'stripe_mode' ) && onf_live_allowed() ) ? 'live' : 'test';
}

function onf_stripe_secret() {
	return (string) onf_setting( 'stripe_' . onf_stripe_mode() . '_secret' );
}

function onf_stripe_webhook_secret() {
	return (string) onf_setting( 'stripe_' . onf_stripe_mode() . '_webhook_secret' );
}

/**
 * Suggested amounts as numbers.
 *
 * @return float[]
 */
function onf_suggested_amounts() {
	$amounts = array_map( 'floatval', preg_split( '/[\s,]+/', (string) onf_setting( 'amounts' ), -1, PREG_SPLIT_NO_EMPTY ) );
	return array_values( array_filter( $amounts, static fn( $a ) => $a > 0 ) );
}

/**
 * Card fee to add so ONF receives the full gift: (gift + fixed) / (1 − rate) − gift.
 */
function onf_fee_for( $amount ) {
	$rate  = (float) onf_setting( 'fee_percent' ) / 100;
	$fixed = (float) onf_setting( 'fee_fixed' );
	if ( $amount <= 0 || $rate >= 1 ) {
		return 0.0;
	}
	return round( ( $amount + $fixed ) / ( 1 - $rate ) - $amount, 2 );
}

/**
 * @return string[] Valid admin notification addresses.
 */
function onf_admin_emails() {
	$list = preg_split( '/[\s,;]+/', (string) onf_setting( 'admin_emails' ), -1, PREG_SPLIT_NO_EMPTY );
	return array_values( array_filter( array_map( 'sanitize_email', $list ), 'is_email' ) );
}

<?php
/**
 * Database tables: entries (player in an event), gifts, donors.
 * Donor email is optional (check/cash givers); when present it is unique.
 * Created on activation; upgraded when ONF_CORE_DB_VERSION changes.
 */

defined( 'ABSPATH' ) || exit;

const ONF_CORE_DB_VERSION = '2';

function onf_core_activate() {
	onf_core_install_tables();
	onf_register_post_types();
	flush_rewrite_rules();
}

// Uploading a new zip over the old plugin doesn't re-run activation, so check on every load.
add_action(
	'plugins_loaded',
	static function () {
		if ( get_option( 'onf_core_db_version' ) !== ONF_CORE_DB_VERSION ) {
			onf_core_install_tables();
		}
	}
);

function onf_core_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();

	// dbDelta is picky: one column per line, two spaces after PRIMARY KEY.
	dbDelta(
		"CREATE TABLE {$wpdb->prefix}onf_entries (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			player_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			goal decimal(10,2) DEFAULT NULL,
			sponsor varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY player_event (player_id,event_id),
			KEY event_id (event_id)
		) $charset;"
	);

	dbDelta(
		"CREATE TABLE {$wpdb->prefix}onf_gifts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			amount decimal(10,2) NOT NULL,
			fee_covered decimal(10,2) NOT NULL DEFAULT 0.00,
			type varchar(20) NOT NULL DEFAULT 'donation',
			player_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			fund_id bigint(20) unsigned NOT NULL DEFAULT 0,
			donor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			donor_first_name varchar(100) NOT NULL DEFAULT '',
			donor_last_name varchar(100) NOT NULL DEFAULT '',
			donor_email varchar(191) NOT NULL DEFAULT '',
			donor_company varchar(191) NOT NULL DEFAULT '',
			display_name varchar(191) NOT NULL DEFAULT '',
			anonymous tinyint(1) NOT NULL DEFAULT 0,
			message text NULL,
			source varchar(20) NOT NULL DEFAULT 'manual',
			method varchar(20) NOT NULL DEFAULT '',
			reference varchar(191) NOT NULL DEFAULT '',
			stripe_session_id varchar(191) NOT NULL DEFAULT '',
			stripe_payment_intent varchar(191) NOT NULL DEFAULT '',
			givewp_id bigint(20) unsigned NOT NULL DEFAULT 0,
			receipt_number varchar(32) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'completed',
			gift_date datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY player_id (player_id),
			KEY event_id (event_id),
			KEY fund_id (fund_id),
			KEY donor_id (donor_id),
			KEY status (status),
			KEY givewp_id (givewp_id),
			KEY stripe_payment_intent (stripe_payment_intent)
		) $charset;"
	);

	dbDelta(
		"CREATE TABLE {$wpdb->prefix}onf_donors (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(191) NULL DEFAULT NULL,
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			company varchar(191) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			address1 varchar(191) NOT NULL DEFAULT '',
			address2 varchar(191) NOT NULL DEFAULT '',
			city varchar(100) NOT NULL DEFAULT '',
			state varchar(50) NOT NULL DEFAULT '',
			zip varchar(20) NOT NULL DEFAULT '',
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY last_name (last_name)
		) $charset;"
	);

	update_option( 'onf_core_db_version', ONF_CORE_DB_VERSION );
}

<?php
/**
 * Gifts → Import from GiveWP.
 *
 * 1. Map every GiveWP form that has gifts to a player / event / fund:
 *    - event: the form's GiveWP category → the Event created by the ONF Migration;
 *    - fund: known non-player forms (General, Dolan Fund, Food Bank, rinks, Sponsors…);
 *    - event only: "… Donations" forms for an event;
 *    - player: the form on a player's page, else the same name, a nickname (Rich = Richard),
 *      a known alias (JP = JP Thomas) or a 1–2 letter typo; otherwise a new draft "historical" player.
 * 2. Import completed (and refunded) live-mode donations as gifts, with donors, in batches.
 * 3. Reconcile: totals per player/event/fund must match GiveWP to the cent.
 *
 * Re-runnable (skips donations already imported) and undoable (removes everything it created).
 * Never sends email.
 */

defined( 'ABSPATH' ) || exit;

const ONF_IMPORT_BATCH = 250;

add_action(
	'admin_menu',
	static function () {
		add_submenu_page( 'onf-gifts', __( 'Import from GiveWP', 'onf-core' ), __( 'Import from GiveWP', 'onf-core' ), 'manage_options', 'onf-import', 'onf_render_import_page' );
	},
	13
);

/**
 * Non-player forms: normalized title => fund name. `''` = event-only (general gift to the event).
 */
function onf_import_special_forms() {
	return array(
		'general'                              => 'General Donation',
		'general donation'                     => 'General Donation',
		'open net foundation donation'         => 'General Donation',
		'donation form'                        => 'General Donation',
		'dolan fund'                           => 'Dolan Fund',
		'2023 dolan fund'                      => 'Dolan Fund',
		'food bank of delaware'                => 'Food Bank of Delaware',
		'adopt a family'                       => 'Adopt a Family',
		'tender hearts prosthetic limb grant'  => 'Tender Hearts Prosthetic Limb Grant',
		'pbpg'                                 => 'PBPG',
		'sponsors'                             => 'Sponsors',
		'patriot ice center'                   => 'Patriot Ice Center',
		'skating club of wilmington'           => 'Skating Club of Wilmington',
		'make steve shave'                     => 'Make Steve Shave',
		'2022 face-off for teen mental health' => '',
	);
}

/**
 * Form-title aliases (normalized title => the person's name).
 */
function onf_import_aliases() {
	return array(
		'jp' => 'JP Thomas',
	);
}

/**
 * Nickname => canonical first name.
 */
function onf_import_nicknames() {
	return array(
		'rich'    => 'richard',
		'rick'    => 'richard',
		'mike'    => 'michael',
		'dave'    => 'david',
		'greg'    => 'gregory',
		'matt'    => 'matthew',
		'mathew'  => 'matthew',
		'nick'    => 'nicholas',
		'jeff'    => 'jeffrey',
		'jim'     => 'james',
		'randy'   => 'randall',
		'carmen'  => 'carmine',
		'zack'    => 'zachary',
		'zach'    => 'zachary',
		'wes'     => 'wesley',
		'tom'     => 'thomas',
		'chris'   => 'christopher',
		'bill'    => 'william',
		'will'    => 'william',
		'joe'     => 'joseph',
		'dan'     => 'daniel',
		'steve'   => 'steven',
		'vince'   => 'vincent',
		'tony'    => 'anthony',
		'ben'     => 'benjamin',
		'alex'    => 'alexander',
		'andy'    => 'andrew',
		'pat'     => 'patrick',
		'ed'      => 'edward',
		'jon'     => 'jonathan',
		'sam'     => 'samuel',
	);
}

/**
 * "Patrick Flaherty-pd" → "patrick flaherty"; "Jeffrey  Campbell" → "jeffrey campbell".
 */
function onf_import_name_key( $title ) {
	$key = strtolower( html_entity_decode( (string) $title, ENT_QUOTES ) );
	$key = preg_replace( '/\s*[-–]\s*[a-z0-9]{1,3}$/', '', trim( $key ) ); // -pd, -21
	$key = preg_replace( '/[^a-z0-9\' -]/', ' ', $key );
	return trim( preg_replace( '/\s+/', ' ', $key ) );
}

function onf_import_canonical( $key ) {
	$parts = explode( ' ', $key, 2 );
	$nick  = onf_import_nicknames();
	if ( isset( $nick[ $parts[0] ] ) ) {
		$parts[0] = $nick[ $parts[0] ];
	}
	return implode( ' ', $parts );
}

/**
 * Display name for a new historical player from a form title.
 */
function onf_import_clean_name( $title ) {
	$name = preg_replace( '/\s*[-–]\s*[A-Za-z0-9]{1,3}$/', '', trim( html_entity_decode( (string) $title, ENT_QUOTES ) ) );
	return trim( preg_replace( '/\s+/', ' ', $name ) );
}

function onf_import_ready() {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'give_donationmeta' ) ) === $wpdb->prefix . 'give_donationmeta';
}

/**
 * GiveWP forms that have importable donations, with GiveWP's own totals.
 *
 * @return object[] form_id, title, term_id, gifts, completed (sum), refunded (count)
 */
function onf_import_forms() {
	global $wpdb;
	$dm = $wpdb->prefix . 'give_donationmeta';
	return $wpdb->get_results(
		"SELECT f.form_id, fp.post_title AS title, f.gifts, f.completed, f.refunded,
			(SELECT MIN(tt.term_id) FROM {$wpdb->term_relationships} tr
				JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'give_forms_category'
				WHERE tr.object_id = f.form_id) AS term_id
		FROM (
			SELECT fm.meta_value AS form_id, COUNT(*) AS gifts,
				ROUND(SUM(CASE WHEN p.post_status = 'publish' THEN t.meta_value ELSE 0 END), 2) AS completed,
				SUM(p.post_status = 'refunded') AS refunded
			FROM $dm fm
			JOIN {$wpdb->posts} p ON p.ID = fm.donation_id AND p.post_type = 'give_payment' AND p.post_status IN ('publish', 'refunded')
			JOIN $dm md ON md.donation_id = p.ID AND md.meta_key = '_give_payment_mode' AND md.meta_value = 'live'
			JOIN $dm t ON t.donation_id = p.ID AND t.meta_key = '_give_payment_total'
			WHERE fm.meta_key = '_give_payment_form_id'
			GROUP BY fm.meta_value
		) f
		LEFT JOIN {$wpdb->posts} fp ON fp.ID = f.form_id
		ORDER BY fp.post_title" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
}

/**
 * Decide where every form's gifts go. Pure lookup: creates nothing.
 *
 * @return array form_id => [ player_id, new_player (name), event_id, fund (name), fund_id, rule, title, … ]
 */
function onf_import_build_map() {
	global $wpdb;

	// Players: by linked form, by normalized name, by canonical name.
	$players   = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'player' AND post_status IN ('publish', 'draft', 'private')" );
	$by_form   = array();
	$by_key    = array();
	$by_canon  = array();
	$form_meta = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_onf_givewp_form_ids'" );
	foreach ( $form_meta as $row ) {
		foreach ( (array) maybe_unserialize( $row->meta_value ) as $form_id ) {
			$by_form[ (int) $form_id ][] = (int) $row->post_id;
		}
	}
	foreach ( $players as $p ) {
		$key                      = onf_import_name_key( $p->post_title );
		$by_key[ $key ][]         = (int) $p->ID;
		$by_canon[ onf_import_canonical( $key ) ][] = (int) $p->ID;
	}

	$events = $wpdb->get_results(
		"SELECT pm.meta_value AS term_id, pm.post_id FROM {$wpdb->postmeta} pm
		JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'onf_event' AND p.post_status <> 'trash'
		WHERE pm.meta_key = '_onf_givewp_term_id'",
		OBJECT_K
	);
	$funds  = array();
	foreach ( get_posts( array( 'post_type' => 'onf_fund', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1 ) ) as $fund ) {
		$funds[ strtolower( $fund->post_title ) ] = $fund->ID;
	}

	$special = onf_import_special_forms();
	$aliases = onf_import_aliases();
	$map     = array();

	foreach ( onf_import_forms() as $form ) {
		$key   = onf_import_name_key( $form->title );
		$entry = array(
			'title'      => (string) $form->title,
			'gifts'      => (int) $form->gifts,
			'completed'  => (float) $form->completed,
			'refunded'   => (int) $form->refunded,
			'event_id'   => isset( $events[ $form->term_id ] ) ? (int) $events[ $form->term_id ]->post_id : 0,
			'player_id'  => 0,
			'new_player' => '',
			'fund'       => '',
			'fund_id'    => 0,
			'rule'       => '',
			'note'       => '',
		);

		if ( array_key_exists( $key, $special ) ) {
			$entry['fund']    = $special[ $key ];
			$entry['fund_id'] = $special[ $key ] ? (int) ( $funds[ strtolower( $special[ $key ] ) ] ?? 0 ) : 0;
			$entry['rule']    = $special[ $key ] ? 'fund' : 'event only';
		} elseif ( preg_match( '/\bdonations?$/', $key ) ) {
			$entry['rule'] = 'event only';
		} elseif ( ! empty( $by_form[ (int) $form->form_id ] ) ) {
			$entry['player_id'] = $by_form[ (int) $form->form_id ][0];
			$entry['rule']      = 'form on player page';
		} else {
			$display = isset( $aliases[ $key ] ) ? $aliases[ $key ] : onf_import_clean_name( $form->title );
			$key     = onf_import_name_key( $display );
			$canon   = onf_import_canonical( $key );
			if ( ! empty( $by_key[ $key ] ) ) {
				$entry['player_id'] = $by_key[ $key ][0];
				$entry['rule']      = 'same name';
				if ( count( $by_key[ $key ] ) > 1 ) {
					$entry['note'] = 'several players with this name; picked the first';
				}
			} elseif ( ! empty( $by_canon[ $canon ] ) ) {
				$entry['player_id'] = $by_canon[ $canon ][0];
				$entry['rule']      = 'nickname';
				if ( count( $by_canon[ $canon ] ) > 1 ) {
					$entry['note'] = 'several players match; picked the first';
				}
			} else {
				// A 1–2 letter typo, if exactly one player is that close.
				$close = array();
				foreach ( $by_canon as $other => $ids ) {
					if ( levenshtein( $canon, $other ) <= 2 && strtok( $canon, ' ' ) === strtok( $other, ' ' ) ) {
						$close[ $other ] = $ids[0];
					}
				}
				if ( 1 === count( $close ) ) {
					$entry['player_id'] = reset( $close );
					$entry['rule']      = 'typo';
				} else {
					$entry['new_player'] = $display;
					$entry['rule'] = 'new historical player';
				}
			}
		}
		if ( ! $entry['event_id'] && $form->term_id && 'fund' !== $entry['rule'] ) {
			$entry['note'] = trim( $entry['note'] . ' category has no event' );
		}
		$map[ (int) $form->form_id ] = $entry;
	}
	return $map;
}

/**
 * Create the historical players and funds the map needs, and fill their IDs in.
 */
function onf_import_create_targets( array $map ) {
	$new_players = array();
	foreach ( $map as $form_id => $entry ) {
		if ( $entry['new_player'] ) {
			$canon = onf_import_canonical( onf_import_name_key( $entry['new_player'] ) );
			if ( ! isset( $new_players[ $canon ] ) ) {
				$new_players[ $canon ] = wp_insert_post(
					array(
						'post_type'   => 'player',
						'post_status' => 'draft',
						'post_title'  => $entry['new_player'],
						'meta_input'  => array(
							'_onf_import_created' => 1,
							'_onf_historical'     => 1,
						),
					)
				);
			}
			$map[ $form_id ]['player_id'] = (int) $new_players[ $canon ];
		}
		if ( $entry['fund'] && ! $entry['fund_id'] ) {
			$existing = get_posts(
				array(
					'post_type'   => 'onf_fund',
					'post_status' => array( 'publish', 'draft', 'private' ),
					'title'       => $entry['fund'],
					'numberposts' => 1,
				)
			);
			$map[ $form_id ]['fund_id'] = $existing ? $existing[0]->ID : (int) wp_insert_post(
				array(
					'post_type'   => 'onf_fund',
					'post_status' => 'draft',
					'post_title'  => $entry['fund'],
					'meta_input'  => array( '_onf_import_created' => 1 ),
				)
			);
			// Later forms for the same fund reuse it.
			foreach ( $map as $other_id => $other ) {
				if ( $other['fund'] === $entry['fund'] && ! $other['fund_id'] ) {
					$map[ $other_id ]['fund_id'] = $map[ $form_id ]['fund_id'];
				}
			}
		}
	}
	return $map;
}

function onf_import_method( $gateway ) {
	if ( str_starts_with( (string) $gateway, 'stripe' ) ) {
		return 'card';
	}
	return array(
		'paypal'          => 'paypal',
		'offline'         => 'check',
		'manual_donation' => 'other',
	)[ $gateway ] ?? 'other';
}

/**
 * Import the next batch of donations after $after_id.
 *
 * @return array [ imported, skipped, last_id, done ]
 */
function onf_import_batch( array $map, int $after_id ) {
	global $wpdb;
	$dm  = $wpdb->prefix . 'give_donationmeta';
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			JOIN $dm md ON md.donation_id = p.ID AND md.meta_key = '_give_payment_mode' AND md.meta_value = 'live'
			WHERE p.post_type = 'give_payment' AND p.post_status IN ('publish', 'refunded') AND p.ID > %d
			ORDER BY p.ID LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$after_id,
			ONF_IMPORT_BATCH
		)
	);
	$imported = 0;
	$skipped  = 0;
	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . onf_gifts_table() . ' WHERE givewp_id = %d', $id ) ) ) {
			++$skipped;
			continue;
		}
		$post = get_post( $id );
		$meta = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM $dm WHERE donation_id = %d", $id ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$meta[ $row->meta_key ] = $row->meta_value;
		}
		$target = $map[ (int) ( $meta['_give_payment_form_id'] ?? 0 ) ] ?? null;

		$gift_id = onf_insert_gift(
			array(
				'amount'           => (float) ( $meta['_give_payment_total'] ?? 0 ),
				'player_id'        => $target['player_id'] ?? 0,
				'event_id'         => $target['event_id'] ?? 0,
				'fund_id'          => $target['fund_id'] ?? 0,
				'donor_first_name' => $meta['_give_donor_billing_first_name'] ?? '',
				'donor_last_name'  => $meta['_give_donor_billing_last_name'] ?? '',
				'donor_email'      => $meta['_give_payment_donor_email'] ?? '',
				'donor_company'    => $meta['_give_donation_company'] ?? '',
				'anonymous'        => '1' === ( $meta['_give_anonymous_donation'] ?? '0' ),
				'message'          => $meta['_give_donation_comment'] ?? '',
				'source'           => 'givewp_import',
				'method'           => onf_import_method( $meta['_give_payment_gateway'] ?? '' ),
				'reference'        => $meta['_give_payment_transaction_id'] ?? '',
				'givewp_id'        => $id,
				'receipt_number'   => preg_match( '/^\d{2}-\d+$/', $post->post_title ) ? $post->post_title : '',
				'status'           => 'refunded' === $post->post_status ? 'refunded' : 'completed',
				'gift_date'        => $post->post_date,
				'donor_details'    => array(
					'phone'    => $meta['_give_payment_donor_phone'] ?? '',
					'address1' => $meta['_give_donor_billing_address1'] ?? '',
					'address2' => $meta['_give_donor_billing_address2'] ?? '',
					'city'     => $meta['_give_donor_billing_city'] ?? '',
					'state'    => $meta['_give_donor_billing_state'] ?? '',
					'zip'      => $meta['_give_donor_billing_zip'] ?? '',
				),
			)
		);
		if ( is_wp_error( $gift_id ) ) {
			++$skipped;
			continue;
		}
		++$imported;
		// Put the player on the event's roster if they weren't (marked so Undo can remove it).
		if ( ! empty( $target['player_id'] ) && ! empty( $target['event_id'] ) ) {
			onf_add_entry( (int) $target['player_id'], (int) $target['event_id'], array( 'status' => 'import' ) );
		}
	}
	return array(
		'imported' => $imported,
		'skipped'  => $skipped,
		'last_id'  => $ids ? (int) end( $ids ) : $after_id,
		'done'     => count( $ids ) < ONF_IMPORT_BATCH,
	);
}

/**
 * Compare totals per player/event/fund with GiveWP. Returns only mismatches, plus grand totals.
 */
function onf_import_reconcile( array $map ) {
	global $wpdb;
	$expected = array();
	foreach ( $map as $entry ) {
		$key              = $entry['player_id'] . ':' . $entry['event_id'] . ':' . $entry['fund_id'];
		$expected[ $key ] = round( ( $expected[ $key ] ?? 0 ) + $entry['completed'], 2 );
	}
	$actual = array();
	foreach ( $wpdb->get_results( 'SELECT player_id, event_id, fund_id, ROUND(SUM(amount), 2) AS total FROM ' . onf_gifts_table() . " WHERE source = 'givewp_import' AND status = 'completed' GROUP BY player_id, event_id, fund_id" ) as $row ) {
		$actual[ $row->player_id . ':' . $row->event_id . ':' . $row->fund_id ] = (float) $row->total;
	}
	$mismatches = array();
	foreach ( array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ) ) as $key ) {
		$e = $expected[ $key ] ?? 0;
		$a = $actual[ $key ] ?? 0;
		if ( abs( $e - $a ) > 0.004 ) {
			$mismatches[ $key ] = array( $e, $a );
		}
	}
	$counts = $wpdb->get_row( 'SELECT COUNT(*) AS n, SUM(status = \'completed\') AS completed, SUM(status = \'refunded\') AS refunded, ROUND(SUM(CASE WHEN status = \'completed\' THEN amount END), 2) AS total FROM ' . onf_gifts_table() . " WHERE source = 'givewp_import'" );
	return array(
		'expected_total' => round( array_sum( $expected ), 2 ),
		'expected_gifts' => array_sum( wp_list_pluck( $map, 'gifts' ) ),
		'actual'         => $counts,
		'mismatches'     => $mismatches,
	);
}

/**
 * Remove everything the import created: imported gifts, roster entries it added, players/funds it created,
 * and donors left with no gifts.
 */
function onf_import_undo() {
	global $wpdb;
	$gifts = $wpdb->query( 'DELETE FROM ' . onf_gifts_table() . " WHERE source = 'givewp_import'" );
	$wpdb->query( 'DELETE FROM ' . onf_entries_table() . " WHERE status = 'import'" );
	$created = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_onf_import_created'" );
	foreach ( $created as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
	$donors = $wpdb->query( 'DELETE d FROM ' . onf_donors_table() . ' d LEFT JOIN ' . onf_gifts_table() . ' g ON g.donor_id = d.id WHERE g.id IS NULL' );
	delete_option( 'onf_import_state' );
	onf_flush_totals();
	return array(
		'gifts'  => (int) $gifts,
		'posts'  => count( $created ),
		'donors' => (int) $donors,
	);
}

function onf_render_import_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="wrap"><h1>' . esc_html__( 'Import from GiveWP', 'onf-core' ) . '</h1>';
	if ( ! onf_import_ready() ) {
		echo '<p>' . esc_html__( 'GiveWP data not found on this site.', 'onf-core' ) . '</p></div>';
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification -- each action checks its nonce below.
	$step  = sanitize_key( $_REQUEST['step'] ?? '' );
	$state = get_option( 'onf_import_state', array() );
	// phpcs:enable

	if ( 'undo' === $step && check_admin_referer( 'onf_import' ) ) {
		$undo = onf_import_undo();
		/* translators: 1: gifts, 2: posts, 3: donors */
		echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Import undone: removed %1$d gifts, %2$d created players/funds and %3$d donors.', 'onf-core' ), $undo['gifts'], $undo['posts'], $undo['donors'] ) ) . '</p></div>';
		$state = array();
	}

	if ( 'start' === $step && check_admin_referer( 'onf_import' ) ) {
		$state = array(
			'map'     => onf_import_create_targets( onf_import_build_map() ),
			'last_id' => 0,
			'done'    => false,
			'counts'  => array(
				'imported' => 0,
				'skipped'  => 0,
			),
		);
		update_option( 'onf_import_state', $state, false );
		$step = 'batch';
	}

	if ( 'batch' === $step && ! empty( $state['map'] ) && empty( $state['done'] ) ) {
		check_admin_referer( 'onf_import' );
		$result                        = onf_import_batch( $state['map'], (int) $state['last_id'] );
		$state['last_id']              = $result['last_id'];
		$state['done']                 = $result['done'];
		$state['counts']['imported']  += $result['imported'];
		$state['counts']['skipped']   += $result['skipped'];
		update_option( 'onf_import_state', $state, false );
		if ( ! $state['done'] ) {
			$next = wp_nonce_url( admin_url( 'admin.php?page=onf-import&step=batch' ), 'onf_import' );
			/* translators: %d: gifts imported so far */
			echo '<p>' . esc_html( sprintf( __( 'Importing… %d gifts so far. This page continues by itself.', 'onf-core' ), $state['counts']['imported'] ) ) . '</p>';
			echo '<meta http-equiv="refresh" content="1;url=' . esc_url( $next ) . '">';
			echo '<p><a href="' . esc_url( $next ) . '">' . esc_html__( 'Continue', 'onf-core' ) . '</a></p></div>';
			return;
		}
		onf_flush_totals();
	}

	$map = ! empty( $state['map'] ) ? $state['map'] : onf_import_build_map();
	?>
	<p><?php esc_html_e( 'Copies GiveWP\'s completed and refunded live donations into Gifts, tagged to players, events and funds, with their donors. GiveWP itself is not changed. No emails are sent.', 'onf-core' ); ?></p>
	<form method="post" style="display:inline">
		<?php wp_nonce_field( 'onf_import' ); ?>
		<input type="hidden" name="step" value="start">
		<?php submit_button( empty( $state['done'] ) ? __( 'Run import', 'onf-core' ) : __( 'Import new GiveWP donations', 'onf-core' ), 'primary', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Run the GiveWP import now?', 'onf-core' ) ) . "');" ) ); ?>
	</form>
	<?php if ( ! empty( $state ) ) : ?>
		<form method="post" style="display:inline">
			<?php wp_nonce_field( 'onf_import' ); ?>
			<input type="hidden" name="step" value="undo">
			<?php submit_button( __( 'Undo import', 'onf-core' ), 'delete', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Remove all imported gifts and the players/funds the import created?', 'onf-core' ) ) . "');" ) ); ?>
		</form>
	<?php endif; ?>
	<?php
	if ( ! empty( $state['done'] ) ) {
		onf_render_import_reconcile( onf_import_reconcile( $map ), $state['counts'] );
	} else {
		echo '<h2>' . esc_html__( 'Dry run — nothing has been imported yet', 'onf-core' ) . '</h2>';
	}
	onf_render_import_map( $map );
	echo '</div>';
}

function onf_render_import_reconcile( array $r, array $counts ) {
	$ok = ! $r['mismatches'] && abs( (float) $r['actual']->total - $r['expected_total'] ) < 0.005;
	echo '<h2>' . esc_html( $ok ? __( 'Import complete — totals match GiveWP to the cent', 'onf-core' ) : __( 'Import complete — some totals do NOT match', 'onf-core' ) ) . '</h2>';
	echo '<table class="widefat striped" style="max-width:700px"><tbody>';
	printf( '<tr><th>%s</th><td>%s</td><td>%s</td></tr>', '', esc_html__( 'GiveWP', 'onf-core' ), esc_html__( 'Imported', 'onf-core' ) );
	printf( '<tr><th>%s</th><td>%d</td><td>%d</td></tr>', esc_html__( 'Donations (completed + refunded)', 'onf-core' ), (int) $r['expected_gifts'], (int) $r['actual']->n );
	printf( '<tr><th>%s</th><td>%s</td><td>%s</td></tr>', esc_html__( 'Completed total', 'onf-core' ), esc_html( onf_money( $r['expected_total'] ) ), esc_html( onf_money( $r['actual']->total ) ) );
	/* translators: 1: imported now, 2: skipped */
	printf( '<tr><th>%s</th><td colspan="2">%s</td></tr>', esc_html__( 'This run', 'onf-core' ), esc_html( sprintf( __( '%1$d imported, %2$d already there / skipped', 'onf-core' ), $counts['imported'], $counts['skipped'] ) ) );
	echo '</tbody></table>';
	if ( $r['mismatches'] ) {
		echo '<h3>' . esc_html__( 'Mismatches (player : event : fund)', 'onf-core' ) . '</h3><ul>';
		foreach ( $r['mismatches'] as $key => $pair ) {
			list( $p, $e, $f ) = array_map( 'intval', explode( ':', $key ) );
			$label = implode( ' · ', array_filter( array( $p ? get_the_title( $p ) : '', $e ? get_the_title( $e ) : '', $f ? get_the_title( $f ) : '' ) ) );
			/* translators: 1: target, 2: GiveWP total, 3: imported total */
			printf( '<li>%s</li>', esc_html( sprintf( __( '%1$s: GiveWP %2$s, imported %3$s', 'onf-core' ), $label ? $label : __( '(nothing)', 'onf-core' ), onf_money( $pair[0] ), onf_money( $pair[1] ) ) ) );
		}
		echo '</ul>';
	}
}

function onf_render_import_map( array $map ) {
	$groups = array();
	foreach ( $map as $form_id => $entry ) {
		$groups[ $entry['rule'] ][ $form_id ] = $entry;
	}
	$order = array( 'new historical player', 'typo', 'nickname', 'same name', 'form on player page', 'fund', 'event only' );
	$help  = array(
		'new historical player' => __( 'No player page found — a draft player will be created. Check these names.', 'onf-core' ),
		'typo'                  => __( 'Matched to a player whose name differs by 1–2 letters. Check these.', 'onf-core' ),
		'nickname'              => __( 'Matched by nickname (Rich = Richard). Check these.', 'onf-core' ),
		'same name'             => __( 'Matched by the same name.', 'onf-core' ),
		'form on player page'   => __( 'This form is on the player\'s page.', 'onf-core' ),
		'fund'                  => __( 'Non-player forms → funds (created as drafts if new).', 'onf-core' ),
		'event only'            => __( 'General gifts to an event, no player.', 'onf-core' ),
	);
	echo '<h2>' . esc_html__( 'Where each GiveWP form goes', 'onf-core' ) . '</h2>';
	foreach ( $order as $rule ) {
		if ( empty( $groups[ $rule ] ) ) {
			continue;
		}
		$rows = $groups[ $rule ];
		/* translators: 1: rule, 2: number of forms, 3: gifts, 4: total */
		echo '<h3>' . esc_html( sprintf( __( '%1$s — %2$d forms, %3$d gifts, %4$s', 'onf-core' ), ucfirst( $rule ), count( $rows ), array_sum( wp_list_pluck( $rows, 'gifts' ) ), onf_money( array_sum( wp_list_pluck( $rows, 'completed' ) ) ) ) ) . '</h3>';
		echo '<p class="description">' . esc_html( $help[ $rule ] ) . '</p>';
		$open = in_array( $rule, array( 'new historical player', 'typo', 'nickname', 'fund', 'event only' ), true );
		echo '<details' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Show forms', 'onf-core' ) . '</summary>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'GiveWP form', 'onf-core' ) . '</th><th>' . esc_html__( 'Player', 'onf-core' ) . '</th><th>' . esc_html__( 'Event', 'onf-core' ) . '</th><th>' . esc_html__( 'Fund', 'onf-core' ) . '</th><th>' . esc_html__( 'Gifts', 'onf-core' ) . '</th><th>' . esc_html__( 'Completed', 'onf-core' ) . '</th><th>' . esc_html__( 'Note', 'onf-core' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $form_id => $e ) {
			$player = $e['player_id'] ? get_the_title( $e['player_id'] ) : ( $e['new_player'] ? '+ ' . $e['new_player'] : '—' );
			printf(
				'<tr><td>%s <small>#%d</small></td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
				esc_html( $e['title'] ),
				(int) $form_id,
				esc_html( $player ),
				esc_html( $e['event_id'] ? get_the_title( $e['event_id'] ) : '—' ),
				esc_html( $e['fund'] ? ( $e['fund_id'] ? $e['fund'] : '+ ' . $e['fund'] ) : '—' ),
				(int) $e['gifts'],
				esc_html( onf_money( $e['completed'] ) ),
				esc_html( $e['note'] )
			);
		}
		echo '</tbody></table></details>';
	}

	// Player pages that look like the same person.
	global $wpdb;
	$seen = array();
	foreach ( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'player' AND post_status IN ('publish', 'draft', 'private')" ) as $p ) {
		$seen[ onf_import_canonical( onf_import_name_key( $p->post_title ) ) ][] = $p->post_title . ' (#' . $p->ID . ')';
	}
	$dupes = array_filter( $seen, static fn( $list ) => count( $list ) > 1 );
	if ( $dupes ) {
		echo '<h3>' . esc_html__( 'Player pages that may be the same person', 'onf-core' ) . '</h3><ul>';
		foreach ( $dupes as $list ) {
			echo '<li>' . esc_html( implode( '  ·  ', $list ) ) . '</li>';
		}
		echo '</ul>';
	}
}

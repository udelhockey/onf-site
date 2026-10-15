<?php
/**
 * Registration hook: when the event registration form (Ninja Forms) is submitted, add the registrant to
 * the event. Runs after Ninja Forms has done everything it normally does (its emails, saved submission,
 * Zapier → Google Sheet), and never stops a submission: any problem is reported by email instead.
 *
 * 1. Event: the event whose "Registration link" is the page the form was sent from; else the only event
 *    open for registration; else none (the email says so).
 * 2. Player: same email, else same name (nicknames count: Nick = Nicholas), else a new draft player.
 * 3. Email and phone are updated when they changed (the old ones go into the player's private notes);
 *    position, company/sponsor and favorite NHL team fill blanks only.
 * 4. Entry in the event (company = this event's sponsor). Archived players come back automatically.
 * 5. One email to the registration notification list (Gifts → Settings), logged in Gifts → Email log.
 *
 * Fields are found by type and label, not by ONF's own field keys, so any registration form with
 * first name, last name and email fields works.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'ninja_forms_after_submission', 'onf_registration_after_submission', 20 );

/**
 * Off until a form is chosen in Gifts → Settings → Registration.
 */
function onf_registration_enabled_for( $form_id ) {
	return (int) $form_id > 0 && (int) $form_id === (int) onf_setting( 'reg_form_id' );
}

/**
 * @param array $form_data Ninja Forms submission data (form_id, fields[ id => [ key, type, label, value ] ]).
 */
function onf_registration_after_submission( $form_data ) {
	if ( ! is_array( $form_data ) || ! onf_registration_enabled_for( $form_data['form_id'] ?? 0 ) ) {
		return;
	}
	try {
		$result = onf_register_from_submission( onf_registration_values( (array) ( $form_data['fields'] ?? array() ) ), (string) wp_get_raw_referer() );
	} catch ( Throwable $e ) {
		$result = array(
			'error' => $e->getMessage(),
			'name'  => '',
		);
	}
	onf_registration_notify( $result );
}

/**
 * Pull the values we use out of the submitted fields, by field type first and label second.
 *
 * @return array first, last, email, phone, position, company, team.
 */
function onf_registration_values( array $fields ) {
	$v = array_fill_keys( array( 'first', 'last', 'email', 'phone', 'position', 'company', 'team' ), '' );
	foreach ( $fields as $field ) {
		$type  = (string) ( $field['type'] ?? '' );
		$label = strtolower( (string) ( $field['label'] ?? '' ) );
		$key   = strtolower( (string) ( $field['key'] ?? '' ) );
		$value = $field['value'] ?? '';
		$value = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : trim( (string) $value );
		if ( '' === $value ) {
			continue;
		}
		if ( 'firstname' === $type || str_starts_with( $key, 'first_name' ) ) {
			$v['first'] = $value;
		} elseif ( 'lastname' === $type || str_starts_with( $key, 'last_name' ) ) {
			$v['last'] = $value;
		} elseif ( 'email' === $type ) {
			$v['email'] = $value;
		} elseif ( 'phone' === $type || str_contains( $label, 'phone' ) ) {
			$v['phone'] = $value;
		} elseif ( str_contains( $label, 'position' ) ) {
			$v['position'] = $value;
		} elseif ( preg_match( '/favou?rite.*team/', $label ) ) {
			$v['team'] = $value;
		} elseif ( preg_match( '/company|sponsor/', $label ) ) {
			$v['company'] = $value;
		}
	}
	$v['email'] = sanitize_email( $v['email'] );
	foreach ( array( 'first', 'last', 'phone', 'position', 'company', 'team' ) as $k ) {
		$v[ $k ] = sanitize_text_field( $v[ $k ] );
	}
	return $v;
}

/**
 * "Forward", "F", "Defense", "D-man", "Goalie", "G" → the profile's position values.
 */
function onf_registration_position( $value ) {
	$value = strtolower( trim( (string) $value ) );
	if ( '' === $value ) {
		return '';
	}
	if ( preg_match( '/^(g|goal)/', $value ) ) {
		return 'goaltender';
	}
	if ( preg_match( '/^(d|def)/', $value ) ) {
		return 'defenseman';
	}
	if ( preg_match( '/^(f|fwd|for|wing|cent|lw|rw|c\b)/', $value ) ) {
		return 'forward';
	}
	return '';
}

/**
 * The event a registration is for: the event whose Registration link is the page the form was sent
 * from (compared by path, so staging and live both work); else the only event open for registration.
 *
 * @return array [ event_id, how ] how = 'page' | 'only-open' | ''.
 */
function onf_registration_event( string $referer ) {
	$path = static fn( $url ) => untrailingslashit( strtolower( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) ) );
	$from = $referer ? $path( $referer ) : '';
	$all  = get_posts(
		array(
			'post_type'      => 'onf_event',
			'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'registration_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		)
	);
	if ( '' !== $from ) {
		$matches = array();
		foreach ( $all as $event_id ) {
			if ( $path( get_post_meta( $event_id, 'registration_url', true ) ) === $from ) {
				$matches[] = (int) $event_id;
			}
		}
		// Several events can share one page over the years: prefer the one open for registration, then the newest.
		usort(
			$matches,
			static fn( $a, $b ) => ( 'registration' === get_post_meta( $b, 'status', true ) ) <=> ( 'registration' === get_post_meta( $a, 'status', true ) )
				?: onf_event_year( $b ) <=> onf_event_year( $a )
		);
		if ( $matches ) {
			return array( $matches[0], 'page' );
		}
	}
	$open = get_posts(
		array(
			'post_type'      => 'onf_event',
			'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
			'posts_per_page' => 2,
			'fields'         => 'ids',
			'meta_key'       => 'status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => 'registration', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	return 1 === count( $open ) ? array( (int) $open[0], 'only-open' ) : array( 0, '' );
}

/**
 * Find the player: same email first, then same name (nicknames count).
 *
 * @return array [ player_id, how ] how = 'email' | 'name' | ''.
 */
function onf_registration_find_player( string $email, string $name ) {
	global $wpdb;
	if ( '' !== $email ) {
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'email'
				WHERE p.post_type = 'player' AND p.post_status NOT IN ('trash', 'auto-draft') AND LOWER(m.meta_value) = %s
				ORDER BY p.post_status = 'publish' DESC, p.ID ASC LIMIT 1",
				strtolower( $email )
			)
		);
		if ( $id ) {
			return array( $id, 'email' );
		}
	}
	$key = onf_registration_name_key( $name );
	if ( '' === $key ) {
		return array( 0, '' );
	}
	$rows = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'player' AND post_status NOT IN ('trash', 'auto-draft') ORDER BY post_status = 'publish' DESC, ID ASC" );
	foreach ( $rows as $row ) {
		if ( onf_registration_name_key( $row->post_title ) === $key ) {
			return array( (int) $row->ID, 'name' );
		}
	}
	return array( 0, '' );
}

/**
 * Comparable name: lower case, plain letters, nicknames → full names (the GiveWP import's list).
 */
function onf_registration_name_key( string $name ) {
	$key = strtolower( remove_accents( html_entity_decode( $name, ENT_QUOTES ) ) );
	$key = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z0-9\' -]/', ' ', $key ) ) );
	if ( '' === $key ) {
		return '';
	}
	if ( function_exists( 'onf_import_canonical' ) ) {
		$key = onf_import_canonical( $key );
	}
	return $key;
}

/**
 * Do the registration. Separate from the Ninja Forms hook so it can be tested and re-run.
 *
 * @param array $v Values from onf_registration_values().
 * @return array What happened, for the notification email.
 */
function onf_register_from_submission( array $v, string $referer = '' ) {
	$name   = trim( $v['first'] . ' ' . $v['last'] );
	$result = array(
		'name'    => $name,
		'values'  => $v,
		'notes'   => array(),
		'error'   => '',
		'player'  => 0,
		'event'   => 0,
		'created' => false,
		'matched' => '',
		'entry'   => '',
	);
	if ( '' === $name ) {
		$result['error'] = __( 'The form had no name, so no player was added.', 'onf-core' );
		return $result;
	}

	list( $event, $how_event ) = onf_registration_event( $referer );
	list( $player, $how )      = onf_registration_find_player( $v['email'], $name );
	$result['event']           = $event;
	$result['event_how']       = $how_event;
	$result['matched']         = $how;
	$today                     = wp_date( 'Y-m-d' );

	if ( ! $player ) {
		$player = wp_insert_post(
			array(
				'post_type'   => 'player',
				'post_title'  => $name,
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $player ) ) {
			$result['error'] = $player->get_error_message();
			return $result;
		}
		update_post_meta( $player, '_onf_from_registration', $today );
		/* translators: %s: date */
		update_post_meta( $player, 'notes', sprintf( __( 'Created from a registration on %s.', 'onf-core' ), $today ) );
		$result['created'] = true;
	}
	$result['player'] = (int) $player;

	// Email and phone: keep them current; the old ones go into the private notes.
	foreach ( array( 'email' => __( 'email', 'onf-core' ), 'phone' => __( 'phone', 'onf-core' ) ) as $key => $label ) {
		$new = $v[ $key ];
		$old = trim( (string) get_post_meta( $player, $key, true ) );
		if ( '' === $new || strtolower( $new ) === strtolower( $old ) ) {
			continue;
		}
		update_post_meta( $player, $key, $new );
		if ( '' !== $old ) {
			$notes = trim( (string) get_post_meta( $player, 'notes', true ) );
			/* translators: 1: email/phone, 2: old value, 3: date */
			$line = sprintf( __( 'Old %1$s: %2$s (replaced by registration on %3$s)', 'onf-core' ), $label, $old, $today );
			update_post_meta( $player, 'notes', trim( $notes . "\n" . $line ) );
			/* translators: 1: email/phone, 2: old value, 3: new value */
			$result['notes'][] = sprintf( __( 'Updated %1$s: %2$s → %3$s', 'onf-core' ), $label, $old, $new );
		}
	}
	// The rest fill blanks only.
	$fill = array(
		'position'          => onf_registration_position( $v['position'] ),
		'sponsor'           => $v['company'],
		'favorite_nhl_team' => $v['team'],
	);
	foreach ( $fill as $key => $value ) {
		if ( '' !== $value && '' === trim( (string) get_post_meta( $player, $key, true ) ) ) {
			update_post_meta( $player, $key, $value );
		}
	}

	if ( $event ) {
		$added           = onf_add_entry( $player, $event, array( 'sponsor' => $v['company'] ) );
		$result['entry'] = $added ? 'added' : 'already';
		if ( ! $added && '' !== $v['company'] ) {
			// Already on the roster (added by hand first): give the entry its sponsor if it has none.
			foreach ( onf_get_player_entries( $player ) as $entry ) {
				if ( (int) $entry->event_id === $event && '' === trim( (string) $entry->sponsor ) ) {
					onf_update_entry( $player, $event, array( 'sponsor' => $v['company'] ) );
				}
			}
		}
	}
	clean_post_cache( $player );
	do_action( 'onf_registration_processed', $result );
	return $result;
}

/**
 * Email the registration notification list about one registration (logged in Gifts → Email log).
 */
function onf_registration_notify( array $r ) {
	$to = onf_registration_emails();
	if ( ! $to ) {
		return;
	}
	$lines = array();
	$name  = '' !== ( $r['name'] ?? '' ) ? $r['name'] : __( '(no name)', 'onf-core' );
	if ( ! empty( $r['error'] ) && empty( $r['player'] ) ) {
		/* translators: 1: name, 2: error */
		$subject = sprintf( __( 'Registration needs a look: %s', 'onf-core' ), $name );
		$lines[] = sprintf( __( 'A registration from %1$s could not be added: %2$s', 'onf-core' ), esc_html( $name ), esc_html( $r['error'] ) );
		$lines[] = esc_html__( 'The form submission itself was saved by Ninja Forms as usual.', 'onf-core' );
	} else {
		$event   = (int) $r['event'];
		$subject = $event
			/* translators: 1: name, 2: event */
			? sprintf( __( 'Registration: %1$s — %2$s', 'onf-core' ), $name, get_the_title( $event ) )
			/* translators: %s: name */
			: sprintf( __( 'Registration (pick the event): %s', 'onf-core' ), $name );

		if ( $event ) {
			/* translators: 1: name, 2: event */
			$lines[] = sprintf( esc_html__( '%1$s registered for the %2$s.', 'onf-core' ), '<strong>' . esc_html( $name ) . '</strong>', esc_html( get_the_title( $event ) ) );
			if ( 'already' === $r['entry'] ) {
				$lines[] = esc_html__( 'They were already on this event’s roster (added by hand, or registered twice).', 'onf-core' );
			}
			if ( 'only-open' === ( $r['event_how'] ?? '' ) ) {
				$lines[] = esc_html__( 'Event chosen because it is the only one open for registration (the form’s page didn’t match any event’s Registration link).', 'onf-core' );
			}
		} else {
			/* translators: %s: name */
			$lines[] = sprintf( esc_html__( '%s registered, but the form’s page didn’t match any event’s Registration link and more than one event (or none) is open for registration — add them to the right event in Players.', 'onf-core' ), '<strong>' . esc_html( $name ) . '</strong>' );
		}

		if ( $r['created'] ) {
			$lines[] = esc_html__( 'New player: a draft page was created with their details — review and publish it.', 'onf-core' );
		} elseif ( 'name' === $r['matched'] ) {
			$lines[] = esc_html__( 'Matched an existing player by name (not email) — check it’s the same person.', 'onf-core' );
		} else {
			$lines[] = esc_html__( 'Matched an existing player by email.', 'onf-core' );
		}
		foreach ( $r['notes'] as $note ) {
			$lines[] = esc_html( $note );
		}
		$v       = $r['values'];
		$details = array_filter(
			array(
				__( 'Email', 'onf-core' )             => $v['email'],
				__( 'Phone', 'onf-core' )             => $v['phone'],
				__( 'Position', 'onf-core' )          => $v['position'],
				__( 'Company', 'onf-core' )           => $v['company'],
				__( 'Favorite NHL team', 'onf-core' ) => $v['team'],
			)
		);
		if ( $details ) {
			$lines[] = implode( "\n", array_map( static fn( $k, $val ) => esc_html( $k . ': ' . $val ), array_keys( $details ), $details ) );
		}
		$links = array( '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $r['player'] . '&action=edit' ) ) . '">' . esc_html__( 'Edit player', 'onf-core' ) . '</a>' );
		if ( $r['created'] ) {
			$links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=player&onf_review=1' ) ) . '">' . esc_html__( 'Players to review', 'onf-core' ) . '</a>';
		}
		if ( $event ) {
			$links[] = '<a href="' . esc_url( admin_url( 'post.php?post=' . $event . '&action=edit' ) ) . '">' . esc_html__( 'Event', 'onf-core' ) . '</a>';
		}
		$lines[] = implode( ' · ', $links );
		if ( ! empty( $r['error'] ) ) {
			$lines[] = esc_html( $r['error'] );
		}
	}
	onf_send_email( $to, $subject, onf_email_html( implode( "\n\n", $lines ) ), 'registration' );
}

/**
 * @return string[] Registration notification addresses (Gifts → Settings).
 */
function onf_registration_emails() {
	$list = preg_split( '/[\s,;]+/', (string) onf_setting( 'reg_notify' ), -1, PREG_SPLIT_NO_EMPTY );
	return array_values( array_filter( array_map( 'sanitize_email', $list ), 'is_email' ) );
}

/**
 * Ninja Forms forms for the settings dropdown: id => title. Empty when Ninja Forms isn't installed.
 */
function onf_ninja_forms_list() {
	global $wpdb;
	$table = $wpdb->prefix . 'nf3_forms';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return array();
	}
	return wp_list_pluck( $wpdb->get_results( "SELECT id, title FROM $table ORDER BY title" ), 'title', 'id' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

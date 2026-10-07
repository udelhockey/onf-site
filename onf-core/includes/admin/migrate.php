<?php
/**
 * Tools → ONF Migration: moves the old setup onto onf-core.
 *
 * - GiveWP form categories → Event posts (clean names), as drafts.
 * - Player ↔ category links → entries.
 * - Email, phone, favorite NHL team, sponsor ← latest Event Registration (Ninja Forms form 4)
 *   submission with the same name. Only fills blanks; no match = left blank.
 * - Email still blank ← the player's address from their GiveWP form's "new donation" notification
 *   recipients (not the admins), so player notifications keep reaching the same person.
 * - GiveWP form IDs in each player page → `_onf_givewp_form_ids` (used by the GiveWP import later).
 * - Turns off the old ACF "Player" field group (onf-core shows those fields now).
 *
 * Safe to run more than once: it only creates what's missing and never overwrites.
 * Dry run and Run share one code path, so the dry-run report is exactly what Run will do.
 */

defined( 'ABSPATH' ) || exit;

const ONF_MIGRATION_REG_FORM  = 4;
const ONF_MIGRATION_ACF_GROUP = 'group_604fdf9b00e30';

/**
 * GiveWP category slug → clean event name. `null` = not an event (left for the GiveWP import to map to a fund).
 * Categories not listed here keep their own name.
 */
function onf_migration_event_names() {
	return array(
		'2018player'                  => '2018 Face-off for Teen Mental Health',
		'sponsors'                    => null,
		'2019chowder'                 => '2019 Chowder Cup',
		'2019adult'                   => '2019 Face-off for Juvenile Arthritis',
		'2020chowder'                 => '2020 Chowder Cup',
		'save-our-rinks'              => '2020 Save Our Rinks',
		'2021chowdercup'              => '2021 Chowder Cup',
		'2021predraft'                => '2021 Pre-Draft Showcase',
		'2022adult'                   => '2022 Face-off for Teen Mental Health',
		'2022predraft-co'             => '2022 Pre-Draft Showcase – College Open',
		'2022predraft-jra'            => '2022 Pre-Draft Showcase – Junior A',
		'2022chowder'                 => '2022 Chowder Cup',
		'infosolutionholiday'         => '2022 Info Solutions Team Holiday Giving Fund',
		'2023-herb-mitchell-cup'      => '2023 Herb Mitchell Cup',
		'2024-herb-mitchell-cup'      => '2024 Herb Mitchell Cup',
		'2024-duhadaway-cup'          => '2024 DuHadaway Cup',
		'2024-info-solutions-holiday' => '2024 Info Solutions Team Holiday Giving Fund',
		'2025-herb-mitchell-cup'      => '2025 Herb Mitchell Cup',
		'2025-duhadaway-cup'          => '2025 DuHadaway Cup',
		'2025-info-solutions-holiday' => '2025 Info Solutions Team Holiday Giving Fund',
		'2026-rouxster-shootout'      => '2026 Rouxster Shootout',
		'2026-herb-mitchell-cup'      => '2026 Herb Mitchell Cup',
	);
}

add_action(
	'admin_menu',
	static function () {
		add_management_page( __( 'ONF Migration', 'onf-core' ), __( 'ONF Migration', 'onf-core' ), 'manage_options', 'onf-migration', 'onf_render_migration_page' );
	}
);

function onf_render_migration_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$report = null;
	$mode   = '';
	if ( isset( $_POST['onf_migration_mode'] ) && check_admin_referer( 'onf_migration' ) ) {
		$mode   = 'run' === $_POST['onf_migration_mode'] ? 'run' : 'dry';
		$report = onf_migrate( 'run' === $mode );
		if ( 'run' === $mode ) {
			update_option( 'onf_migration_last_run', current_time( 'mysql' ), false );
		}
	}
	$last = get_option( 'onf_migration_last_run' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'ONF Migration', 'onf-core' ); ?></h1>
		<p><?php esc_html_e( 'Creates events from the GiveWP categories, adds players to them, and fills blank player contact fields from registrations. Only adds what is missing; never overwrites. Start with a dry run.', 'onf-core' ); ?></p>
		<?php if ( $last ) : ?>
			<?php /* translators: %s: date and time */ ?>
			<p><em><?php echo esc_html( sprintf( __( 'Last run: %s', 'onf-core' ), $last ) ); ?></em></p>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'onf_migration' ); ?>
			<button class="button" name="onf_migration_mode" value="dry"><?php esc_html_e( 'Dry run (changes nothing)', 'onf-core' ); ?></button>
			<button class="button button-primary" name="onf_migration_mode" value="run" onclick="return confirm('<?php echo esc_js( __( 'Run the migration now?', 'onf-core' ) ); ?>');"><?php esc_html_e( 'Run migration', 'onf-core' ); ?></button>
		</form>
		<?php
		if ( $report ) {
			onf_render_migration_report( $report, 'run' === $mode );
		}
		onf_render_old_slug_cleanup();
		?>
	</div>
	<?php
}

/**
 * Do (or preview) the migration.
 *
 * @param bool $commit False = dry run.
 * @return array Report.
 */
function onf_migrate( bool $commit ) {
	global $wpdb;
	$report = array(
		'events'  => array(),
		'entries' => array(
			'new'      => 0,
			'existing' => 0,
			'skipped'  => 0,
		),
		'fields'  => array(),
		'matched' => 0,
		'givewp'  => 0,
		'forms'   => 0,
		'acf'     => '',
	);

	// 1. Events from GiveWP categories.
	$terms = $wpdb->get_results(
		"SELECT t.term_id, t.name, t.slug, MIN(f.post_date) AS first_date
		FROM {$wpdb->terms} t
		JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'give_forms_category'
		LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
		LEFT JOIN {$wpdb->posts} f ON f.ID = tr.object_id AND f.post_type = 'give_forms'
		GROUP BY t.term_id ORDER BY first_date"
	);
	$existing = $wpdb->get_results(
		"SELECT pm.meta_value AS term_id, pm.post_id FROM {$wpdb->postmeta} pm
		JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'onf_event' AND p.post_status <> 'trash'
		WHERE pm.meta_key = '_onf_givewp_term_id'",
		OBJECT_K
	);
	$names     = onf_migration_event_names();
	$event_for = array(); // term_id => event post ID (0 in a dry run for events not created yet).

	foreach ( $terms as $term ) {
		$name = array_key_exists( $term->slug, $names ) ? $names[ $term->slug ] : $term->name;
		$row  = array(
			'category' => $term->name,
			'event'    => $name,
		);
		if ( null === $name ) {
			$row['action'] = __( 'skipped (not an event)', 'onf-core' );
		} elseif ( isset( $existing[ $term->term_id ] ) ) {
			$row['action']                = __( 'already exists', 'onf-core' );
			$event_for[ $term->term_id ] = (int) $existing[ $term->term_id ]->post_id;
		} else {
			$row['action']                = __( 'create (draft, closed)', 'onf-core' );
			$event_for[ $term->term_id ] = 0;
			if ( $commit ) {
				$event_id = wp_insert_post(
					array(
						'post_type'   => 'onf_event',
						'post_status' => 'draft',
						'post_title'  => $name,
						'post_date'   => $term->first_date ? $term->first_date : current_time( 'mysql' ),
						'meta_input'  => array(
							'status'              => 'closed',
							'_onf_givewp_term_id' => (int) $term->term_id,
						),
					),
					true
				);
				if ( is_wp_error( $event_id ) ) {
					$row['action'] = $event_id->get_error_message();
					unset( $event_for[ $term->term_id ] );
				} else {
					$event_for[ $term->term_id ] = $event_id;
				}
			}
		}
		$report['events'][] = $row;
	}

	// 2. Entries from player ↔ category links.
	$links = $wpdb->get_results(
		"SELECT tr.object_id AS player_id, tt.term_id
		FROM {$wpdb->term_relationships} tr
		JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'give_forms_category'
		JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'player' AND p.post_status IN ('publish', 'draft', 'private')"
	);
	foreach ( $links as $link ) {
		if ( ! isset( $event_for[ $link->term_id ] ) ) {
			++$report['entries']['skipped'];
			continue;
		}
		$event_id = $event_for[ $link->term_id ];
		$exists   = $event_id && $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . onf_entries_table() . ' WHERE player_id = %d AND event_id = %d', $link->player_id, $event_id )
		);
		if ( $exists ) {
			++$report['entries']['existing'];
			continue;
		}
		++$report['entries']['new'];
		if ( $commit && $event_id ) {
			onf_add_entry( (int) $link->player_id, $event_id );
		}
	}

	// 3. Contact fields from registrations, and 4. GiveWP form IDs.
	$registrations = onf_migration_registrations();
	$players       = $wpdb->get_results(
		"SELECT ID, post_title, post_content FROM {$wpdb->posts}
		WHERE post_type = 'player' AND post_status IN ('publish', 'draft', 'private')"
	);
	foreach ( array_keys( onf_migration_registration_fields() ) as $meta_key ) {
		$report['fields'][ $meta_key ] = 0;
	}

	foreach ( $players as $player ) {
		$reg = $registrations[ onf_migration_name_key( $player->post_title ) ] ?? null;
		if ( $reg ) {
			++$report['matched'];
			foreach ( $reg as $meta_key => $value ) {
				if ( '' !== (string) get_post_meta( $player->ID, $meta_key, true ) ) {
					continue;
				}
				++$report['fields'][ $meta_key ];
				if ( $commit ) {
					update_post_meta( $player->ID, $meta_key, $value );
				}
			}
		}

		preg_match_all( '/\[give_form\b[^\]]*\bid=["\']?(\d+)/', $player->post_content, $m );
		$form_ids = array_values( array_unique( array_map( 'intval', $m[1] ) ) );

		$will_have_email = '' !== (string) get_post_meta( $player->ID, 'email', true ) || ! empty( $reg['email'] );
		if ( ! $will_have_email && $form_ids ) {
			$email = onf_migration_givewp_player_email( $form_ids );
			if ( $email ) {
				++$report['givewp'];
				if ( $commit ) {
					update_post_meta( $player->ID, 'email', $email );
				}
			}
		}

		if ( ! metadata_exists( 'post', $player->ID, '_onf_givewp_form_ids' ) && $form_ids ) {
			++$report['forms'];
			if ( $commit ) {
				update_post_meta( $player->ID, '_onf_givewp_form_ids', $form_ids );
			}
		}
	}

	// 5. Old ACF field group.
	if ( function_exists( 'acf_get_field_group' ) && ( $group = acf_get_field_group( ONF_MIGRATION_ACF_GROUP ) ) ) {
		if ( empty( $group['active'] ) ) {
			$report['acf'] = __( 'already off', 'onf-core' );
		} else {
			$report['acf'] = __( 'turn off', 'onf-core' );
			if ( $commit ) {
				$group['active'] = false;
				acf_update_field_group( $group );
			}
		}
	} else {
		$report['acf'] = __( 'not found (nothing to do)', 'onf-core' );
	}

	if ( $commit ) {
		onf_flush_totals();
	}
	return $report;
}

/**
 * The player's own address from their newest GiveWP form's "new donation" recipients,
 * skipping ONF's admin addresses.
 */
function onf_migration_givewp_player_email( array $form_ids ) {
	global $wpdb;
	$table = $wpdb->prefix . 'give_formmeta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return '';
	}
	rsort( $form_ids );
	$skip = array_map( 'strtolower', array_merge( onf_admin_emails(), array( onf_setting( 'org_email' ), 'stroik@udelhockey.com', get_option( 'admin_email' ) ) ) );
	foreach ( $form_ids as $form_id ) {
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM $table WHERE form_id = %d AND meta_key = '_give_new-donation_recipient'", $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) maybe_unserialize( $value ) as $recipient ) {
			$email = strtolower( sanitize_email( is_array( $recipient ) ? ( $recipient['email'] ?? '' ) : (string) $recipient ) );
			if ( is_email( $email ) && ! in_array( $email, $skip, true ) ) {
				return $email;
			}
		}
	}
	return '';
}

/**
 * Player meta key => Ninja Forms field key prefix on the Event Registration form.
 */
function onf_migration_registration_fields() {
	return array(
		'email'             => 'email_',
		'phone'             => 'textbox', // The phone field's key is literally "textbox".
		'favorite_nhl_team' => 'favorite_nhl_team_',
		'sponsor'           => 'company_',
	);
}

function onf_migration_name_key( string $name ) {
	return strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ) );
}

/**
 * Latest non-empty value per field for each registrant, keyed by normalized "first last".
 *
 * @return array name key => [ meta_key => value ]
 */
function onf_migration_registrations() {
	global $wpdb;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'nf3_fields' ) ) !== $wpdb->prefix . 'nf3_fields' ) {
		return array(); // Ninja Forms not installed.
	}
	$fields = $wpdb->get_results(
		$wpdb->prepare( "SELECT id, `key` FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d", ONF_MIGRATION_REG_FORM )
	);
	if ( ! $fields ) {
		return array();
	}

	$want = onf_migration_registration_fields();
	$map  = array(); // "_field_123" => meta key, plus first/last name.
	foreach ( $fields as $field ) {
		if ( str_starts_with( $field->key, 'first_name' ) ) {
			$map[ '_field_' . $field->id ] = 'first';
		} elseif ( str_starts_with( $field->key, 'last_name' ) ) {
			$map[ '_field_' . $field->id ] = 'last';
		} else {
			foreach ( $want as $meta_key => $prefix ) {
				if ( 'textbox' === $prefix ? 'textbox' === $field->key : str_starts_with( $field->key, $prefix ) ) {
					$map[ '_field_' . $field->id ] = $meta_key;
				}
			}
		}
	}

	$placeholders = implode( ',', array_fill( 0, count( $map ), '%s' ) );
	$rows         = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT s.ID, pm.meta_key, pm.meta_value FROM {$wpdb->posts} s
			JOIN {$wpdb->postmeta} f ON f.post_id = s.ID AND f.meta_key = '_form_id' AND f.meta_value = %d
			JOIN {$wpdb->postmeta} pm ON pm.post_id = s.ID AND pm.meta_key IN ($placeholders)
			WHERE s.post_type = 'nf_sub' AND s.post_status = 'publish'
			ORDER BY s.post_date ASC, s.ID ASC",
			array_merge( array( ONF_MIGRATION_REG_FORM ), array_keys( $map ) )
		)
	);

	$subs = array();
	foreach ( $rows as $row ) {
		$subs[ $row->ID ][ $map[ $row->meta_key ] ] = trim( (string) $row->meta_value );
	}

	$skip = array( '', 'n/a', 'na', 'none', 'no', '-', '.', 'x' );
	$out  = array();
	foreach ( $subs as $sub ) { // Oldest first, so newer submissions win.
		$key = onf_migration_name_key( ( $sub['first'] ?? '' ) . ' ' . ( $sub['last'] ?? '' ) );
		if ( '' === $key ) {
			continue;
		}
		foreach ( array_keys( $want ) as $meta_key ) {
			$value = $sub[ $meta_key ] ?? '';
			if ( in_array( strtolower( $value ), $skip, true ) ) {
				continue;
			}
			$out[ $key ][ $meta_key ] = 'email' === $meta_key ? sanitize_email( $value ) : sanitize_text_field( $value );
		}
	}
	return array_map( 'array_filter', $out );
}

function onf_render_migration_report( array $report, bool $committed ) {
	$labels = array(
		'email'             => __( 'Email', 'onf-core' ),
		'phone'             => __( 'Phone', 'onf-core' ),
		'favorite_nhl_team' => __( 'Favorite NHL team', 'onf-core' ),
		'sponsor'           => __( 'Sponsor / company', 'onf-core' ),
	);
	echo '<h2>' . esc_html( $committed ? __( 'Done', 'onf-core' ) : __( 'Dry run — nothing was changed', 'onf-core' ) ) . '</h2>';

	echo '<h3>' . esc_html__( 'Events', 'onf-core' ) . '</h3>';
	echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'GiveWP category', 'onf-core' ) . '</th><th>' . esc_html__( 'Event', 'onf-core' ) . '</th><th>' . esc_html__( 'Action', 'onf-core' ) . '</th></tr></thead><tbody>';
	foreach ( $report['events'] as $row ) {
		printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $row['category'] ), esc_html( $row['event'] ?? '—' ), esc_html( $row['action'] ) );
	}
	echo '</tbody></table>';

	echo '<h3>' . esc_html__( 'Players in events', 'onf-core' ) . '</h3><ul>';
	/* translators: %d: count */
	printf( '<li>%s</li>', esc_html( sprintf( __( '%d entries to add', 'onf-core' ), $report['entries']['new'] ) ) );
	/* translators: %d: count */
	printf( '<li>%s</li>', esc_html( sprintf( __( '%d already there', 'onf-core' ), $report['entries']['existing'] ) ) );
	/* translators: %d: count */
	printf( '<li>%s</li>', esc_html( sprintf( __( '%d skipped (category is not an event)', 'onf-core' ), $report['entries']['skipped'] ) ) );
	echo '</ul>';

	echo '<h3>' . esc_html__( 'Player fields from registrations', 'onf-core' ) . '</h3><ul>';
	/* translators: %d: count */
	printf( '<li>%s</li>', esc_html( sprintf( __( '%d players have a matching registration', 'onf-core' ), $report['matched'] ) ) );
	foreach ( $report['fields'] as $key => $count ) {
		/* translators: 1: field name, 2: count */
		printf( '<li>%s</li>', esc_html( sprintf( __( '%1$s: %2$d blank fields filled', 'onf-core' ), $labels[ $key ] ?? $key, $count ) ) );
	}
	echo '</ul>';

	echo '<h3>' . esc_html__( 'Other', 'onf-core' ) . '</h3><ul>';
	/* translators: %d: count */
	printf( '<li>%s</li>', esc_html( sprintf( __( 'Email filled from GiveWP donation-notification settings for %d more players', 'onf-core' ), $report['givewp'] ) ) );
	/* translators: %d: count */
	printf( '<li>%s</li>', esc_html( sprintf( __( 'GiveWP form IDs recorded on %d players', 'onf-core' ), $report['forms'] ) ) );
	/* translators: %s: action */
	printf( '<li>%s</li>', esc_html( sprintf( __( 'Old ACF "Player" field group: %s', 'onf-core' ), $report['acf'] ) ) );
	echo '</ul>';
}

/**
 * Old player addresses (_wp_old_slug). Copying a player page with Yoast Duplicate Post copied the
 * original's old addresses too, so one old address can sit on dozens of players and WordPress redirects
 * a dead link to whichever comes first. Keep an old address only on the player whose name it matches
 * (nicknames count: nick-falkowski ↔ Nicholas Falkowski); remove the rest.
 *
 * @return array [ kept => [ slug => title ], removed => n, players => n ]
 */
function onf_cleanup_old_slugs( bool $commit ) {
	global $wpdb;
	$rows    = $wpdb->get_results(
		"SELECT m.meta_id, m.post_id, m.meta_value AS slug, p.post_title, p.post_name FROM {$wpdb->postmeta} m
		JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'player'
		WHERE m.meta_key = '_wp_old_slug'"
	);
	$kept    = array();
	$remove  = array();
	$touched = array();
	$seen    = array();
	foreach ( $rows as $row ) {
		$slug_name = onf_import_canonical( onf_import_name_key( preg_replace( '/-\d+$/', '', str_replace( '-', ' ', $row->slug ) ) ) );
		$own_name  = onf_import_canonical( onf_import_name_key( $row->post_title ) );
		$key       = $row->post_id . '|' . $row->slug;
		if ( $row->slug !== $row->post_name && $slug_name === $own_name && ! isset( $seen[ $key ] ) ) {
			$kept[ $row->slug ] = $row->post_title;
			$seen[ $key ]       = true;
			continue;
		}
		$remove[]                  = (int) $row->meta_id;
		$touched[ $row->post_id ] = true;
	}
	if ( $commit && $remove ) {
		foreach ( array_chunk( $remove, 500 ) as $chunk ) {
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN (" . implode( ',', $chunk ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		}
		foreach ( array_keys( $touched ) as $post_id ) {
			clean_post_cache( $post_id );
		}
	}
	return array(
		'kept'    => $kept,
		'removed' => count( $remove ),
		'players' => count( $touched ),
	);
}

function onf_render_old_slug_cleanup() {
	$result = null;
	$commit = false;
	if ( isset( $_POST['onf_slug_mode'] ) && check_admin_referer( 'onf_slug_cleanup' ) ) {
		$commit = 'run' === $_POST['onf_slug_mode'];
		$result = onf_cleanup_old_slugs( $commit );
	}
	echo '<hr><h2>' . esc_html__( 'Clean up old player addresses', 'onf-core' ) . '</h2>';
	echo '<p>' . esc_html__( 'Copying player pages copied their old web addresses too, so a dead link (e.g. a merged player) can redirect to the wrong player. This keeps an old address only on the player whose name it matches and removes the rest. Current addresses are not affected.', 'onf-core' ) . '</p>';
	echo '<form method="post">';
	wp_nonce_field( 'onf_slug_cleanup' );
	echo '<button class="button" name="onf_slug_mode" value="dry">' . esc_html__( 'Dry run (changes nothing)', 'onf-core' ) . '</button> ';
	echo '<button class="button button-primary" name="onf_slug_mode" value="run" onclick="return confirm(\'' . esc_js( __( 'Remove the copied old addresses now?', 'onf-core' ) ) . '\');">' . esc_html__( 'Clean up', 'onf-core' ) . '</button>';
	echo '</form>';
	if ( $result ) {
		/* translators: 1: removed, 2: players */
		echo '<h3>' . esc_html( $commit ? __( 'Done', 'onf-core' ) : __( 'Dry run — nothing was changed', 'onf-core' ) ) . '</h3><p>' . esc_html( sprintf( __( '%1$d copied old addresses removed from %2$d players.', 'onf-core' ), $result['removed'], $result['players'] ) ) . '</p>';
		if ( $result['kept'] ) {
			/* translators: %d: count */
			echo '<p>' . esc_html( sprintf( __( 'Kept %d genuine old addresses (they redirect to the right player):', 'onf-core' ), count( $result['kept'] ) ) ) . '</p><ul>';
			foreach ( $result['kept'] as $slug => $title ) {
				printf( '<li>/player/%s/ → %s</li>', esc_html( $slug ), esc_html( $title ) );
			}
			echo '</ul>';
		}
	}
}

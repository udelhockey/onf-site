<?php
/**
 * Events and funds admin: status/roster/raised columns and a roster box on the event edit screen.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'manage_onf_event_posts_columns',
	static function ( $columns ) {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );
		$columns['onf_status']  = __( 'Status', 'onf-core' );
		$columns['onf_players'] = __( 'Players', 'onf-core' );
		$columns['onf_raised']  = __( 'Raised', 'onf-core' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}
);

add_action(
	'manage_onf_event_posts_custom_column',
	static function ( $column, $post_id ) {
		if ( 'onf_status' === $column ) {
			$status = get_post_meta( $post_id, 'status', true );
			echo esc_html( onf_event_statuses()[ $status ] ?? '—' );
		} elseif ( 'onf_players' === $column ) {
			echo (int) count( onf_get_event_entries( $post_id ) );
		} elseif ( 'onf_raised' === $column ) {
			echo esc_html( onf_money( onf_event_total( $post_id ) ) );
		}
	},
	10,
	2
);

add_filter(
	'manage_onf_fund_posts_columns',
	static function ( $columns ) {
		$columns['onf_raised'] = __( 'Raised', 'onf-core' );
		return $columns;
	}
);

add_action(
	'manage_onf_fund_posts_custom_column',
	static function ( $column, $post_id ) {
		if ( 'onf_raised' === $column ) {
			echo esc_html( onf_money( onf_fund_total( $post_id ) ) );
		}
	},
	10,
	2
);

add_action(
	'add_meta_boxes_onf_event',
	static function () {
		add_meta_box( 'onf_event_roster', __( 'Roster & totals', 'onf-core' ), 'onf_render_event_roster_box', 'onf_event', 'normal' );
		add_meta_box( 'onf_event_player_show', __( 'Show on player pages', 'onf-core' ), 'onf_render_event_player_show_box', 'onf_event', 'side' );
	}
);

function onf_render_event_roster_box( WP_Post $post ) {
	$entries = onf_get_event_entries( $post->ID );
	$goal    = get_post_meta( $post->ID, 'goal', true );
	$total   = onf_event_total( $post->ID );

	printf(
		'<p><strong>%s</strong> %s%s</p>',
		esc_html__( 'Event total:', 'onf-core' ),
		esc_html( onf_money( $total ) ),
		$goal ? esc_html( ' / ' . onf_money( $goal ) ) : ''
	);

	if ( ! $entries ) {
		printf(
			'<p>%s <a href="%s">%s</a></p>',
			esc_html__( 'No players yet. Add them from the', 'onf-core' ),
			esc_url( admin_url( 'edit.php?post_type=player' ) ),
			esc_html__( 'Players list (bulk action "Add to").', 'onf-core' )
		);
		return;
	}

	$rows = array();
	foreach ( $entries as $entry ) {
		$rows[] = array(
			'entry'  => $entry,
			'raised' => onf_player_total( (int) $entry->player_id, $post->ID ),
		);
	}
	usort( $rows, static fn( $a, $b ) => $b['raised'] <=> $a['raised'] );

	printf(
		'<p><a class="button" href="%s">%s</a> <span class="description">%s</span></p>',
		esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=onf_roster_csv&event=' . $post->ID ), 'onf_roster_csv_' . $post->ID ) ),
		esc_html__( 'Download roster (CSV)', 'onf-core' ),
		esc_html__( 'Name, email, phone, position, number, jersey size, sponsor, goal, raised.', 'onf-core' )
	);
	wp_nonce_field( 'onf_save_roster_' . $post->ID, 'onf_roster_nonce' );
	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>#</th><th>' . esc_html__( 'Player', 'onf-core' ) . '</th><th>' . esc_html__( 'Jersey size', 'onf-core' ) . '</th><th>' . esc_html__( 'Goal', 'onf-core' ) . '</th><th>' . esc_html__( 'Raised', 'onf-core' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $rows as $i => $row ) {
		$entry = $row['entry'];
		printf(
			'<tr><td>%d</td><td><a href="%s">%s</a></td><td><input type="text" name="onf_jersey_size[%d]" value="%s" size="6" maxlength="20" aria-label="%s"></td><td>%s</td><td>%s</td></tr>',
			(int) $i + 1,
			esc_url( get_edit_post_link( $entry->player_id ) ),
			esc_html( $entry->player_name ),
			(int) $entry->player_id,
			esc_attr( $entry->jersey_size ?? '' ),
			/* translators: %s: player name */
			esc_attr( sprintf( __( 'Jersey size for %s', 'onf-core' ), $entry->player_name ) ),
			null === $entry->goal ? '—' : esc_html( onf_money( $entry->goal ) ),
			esc_html( onf_money( $row['raised'] ) )
		);
	}
	echo '</tbody></table>';
}

/**
 * Which profile items this event's player pages show (e.g. last team and stats for junior events only).
 */
function onf_render_event_player_show_box( WP_Post $post ) {
	wp_nonce_field( 'onf_save_player_show_' . $post->ID, 'onf_player_show_nonce' );
	$choice   = onf_event_player_items( $post->ID );
	$sitewide = onf_player_items_sitewide();
	$current  = null === $choice['items'] ? $sitewide : $choice['items'];
	if ( $choice['from'] && $choice['from'] !== $post->ID ) {
		/* translators: %s: event name */
		echo '<p class="description">' . esc_html( sprintf( __( 'Not set yet — using the %s’s choices. Saving this event keeps them (or your changes) for this event.', 'onf-core' ), get_the_title( $choice['from'] ) ) ) . '</p>';
	} elseif ( ! $choice['from'] ) {
		echo '<p class="description">' . esc_html__( 'Not set yet — everything allowed site-wide is shown.', 'onf-core' ) . '</p>';
	}
	foreach ( onf_player_display_items() as $item => $label ) {
		$allowed = in_array( $item, $sitewide, true );
		printf(
			'<label style="display:block;margin:2px 0%s"><input type="checkbox" name="onf_player_show[]" value="%s" %s %s> %s%s</label>',
			$allowed ? '' : ';opacity:.6',
			esc_attr( $item ),
			checked( in_array( $item, $current, true ), true, false ),
			disabled( ! $allowed, true, false ),
			esc_html( $label ),
			$allowed ? '' : ' <em>' . esc_html__( '(hidden site-wide)', 'onf-core' ) . '</em>'
		);
	}
	echo '<p class="description">' . esc_html__( 'Collected either way; this only decides what shows on player pages for this event. Next year’s event in the same series starts with these choices.', 'onf-core' ) . '</p>';
}

add_action(
	'save_post_onf_event',
	static function ( $post_id ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$show_nonce = sanitize_text_field( wp_unslash( $_POST['onf_player_show_nonce'] ?? '' ) );
		if ( wp_verify_nonce( $show_nonce, 'onf_save_player_show_' . $post_id ) ) {
			$sitewide = onf_player_items_sitewide();
			$posted   = array_map( 'strval', (array) wp_unslash( $_POST['onf_player_show'] ?? array() ) );
			// Items hidden site-wide can't be posted (disabled boxes); keep this event's earlier choice for them.
			$own   = onf_event_player_items( $post_id )['items'] ?? array_keys( onf_player_display_items() );
			$keep  = array_diff( array_intersect( $own, array_keys( onf_player_display_items() ) ), $sitewide );
			$items = array_values( array_intersect( array_keys( onf_player_display_items() ), array_merge( array_intersect( $posted, $sitewide ), $keep ) ) );
			update_post_meta( $post_id, '_onf_player_show', $items );
		}
		$roster_nonce = sanitize_text_field( wp_unslash( $_POST['onf_roster_nonce'] ?? '' ) );
		if ( wp_verify_nonce( $roster_nonce, 'onf_save_roster_' . $post_id ) ) {
			foreach ( (array) wp_unslash( $_POST['onf_jersey_size'] ?? array() ) as $player_id => $size ) {
				onf_update_entry( (int) $player_id, $post_id, array( 'jersey_size' => (string) $size ) );
			}
		}
		// phpcs:enable
	}
);

/**
 * Roster CSV: everything needed to order jerseys and contact players.
 */
add_action(
	'admin_post_onf_roster_csv',
	static function () {
		$event = absint( $_GET['event'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		check_admin_referer( 'onf_roster_csv_' . $event );
		if ( ! $event || 'onf_event' !== get_post_type( $event ) || ! current_user_can( 'edit_post', $event ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		$positions = onf_field_groups()['player']['onf_player_profile']['fields']['position'][2];
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( get_the_title( $event ) . ' roster.csv' ) . '"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Player', 'Email', 'Phone', 'Position', 'Number', 'Jersey size', 'Sponsor', 'Goal', 'Raised' ) );
		foreach ( onf_get_event_entries( $event ) as $entry ) {
			$p = (int) $entry->player_id;
			$row = array(
				$entry->player_name,
				get_post_meta( $p, 'email', true ),
				get_post_meta( $p, 'phone', true ),
				$positions[ get_post_meta( $p, 'position', true ) ] ?? '',
				get_post_meta( $p, 'jersey', true ),
				$entry->jersey_size ?? '',
				'' !== (string) $entry->sponsor ? $entry->sponsor : get_post_meta( $p, 'sponsor', true ),
				null === $entry->goal ? '' : $entry->goal,
				number_format( onf_player_total( $p, $event ), 2, '.', '' ),
			);
			// Spreadsheet safety: a cell starting with = + - @ is shown as text, not run as a formula.
			fputcsv( $out, array_map( static fn( $v ) => preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v, $row ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
);


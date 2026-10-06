<?php
/**
 * Players admin: Events + Raised columns, "Add to event" bulk actions, and an Events box on the edit screen.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Events that players can still be added to (not closed), newest first.
 *
 * @return WP_Post[]
 */
function onf_open_events() {
	return get_posts(
		array(
			'post_type'      => 'onf_event',
			'post_status'    => array( 'publish', 'draft', 'future' ),
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => 'status',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'status',
					'value'   => 'closed',
					'compare' => '!=',
				),
			),
		)
	);
}

/**
 * All events, newest first.
 *
 * @return WP_Post[]
 */
function onf_all_events() {
	return get_posts(
		array(
			'post_type'      => 'onf_event',
			'post_status'    => array( 'publish', 'draft', 'future' ),
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
}

// List columns.
add_filter(
	'manage_player_posts_columns',
	static function ( $columns ) {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );
		$columns['onf_events'] = __( 'Events', 'onf-core' );
		$columns['onf_raised'] = __( 'Raised (all-time)', 'onf-core' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}
);

add_action(
	'manage_player_posts_custom_column',
	static function ( $column, $post_id ) {
		if ( 'onf_events' === $column ) {
			$links = array();
			foreach ( onf_get_player_entries( $post_id ) as $entry ) {
				$links[] = sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $entry->event_id ) ), esc_html( $entry->event_title ) );
			}
			echo $links ? implode( '<br>', $links ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		} elseif ( 'onf_raised' === $column ) {
			echo esc_html( onf_money( onf_player_total( $post_id ) ) );
		}
	},
	10,
	2
);

// Bulk actions: one "Add to …" per open event.
add_filter(
	'bulk_actions-edit-player',
	static function ( $actions ) {
		foreach ( onf_open_events() as $event ) {
			/* translators: %s: event name */
			$actions[ 'onf_add_to_' . $event->ID ] = sprintf( __( 'Add to: %s', 'onf-core' ), $event->post_title );
		}
		return $actions;
	}
);

add_filter(
	'handle_bulk_actions-edit-player',
	static function ( $redirect, $action, $post_ids ) {
		if ( ! str_starts_with( $action, 'onf_add_to_' ) ) {
			return $redirect;
		}
		$event_id = (int) substr( $action, strlen( 'onf_add_to_' ) );
		$added    = 0;
		foreach ( $post_ids as $post_id ) {
			if ( current_user_can( 'edit_post', $post_id ) && onf_add_entry( (int) $post_id, $event_id ) ) {
				++$added;
			}
		}
		return add_query_arg(
			array(
				'onf_added' => $added,
				'onf_event' => $event_id,
			),
			$redirect
		);
	},
	10,
	3
);

add_action(
	'admin_notices',
	static function () {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET['onf_added'], $_GET['onf_event'] ) ) {
			return;
		}
		$added = absint( $_GET['onf_added'] );
		$event = get_the_title( absint( $_GET['onf_event'] ) );
		// phpcs:enable
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			/* translators: 1: number of players, 2: event name */
			esc_html( sprintf( _n( '%1$d player added to %2$s.', '%1$d players added to %2$s.', $added, 'onf-core' ), $added, $event ) )
		);
	}
);

// Edit screen: Events box.
add_action(
	'add_meta_boxes_player',
	static function () {
		add_meta_box( 'onf_player_events', __( 'Events', 'onf-core' ), 'onf_render_player_events_box', 'player', 'normal' );
	}
);

function onf_render_player_events_box( WP_Post $post ) {
	wp_nonce_field( 'onf_player_events', 'onf_player_events_nonce' );
	$entries = onf_get_player_entries( $post->ID );
	$in      = wp_list_pluck( $entries, 'event_id' );

	if ( $entries ) {
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Event', 'onf-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Goal ($)', 'onf-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Sponsor this event', 'onf-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Raised', 'onf-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Remove', 'onf-core' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$eid = (int) $entry->event_id;
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td>
				<td><input type="number" step="any" min="0" name="onf_entry[%3$d][goal]" value="%4$s" style="width:8em"></td>
				<td><input type="text" name="onf_entry[%3$d][sponsor]" value="%5$s" class="regular-text"></td>
				<td>%6$s</td>
				<td><input type="checkbox" name="onf_entry[%3$d][remove]" value="1" aria-label="%7$s"></td></tr>',
				esc_url( get_edit_post_link( $eid ) ),
				esc_html( $entry->event_title ),
				$eid,
				esc_attr( null === $entry->goal ? '' : 0 + $entry->goal ),
				esc_attr( $entry->sponsor ),
				esc_html( onf_money( onf_player_total( $post->ID, $eid ) ) ),
				/* translators: %s: event name */
				esc_attr( sprintf( __( 'Remove from %s', 'onf-core' ), $entry->event_title ) )
			);
		}
		echo '</tbody></table>';
		printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'All-time:', 'onf-core' ), esc_html( onf_money( onf_player_total( $post->ID ) ) ) );
	} else {
		echo '<p>' . esc_html__( 'Not in any events yet.', 'onf-core' ) . '</p>';
	}

	$choices = array_filter( onf_all_events(), static fn( $event ) => ! in_array( (string) $event->ID, $in, true ) );
	if ( $choices ) {
		echo '<p><label for="onf-add-event">' . esc_html__( 'Add to event:', 'onf-core' ) . '</label> ';
		echo '<select id="onf-add-event" name="onf_add_event"><option value="">—</option>';
		foreach ( $choices as $event ) {
			printf( '<option value="%d">%s</option>', (int) $event->ID, esc_html( $event->post_title ) );
		}
		echo '</select> ' . esc_html__( '(saved when you update the player)', 'onf-core' ) . '</p>';
	}
}

add_action(
	'save_post_player',
	static function ( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['onf_player_events_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'onf_player_events' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$rows = isset( $_POST['onf_entry'] ) ? wp_unslash( (array) $_POST['onf_entry'] ) : array();
		foreach ( $rows as $event_id => $row ) {
			$event_id = absint( $event_id );
			if ( ! empty( $row['remove'] ) ) {
				onf_remove_entry( $post_id, $event_id );
				continue;
			}
			onf_update_entry(
				$post_id,
				$event_id,
				array(
					'goal'    => $row['goal'] ?? '',
					'sponsor' => $row['sponsor'] ?? '',
				)
			);
		}

		$add = absint( $_POST['onf_add_event'] ?? 0 );
		if ( $add ) {
			onf_add_entry( $post_id, $add );
		}
	}
);

// Players → Merge players.
add_action(
	'admin_menu',
	static function () {
		add_submenu_page( 'edit.php?post_type=player', __( 'Merge players', 'onf-core' ), __( 'Merge players', 'onf-core' ), 'manage_options', 'onf-merge-players', 'onf_render_merge_players_page' );
	}
);

function onf_player_options( int $selected ) {
	$players = get_posts(
		array(
			'post_type'      => 'player',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	echo '<option value="0">—</option>';
	foreach ( $players as $p ) {
		$label = sprintf( '%s (#%d%s)', $p->post_title, $p->ID, 'publish' === $p->post_status ? '' : ', ' . $p->post_status );
		printf( '<option value="%d" %s>%s</option>', (int) $p->ID, selected( $selected, $p->ID, false ), esc_html( $label ) );
	}
}

function onf_player_summary( int $player_id ) {
	$events = wp_list_pluck( onf_get_player_entries( $player_id ), 'event_title' );
	global $wpdb;
	$gifts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . onf_gifts_table() . ' WHERE player_id = %d', $player_id ) );
	return sprintf(
		/* translators: 1: name, 2: gifts, 3: total, 4: events */
		__( '%1$s — %2$d gifts, %3$s all-time; events: %4$s', 'onf-core' ),
		get_the_title( $player_id ),
		$gifts,
		onf_money( onf_player_total( $player_id ) ),
		$events ? implode( ', ', $events ) : __( 'none', 'onf-core' )
	);
}

function onf_render_merge_players_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- the merge itself checks its nonce.
	$from = absint( $_REQUEST['from'] ?? 0 );
	$into = absint( $_REQUEST['into'] ?? 0 );
	$go   = isset( $_POST['onf_merge_confirm'] );
	// phpcs:enable
	echo '<div class="wrap"><h1>' . esc_html__( 'Merge players', 'onf-core' ) . '</h1>';
	echo '<p>' . esc_html__( 'For one person with two player pages. Everything moves to the player that stays: gifts, events, GiveWP form links and any blank profile fields. The other page goes to the Trash and its old web address redirects to the one that stays.', 'onf-core' ) . '</p>';

	if ( $go && check_admin_referer( 'onf_merge_players' ) ) {
		$result = onf_merge_players( $from, $into );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>' . esc_html( onf_player_summary( $into ) ) . '</p></div>';
			printf( '<p><a href="%s">%s</a></p></div>', esc_url( get_edit_post_link( $into ) ), esc_html__( 'Open the merged player', 'onf-core' ) );
			return;
		}
	}
	?>
	<form method="post">
		<?php wp_nonce_field( 'onf_merge_players' ); ?>
		<table class="form-table" role="presentation">
			<tr><th><label for="onf-merge-from"><?php esc_html_e( 'Merge this player…', 'onf-core' ); ?></label></th>
				<td><select id="onf-merge-from" name="from"><?php onf_player_options( $from ); ?></select>
				<?php if ( $from ) : ?><p class="description"><?php echo esc_html( onf_player_summary( $from ) ); ?></p><?php endif; ?></td></tr>
			<tr><th><label for="onf-merge-into"><?php esc_html_e( '…into this player (stays)', 'onf-core' ); ?></label></th>
				<td><select id="onf-merge-into" name="into"><?php onf_player_options( $into ); ?></select>
				<?php if ( $into ) : ?><p class="description"><?php echo esc_html( onf_player_summary( $into ) ); ?></p><?php endif; ?></td></tr>
		</table>
		<?php
		submit_button( __( 'Preview', 'onf-core' ), 'secondary', 'onf_merge_preview', false );
		if ( $from && $into && $from !== $into ) {
			echo ' ';
			submit_button( __( 'Merge now', 'onf-core' ), 'primary', 'onf_merge_confirm', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Merge these players? The first one goes to the Trash.', 'onf-core' ) ) . "');" ) );
		}
		?>
	</form>
	<?php
	echo '</div>';
}

/*
 * Archiving: Players list shows active players by default, with an "Archived" view,
 * Archive/Restore row and bulk actions, and a "Last event before…" filter.
 */

function onf_players_list_mode() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list view switch.
	return isset( $_GET['onf_archived'] ) ? 'archived' : 'active';
}

/**
 * IDs of players whose newest event is before $year (or who have no events, for $year = 'none').
 *
 * @return int[]
 */
function onf_players_last_event_before( $year ) {
	global $wpdb;
	if ( 'none' === $year ) {
		return array_map(
			'intval',
			$wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN " . onf_entries_table() . " e ON e.player_id = p.ID WHERE p.post_type = 'player' AND e.id IS NULL" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}
	return array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				'SELECT e.player_id FROM ' . onf_entries_table() . " e JOIN {$wpdb->posts} ev ON ev.ID = e.event_id GROUP BY e.player_id HAVING MAX(YEAR(ev.post_date)) < %d",
				(int) $year
			)
		)
	);
}

add_action(
	'pre_get_posts',
	static function ( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'player' !== $query->get( 'post_type' ) || 'edit-player' !== ( get_current_screen()->id ?? '' ) ) {
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = 'archived' === onf_players_list_mode()
			? array(
				'key'   => '_onf_archived',
				'value' => '1',
			)
			: array(
				'key'     => '_onf_archived',
				'compare' => 'NOT EXISTS',
			);
		$query->set( 'meta_query', $meta );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
		$before = sanitize_key( $_GET['onf_last_before'] ?? '' );
		if ( '' !== $before ) {
			$ids = onf_players_last_event_before( 'none' === $before ? 'none' : (int) $before );
			$query->set( 'post__in', $ids ? $ids : array( 0 ) );
		}
	}
);

add_filter(
	'views_edit-player',
	static function ( $views ) {
		global $wpdb;
		$archived = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'player' AND p.post_status <> 'trash' WHERE m.meta_key = '_onf_archived' AND m.meta_value = '1'" );
		$mode     = onf_players_list_mode();
		if ( 'archived' === $mode ) {
			foreach ( $views as $key => $html ) {
				$views[ $key ] = str_replace( 'class="current"', '', $html );
			}
		}
		$views['onf_archived'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( admin_url( 'edit.php?post_type=player&onf_archived=1' ) ),
			'archived' === $mode ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Archived', 'onf-core' ),
			$archived
		);
		return $views;
	}
);

add_action(
	'restrict_manage_posts',
	static function ( $post_type ) {
		if ( 'player' !== $post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
		$current = sanitize_key( $_GET['onf_last_before'] ?? '' );
		if ( 'archived' === onf_players_list_mode() ) {
			echo '<input type="hidden" name="onf_archived" value="1">';
		}
		echo '<label class="screen-reader-text" for="onf-last-before">' . esc_html__( 'Last event', 'onf-core' ) . '</label>';
		echo '<select name="onf_last_before" id="onf-last-before"><option value="">' . esc_html__( 'Any last event', 'onf-core' ) . '</option>';
		printf( '<option value="none" %s>%s</option>', selected( $current, 'none', false ), esc_html__( 'No events', 'onf-core' ) );
		$this_year = (int) current_time( 'Y' );
		for ( $year = $this_year; $year >= 2019; $year-- ) {
			/* translators: %d: year */
			printf( '<option value="%d" %s>%s</option>', $year, selected( $current, (string) $year, false ), esc_html( sprintf( __( 'Last event before %d', 'onf-core' ), $year ) ) );
		}
		echo '</select>';
	}
);

add_filter(
	'post_row_actions',
	static function ( $actions, $post ) {
		if ( 'player' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$archived = onf_is_archived( $post->ID );
		$url      = wp_nonce_url( admin_url( 'admin-post.php?action=onf_archive_player&player=' . $post->ID . '&archive=' . ( $archived ? 0 : 1 ) ), 'onf_archive_player_' . $post->ID );
		$actions['onf_archive'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $archived ? __( 'Restore', 'onf-core' ) : __( 'Archive', 'onf-core' ) ) );
		return $actions;
	},
	10,
	2
);

add_action(
	'admin_post_onf_archive_player',
	static function () {
		$player_id = absint( $_GET['player'] ?? 0 );
		check_admin_referer( 'onf_archive_player_' . $player_id );
		if ( ! current_user_can( 'edit_post', $player_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		$archive = ! empty( $_GET['archive'] );
		onf_set_archived( $player_id, $archive );
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=player' );
		wp_safe_redirect( add_query_arg( $archive ? 'onf_archived_n' : 'onf_restored_n', 1, remove_query_arg( array( 'onf_archived_n', 'onf_restored_n' ), $back ) ) );
		exit;
	}
);

add_filter(
	'bulk_actions-edit-player',
	static function ( $actions ) {
		if ( 'archived' === onf_players_list_mode() ) {
			$actions['onf_restore'] = __( 'Restore (un-archive)', 'onf-core' );
		} else {
			$actions['onf_archive'] = __( 'Archive', 'onf-core' );
		}
		return $actions;
	},
	20
);

add_filter(
	'handle_bulk_actions-edit-player',
	static function ( $redirect, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'onf_archive', 'onf_restore' ), true ) ) {
			return $redirect;
		}
		$n = 0;
		foreach ( $post_ids as $post_id ) {
			if ( current_user_can( 'edit_post', $post_id ) ) {
				onf_set_archived( (int) $post_id, 'onf_archive' === $action );
				++$n;
			}
		}
		return add_query_arg( 'onf_archive' === $action ? 'onf_archived_n' : 'onf_restored_n', $n, $redirect );
	},
	10,
	3
);

add_action(
	'admin_notices',
	static function () {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['onf_archived_n'] ) ) {
			$n = absint( $_GET['onf_archived_n'] );
			/* translators: %d: number of players */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d player archived. Their history and totals are kept; see the Archived view.', '%d players archived. Their history and totals are kept; see the Archived view.', $n, 'onf-core' ), $n ) ) );
		}
		if ( isset( $_GET['onf_restored_n'] ) ) {
			$n = absint( $_GET['onf_restored_n'] );
			/* translators: %d: number of players */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d player restored.', '%d players restored.', $n, 'onf-core' ), $n ) ) );
		}
		// phpcs:enable
	}
);

// On a player's edit screen, show that they're archived.
add_action(
	'post_submitbox_misc_actions',
	static function ( $post ) {
		if ( 'player' === $post->post_type && onf_is_archived( $post->ID ) ) {
			echo '<div class="misc-pub-section"><strong>' . esc_html__( 'Archived', 'onf-core' ) . '</strong> — ' . esc_html__( 'hidden from the Players list; history kept.', 'onf-core' ) . '</div>';
		}
	}
);

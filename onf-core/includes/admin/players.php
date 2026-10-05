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

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

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>#</th><th>' . esc_html__( 'Player', 'onf-core' ) . '</th><th>' . esc_html__( 'Goal', 'onf-core' ) . '</th><th>' . esc_html__( 'Raised', 'onf-core' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $rows as $i => $row ) {
		$entry = $row['entry'];
		printf(
			'<tr><td>%d</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>',
			(int) $i + 1,
			esc_url( get_edit_post_link( $entry->player_id ) ),
			esc_html( $entry->player_name ),
			null === $entry->goal ? '—' : esc_html( onf_money( $entry->goal ) ),
			esc_html( onf_money( $row['raised'] ) )
		);
	}
	echo '</tbody></table>';
}

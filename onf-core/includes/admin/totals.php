<?php
/**
 * Gifts → Totals: foundation total all-time and by year, each event series by year, each fund by year.
 * Counts completed donations only. Also a one-time setup: group events into series and link funds.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	static function () {
		// Registered after the Gifts menu exists (priority 10); listed second in it.
		add_submenu_page( 'onf-gifts', __( 'Totals', 'onf-core' ), __( 'Totals', 'onf-core' ), ONF_GIFTS_CAP, 'onf-totals', 'onf_render_totals_page', 1 );
	},
	11
);

/**
 * Put every event without a series into the one named after it, and link each fund with no series
 * to the series its gifts were mostly given under.
 *
 * @return array [ events => n, funds => n ]
 */
function onf_setup_series() {
	global $wpdb;
	$events = 0;
	foreach ( get_posts( array( 'post_type' => 'onf_event', 'post_status' => array( 'publish', 'draft', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $event_id ) {
		if ( ! onf_event_series_id( $event_id ) && onf_assign_series_from_title( $event_id ) ) {
			++$events;
		}
	}
	$funds = 0;
	foreach ( get_posts( array( 'post_type' => 'onf_fund', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $fund_id ) {
		if ( get_post_meta( $fund_id, 'series', true ) ) {
			continue;
		}
		$counts = array();
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT event_id FROM ' . onf_gifts_table() . ' WHERE fund_id = %d AND event_id > 0', $fund_id ) ) as $event_id ) {
			$series = onf_event_series_id( (int) $event_id );
			if ( $series ) {
				$counts[ $series ] = ( $counts[ $series ] ?? 0 ) + 1;
			}
		}
		if ( $counts ) {
			arsort( $counts );
			update_post_meta( $fund_id, 'series', (string) array_key_first( $counts ) );
			++$funds;
		}
	}
	return array(
		'events' => $events,
		'funds'  => $funds,
	);
}

function onf_whole_dollars( $amount ) {
	return $amount ? '$' . number_format( (float) $amount ) : '—';
}

function onf_render_totals_page() {
	global $wpdb;
	echo '<div class="wrap"><h1>' . esc_html__( 'Totals', 'onf-core' ) . '</h1>';

	if ( isset( $_POST['onf_setup_series'] ) && check_admin_referer( 'onf_setup_series' ) && current_user_can( ONF_GIFTS_CAP ) ) {
		$done = onf_setup_series();
		/* translators: 1: events, 2: funds */
		echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%1$d events put into series, %2$d funds linked to a series.', 'onf-core' ), $done['events'], $done['funds'] ) ) . '</p></div>';
	}

	// Event → [ year, series ].
	$event_info = array();
	foreach ( get_posts( array( 'post_type' => 'onf_event', 'post_status' => array( 'publish', 'draft', 'future', 'private' ), 'posts_per_page' => -1 ) ) as $event ) {
		$event_info[ $event->ID ] = array(
			'year'   => onf_event_year( $event->ID ),
			'series' => onf_event_series_id( $event->ID ),
		);
	}
	$rows = $wpdb->get_results(
		'SELECT event_id, fund_id, YEAR(gift_date) AS y, SUM(amount) AS total FROM ' . onf_gifts_table() . "
		WHERE status = 'completed' AND type = 'donation' GROUP BY event_id, fund_id, y"
	);

	$by_year   = array();
	$by_series = array(); // series_id (0 = no series, -1 = no event) => event year => total
	$by_fund   = array(); // fund_id => gift year => total
	$all_years = array();
	foreach ( $rows as $r ) {
		$total                 = (float) $r->total;
		$y                     = (int) $r->y;
		$by_year[ $y ]         = ( $by_year[ $y ] ?? 0 ) + $total;
		$all_years[ $y ]       = true;
		$event                 = $event_info[ (int) $r->event_id ] ?? null;
		$series                = $r->event_id ? ( $event ? $event['series'] : 0 ) : -1;
		$ey                    = $event ? $event['year'] : $y;
		$all_years[ $ey ]      = true;
		$by_series[ $series ][ $ey ] = ( $by_series[ $series ][ $ey ] ?? 0 ) + $total;
		if ( $r->fund_id ) {
			$by_fund[ (int) $r->fund_id ][ $y ] = ( $by_fund[ (int) $r->fund_id ][ $y ] ?? 0 ) + $total;
		}
	}
	$years = array_keys( $all_years );
	sort( $years );
	$grand = array_sum( $by_year );

	printf(
		'<p style="font-size:1.4em"><strong>%s</strong> %s</p>',
		esc_html__( 'Raised all-time:', 'onf-core' ),
		esc_html( onf_money( $grand ) )
	);
	echo '<p class="description">' . esc_html__( 'Completed donations only (refunds and event payments excluded), including GiveWP history since 2015.', 'onf-core' ) . '</p>';

	// Foundation by year.
	echo '<h2>' . esc_html__( 'By year', 'onf-core' ) . '</h2><table class="widefat striped" style="width:auto"><thead><tr>';
	foreach ( $years as $y ) {
		if ( isset( $by_year[ $y ] ) ) {
			echo '<th>' . (int) $y . '</th>';
		}
	}
	echo '</tr></thead><tbody><tr>';
	foreach ( $years as $y ) {
		if ( isset( $by_year[ $y ] ) ) {
			echo '<td>' . esc_html( onf_whole_dollars( $by_year[ $y ] ) ) . '</td>';
		}
	}
	echo '</tr></tbody></table>';

	// Series by event year.
	$series_names = onf_series_options();
	echo '<h2>' . esc_html__( 'By event series', 'onf-core' ) . '</h2>';
	echo '<p class="description">' . esc_html__( 'Each yearly event counts under its series, in the event\'s year.', 'onf-core' ) . '</p>';
	onf_render_year_matrix(
		$by_series,
		$years,
		static function ( $id ) use ( $series_names ) {
			if ( -1 === $id ) {
				return __( 'Not tied to an event (general gifts, sponsors…)', 'onf-core' );
			}
			if ( 0 === $id ) {
				return __( 'Events with no series yet', 'onf-core' );
			}
			return $series_names[ $id ] ?? '#' . $id;
		}
	);

	// One grid per series: years down, funds across.
	echo '<h2>' . esc_html__( 'Campaigns by year and fund', 'onf-core' ) . '</h2>';
	echo '<p class="description">' . esc_html__( 'Across a row: that year\'s campaign, all funds together. Down a column: that fund over all years. Each gift is counted once.', 'onf-core' ) . '</p>';
	$series_totals = $by_series;
	unset( $series_totals[0], $series_totals[-1] );
	uasort( $series_totals, static fn( $a, $b ) => array_sum( $b ) <=> array_sum( $a ) );
	foreach ( array_keys( $series_totals ) as $term_id ) {
		echo '<h3>' . esc_html( $series_names[ $term_id ] ?? '#' . $term_id ) . '</h3>';
		onf_render_series_grid( (int) $term_id );
	}

	// Funds by gift year.
	echo '<h2>' . esc_html__( 'By fund', 'onf-core' ) . '</h2>';
	onf_render_year_matrix(
		$by_fund,
		$years,
		static function ( $id ) use ( $series_names ) {
			$series = (int) get_post_meta( $id, 'series', true );
			return get_the_title( $id ) . ( $series && isset( $series_names[ $series ] ) ? ' (' . $series_names[ $series ] . ')' : '' );
		}
	);

	// Setup.
	$unassigned = 0;
	foreach ( $event_info as $info ) {
		$unassigned += $info['series'] ? 0 : 1;
	}
	echo '<h2>' . esc_html__( 'Series setup', 'onf-core' ) . '</h2>';
	echo '<p>' . esc_html__( 'Puts each event without a series into one named after it ("2025 Herb Mitchell Cup" → Herb Mitchell Cup; both 2022 Pre-Draft divisions → Pre-Draft Showcase), and links each fund to the series its gifts came in under (Dolan Fund → holiday giving). Only fills what\'s missing. Adjust any of it under Events → Series, or a fund\'s "Event series".', 'onf-core' ) . '</p>';
	/* translators: %d: number of events */
	echo '<p>' . esc_html( sprintf( __( 'Events without a series: %d', 'onf-core' ), $unassigned ) ) . '</p>';
	echo '<form method="post">';
	wp_nonce_field( 'onf_setup_series' );
	submit_button( __( 'Group events into series and link funds', 'onf-core' ), 'secondary', 'onf_setup_series', false );
	echo '</form></div>';
}

/**
 * Rows × years table with a total column. $label( $id ) names each row.
 */
function onf_render_year_matrix( array $data, array $years, callable $label ) {
	if ( ! $data ) {
		echo '<p>' . esc_html__( 'Nothing yet.', 'onf-core' ) . '</p>';
		return;
	}
	$used = array();
	foreach ( $data as $cells ) {
		foreach ( array_keys( $cells ) as $y ) {
			$used[ $y ] = true;
		}
	}
	$cols = array_values( array_filter( $years, static fn( $y ) => isset( $used[ $y ] ) ) );
	$rows = array();
	foreach ( $data as $id => $cells ) {
		$rows[] = array(
			'label' => $label( $id ),
			'cells' => $cells,
			'total' => array_sum( $cells ),
		);
	}
	usort( $rows, static fn( $a, $b ) => $b['total'] <=> $a['total'] );

	echo '<div style="overflow-x:auto"><table class="widefat striped" style="width:auto"><thead><tr><th></th>';
	foreach ( $cols as $y ) {
		echo '<th>' . (int) $y . '</th>';
	}
	echo '<th>' . esc_html__( 'All-time', 'onf-core' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		echo '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th>';
		foreach ( $cols as $y ) {
			echo '<td>' . esc_html( onf_whole_dollars( $row['cells'][ $y ] ?? 0 ) ) . '</td>';
		}
		echo '<td><strong>' . esc_html( onf_whole_dollars( $row['total'] ) ) . '</strong></td></tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * Grid for one series: rows = its yearly events, columns = funds (+ player pages, general),
 * with totals across (each year's campaign) and down (each fund over all years).
 *
 * @return array [ rows => [ event_id => [ year, cells => [ col => total ] ] ], cols => [ col => label ] ]
 */
function onf_series_grid_data( int $term_id ) {
	global $wpdb;
	$events = get_posts(
		array(
			'post_type'      => 'onf_event',
			'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'onf_series',
					'terms'    => $term_id,
				),
			),
		)
	);
	if ( ! $events ) {
		return array(
			'rows' => array(),
			'cols' => array(),
		);
	}
	$ids  = implode( ',', array_map( 'intval', $events ) );
	$sums = $wpdb->get_results(
		'SELECT event_id, fund_id, (player_id > 0) AS has_player, SUM(amount) AS total FROM ' . onf_gifts_table() . "
		WHERE status = 'completed' AND type = 'donation' AND event_id IN ($ids)
		GROUP BY event_id, fund_id, has_player" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
	);

	$rows = array();
	foreach ( $events as $event_id ) {
		$rows[ $event_id ] = array(
			'year'  => onf_event_year( $event_id ),
			'title' => get_the_title( $event_id ),
			'cells' => array(),
		);
	}
	$cols = array();
	foreach ( $sums as $s ) {
		if ( $s->fund_id ) {
			$col          = 'f' . $s->fund_id;
			$cols[ $col ] = get_the_title( $s->fund_id );
		} elseif ( $s->has_player ) {
			$col          = 'players';
			$cols[ $col ] = __( 'Player pages', 'onf-core' );
		} else {
			$col          = 'general';
			$cols[ $col ] = __( 'General', 'onf-core' );
		}
		$rows[ $s->event_id ]['cells'][ $col ] = ( $rows[ $s->event_id ]['cells'][ $col ] ?? 0 ) + (float) $s->total;
	}
	// Funds A–Z, then player pages, then general.
	uksort(
		$cols,
		static function ( $a, $b ) use ( $cols ) {
			$rank = static fn( $k ) => 'players' === $k ? 1 : ( 'general' === $k ? 2 : 0 );
			return $rank( $a ) <=> $rank( $b ) ?: strcasecmp( $cols[ $a ], $cols[ $b ] );
		}
	);
	uasort( $rows, static fn( $a, $b ) => $a['year'] <=> $b['year'] ?: strcmp( $a['title'], $b['title'] ) );
	return array(
		'rows' => $rows,
		'cols' => $cols,
	);
}

function onf_render_series_grid( int $term_id ) {
	$grid = onf_series_grid_data( $term_id );
	if ( ! $grid['cols'] ) {
		echo '<p>' . esc_html__( 'No gifts in this series yet.', 'onf-core' ) . '</p>';
		return;
	}
	// Two events in the same year (e.g. two divisions) show their names; otherwise just the year.
	$years = array_count_values( wp_list_pluck( $grid['rows'], 'year' ) );
	$down  = array();
	echo '<div style="overflow-x:auto"><table class="widefat striped" style="width:auto"><thead><tr><th></th>';
	foreach ( $grid['cols'] as $label ) {
		echo '<th>' . esc_html( $label ) . '</th>';
	}
	echo '<th>' . esc_html__( 'Campaign total', 'onf-core' ) . '</th></tr></thead><tbody>';
	foreach ( $grid['rows'] as $event_id => $row ) {
		$label = $years[ $row['year'] ] > 1 ? $row['title'] : (string) $row['year'];
		printf( '<tr><th scope="row"><a href="%s">%s</a></th>', esc_url( get_edit_post_link( $event_id ) ), esc_html( $label ) );
		foreach ( array_keys( $grid['cols'] ) as $col ) {
			$v            = $row['cells'][ $col ] ?? 0;
			$down[ $col ] = ( $down[ $col ] ?? 0 ) + $v;
			echo '<td>' . esc_html( onf_whole_dollars( $v ) ) . '</td>';
		}
		echo '<td><strong>' . esc_html( onf_whole_dollars( array_sum( $row['cells'] ) ) ) . '</strong></td></tr>';
	}
	echo '<tr><th scope="row">' . esc_html__( 'All-time', 'onf-core' ) . '</th>';
	foreach ( array_keys( $grid['cols'] ) as $col ) {
		echo '<td><strong>' . esc_html( onf_whole_dollars( $down[ $col ] ?? 0 ) ) . '</strong></td>';
	}
	echo '<td><strong>' . esc_html( onf_whole_dollars( array_sum( $down ) ) ) . '</strong></td></tr>';
	echo '</tbody></table></div>';
}

// The grid on each series' own edit page (Events → Series → a series).
add_action(
	'onf_series_edit_form',
	static function ( $term ) {
		echo '<h2>' . esc_html__( 'Totals by year and fund', 'onf-core' ) . '</h2>';
		onf_render_series_grid( (int) $term->term_id );
	}
);

// First load after installing: group existing events into series and link funds, once.
add_action(
	'admin_init',
	static function () {
		if ( get_option( 'onf_series_setup_done' ) || ! current_user_can( ONF_GIFTS_CAP ) ) {
			return;
		}
		update_option( 'onf_series_setup_done', current_time( 'mysql' ), false );
		$done = onf_setup_series();
		set_transient( 'onf_series_setup_notice', $done, HOUR_IN_SECONDS );
	}
);

add_action(
	'admin_notices',
	static function () {
		$done = get_transient( 'onf_series_setup_notice' );
		if ( ! $done ) {
			return;
		}
		delete_transient( 'onf_series_setup_notice' );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			/* translators: 1: events, 2: funds */
			esc_html( sprintf( __( 'Event series set up: %1$d events grouped into series, %2$d funds linked.', 'onf-core' ), $done['events'], $done['funds'] ) ),
			esc_url( admin_url( 'admin.php?page=onf-totals' ) ),
			esc_html__( 'See Gifts → Totals', 'onf-core' )
		);
	}
);

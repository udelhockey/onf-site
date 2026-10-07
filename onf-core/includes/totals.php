<?php
/**
 * Public totals: sums, donor lists and leaderboards for the blocks, plus the series grid shared with
 * Gifts → Totals and the PDF report. Counts completed donations only; each gift is counted once.
 * Results are cached and cleared whenever a gift changes (see onf_flush_totals()).
 */

defined( 'ABSPATH' ) || exit;

function onf_whole_dollars( $amount ) {
	return $amount ? '$' . number_format( (float) $amount ) : '—';
}

/**
 * Grid for one series: rows = its yearly events, columns = funds (+ player pages, general),
 * with totals across (each year's campaign) and down (each fund over all years).
 *
 * @return array [ rows => [ event_id => [ year, cells => [ col => total ] ] ], cols => [ col => label ] ]
 */
function onf_series_grid_data( int $term_id, int $from = 0, int $to = 9999 ) {
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
	$events = array_values( array_filter( $events, static fn( $id ) => onf_event_year( $id ) >= $from && onf_event_year( $id ) <= $to ) );
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


/**
 * WHERE clause for a gift filter.
 *
 * @param array $f player_id (int), event_ids (int[]), fund_ids (int[]), year (int, gift year).
 */
function onf_totals_where( array $f ) {
	global $wpdb;
	$where = array( "status = 'completed'", "type = 'donation'" );
	if ( ! empty( $f['player_id'] ) ) {
		$where[] = $wpdb->prepare( 'player_id = %d', $f['player_id'] );
	}
	foreach ( array( 'event_ids' => 'event_id', 'fund_ids' => 'fund_id' ) as $key => $column ) {
		if ( isset( $f[ $key ] ) ) {
			$ids     = array_filter( array_map( 'absint', (array) $f[ $key ] ) );
			$where[] = $ids ? "$column IN (" . implode( ',', $ids ) . ')' : '1 = 0'; // An empty list matches nothing.
		}
	}
	if ( ! empty( $f['year'] ) ) {
		$where[] = $wpdb->prepare( 'gift_date >= %s AND gift_date < %s', (int) $f['year'] . '-01-01', ( (int) $f['year'] + 1 ) . '-01-01' );
	}
	return implode( ' AND ', $where );
}

/**
 * Cache wrapper keyed on the totals version, so any gift change refreshes every block.
 */
function onf_totals_cache( string $name, array $args, callable $compute ) {
	$key   = 'onf_t' . get_option( 'onf_totals_version', 1 ) . '_' . md5( $name . wp_json_encode( $args ) );
	$value = get_transient( $key );
	if ( false === $value ) {
		$value = $compute();
		set_transient( $key, $value, DAY_IN_SECONDS );
	}
	return $value;
}

/**
 * Total, gift count and donor count for a filter.
 *
 * @return array [ total => float, gifts => int, donors => int ]
 */
function onf_sum( array $f ) {
	return onf_totals_cache(
		'sum',
		$f,
		static function () use ( $f ) {
			global $wpdb;
			$row = $wpdb->get_row( 'SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS gifts, COUNT(DISTINCT NULLIF(donor_id, 0)) AS donors FROM ' . onf_gifts_table() . ' WHERE ' . onf_totals_where( $f ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from prepared parts.
			return array(
				'total'  => (float) $row->total,
				'gifts'  => (int) $row->gifts,
				'donors' => (int) $row->donors,
			);
		}
	);
}

/**
 * The same split as the campaign grid: each fund, player pages, general.
 *
 * @return array label => total, funds A–Z, then player pages, then general. Empty parts left out.
 */
function onf_sum_breakdown( array $f ) {
	return onf_totals_cache(
		'breakdown',
		$f,
		static function () use ( $f ) {
			global $wpdb;
			$rows  = $wpdb->get_results( 'SELECT fund_id, (player_id > 0) AS has_player, SUM(amount) AS total FROM ' . onf_gifts_table() . ' WHERE ' . onf_totals_where( $f ) . ' GROUP BY fund_id, has_player' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$funds = array();
			$tail  = array();
			foreach ( $rows as $r ) {
				if ( $r->fund_id ) {
					$label           = get_the_title( (int) $r->fund_id );
					$funds[ $label ] = ( $funds[ $label ] ?? 0 ) + (float) $r->total;
				} else {
					$label          = $r->has_player ? __( 'Player pages', 'onf-core' ) : __( 'General', 'onf-core' );
					$tail[ $label ] = ( $tail[ $label ] ?? 0 ) + (float) $r->total;
				}
			}
			uksort( $funds, 'strcasecmp' );
			krsort( $tail ); // "Player pages" before "General".
			return $funds + $tail;
		}
	);
}

/**
 * Gifts for a donor board.
 *
 * @param string $order 'newest' or 'largest'.
 * @return object[] id, amount, name (as shown: display name / first + last, or Anonymous), message, gift_date, player_id.
 */
function onf_gift_list( array $f, string $order = 'newest', int $limit = 500 ) {
	return onf_totals_cache(
		'list',
		array( $f, $order, $limit ),
		static function () use ( $f, $order, $limit ) {
			global $wpdb;
			$by   = 'largest' === $order ? 'amount DESC, gift_date DESC' : 'gift_date DESC, id DESC';
			$rows = $wpdb->get_results(
				'SELECT id, amount, anonymous, display_name, donor_first_name, donor_last_name, message, gift_date, player_id FROM ' . onf_gifts_table()
				. ' WHERE ' . onf_totals_where( $f ) . " ORDER BY $by LIMIT " . max( 1, $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed order and integer limit.
			);
			foreach ( $rows as $row ) {
				$name      = trim( $row->display_name ) !== '' ? $row->display_name : trim( $row->donor_first_name . ' ' . $row->donor_last_name );
				$row->name = ( $row->anonymous || '' === $name ) ? __( 'Anonymous', 'onf-core' ) : $name;
				unset( $row->display_name, $row->donor_first_name, $row->donor_last_name, $row->anonymous );
			}
			return $rows;
		}
	);
}

/**
 * Players in an event with what each raised for it, highest first. Players on the roster with no gifts yet
 * come last (A–Z); players with gifts to the event but no roster entry are included too.
 *
 * @return object[] player_id, name, total, goal.
 */
function onf_event_leaderboard( int $event_id ) {
	return onf_totals_cache(
		'leaderboard',
		array( $event_id ),
		static function () use ( $event_id ) {
			global $wpdb;
			$sums = $wpdb->get_results( 'SELECT player_id, SUM(amount) AS total FROM ' . onf_gifts_table() . ' WHERE ' . onf_totals_where( array( 'event_ids' => array( $event_id ) ) ) . ' AND player_id > 0 GROUP BY player_id' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = array();
			foreach ( onf_get_event_entries( $event_id ) as $entry ) {
				$rows[ (int) $entry->player_id ] = (object) array(
					'player_id' => (int) $entry->player_id,
					'name'      => $entry->player_name,
					'total'     => 0.0,
					'goal'      => (float) $entry->goal,
				);
			}
			foreach ( $sums as $s ) {
				$id = (int) $s->player_id;
				if ( ! isset( $rows[ $id ] ) ) {
					$rows[ $id ] = (object) array(
						'player_id' => $id,
						'name'      => get_the_title( $id ),
						'total'     => 0.0,
						'goal'      => 0.0,
					);
				}
				$rows[ $id ]->total = (float) $s->total;
			}
			$default_goal = (float) get_post_meta( $event_id, 'player_goal', true );
			foreach ( $rows as $row ) {
				$row->goal = $row->goal > 0 ? $row->goal : $default_goal;
			}
			$rows = array_values( $rows );
			usort( $rows, static fn( $a, $b ) => $b->total <=> $a->total ?: strcasecmp( $a->name, $b->name ) );
			return $rows;
		}
	);
}

/**
 * The year of the oldest completed gift (for "raised since …").
 */
function onf_first_gift_year() {
	return (int) onf_totals_cache(
		'first_year',
		array(),
		static function () {
			global $wpdb;
			return (int) $wpdb->get_var( 'SELECT YEAR(MIN(gift_date)) FROM ' . onf_gifts_table() . ' WHERE ' . onf_totals_where( array() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	);
}

/**
 * An event's IDs in a series, optionally only one event year.
 *
 * @return int[]
 */
function onf_series_event_ids( int $term_id, int $year = 0 ) {
	if ( ! $term_id ) {
		return array();
	}
	$ids = get_posts(
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
	if ( $year ) {
		$ids = array_filter( $ids, static fn( $id ) => onf_event_year( (int) $id ) === $year );
	}
	return array_values( array_map( 'intval', $ids ) );
}

/**
 * The event a player page is about right now: their current (open) event, else the latest event they were in.
 */
function onf_player_focus_event( int $player_id ) {
	$current = onf_player_current_event( $player_id );
	if ( $current ) {
		return $current;
	}
	$entries = onf_get_player_entries( $player_id ); // Newest first.
	return $entries ? (int) $entries[0]->event_id : 0;
}

/**
 * A series' current event (open now), else its newest event.
 */
function onf_series_focus_event( int $term_id ) {
	$current = onf_series_current_event( $term_id );
	if ( $current ) {
		return $current;
	}
	$ids = onf_series_event_ids( $term_id );
	usort( $ids, static fn( $a, $b ) => onf_event_year( $b ) <=> onf_event_year( $a ) ?: $b <=> $a );
	return $ids ? $ids[0] : 0;
}

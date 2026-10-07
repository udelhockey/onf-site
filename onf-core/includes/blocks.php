<?php
/**
 * Public blocks: Donate, Progress / totals, Donor board, Leaderboard, Foundation total, Player profile,
 * Event details, Event & fund cards, Series totals. All server-rendered so totals are always current.
 * The editor script is plain JavaScript (no build step): blocks/editor.js.
 *
 * Each block works out what it is about from the page it sits on (player, event, fund, series);
 * the block settings can point it somewhere else.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		wp_register_style( 'onf-blocks', ONF_CORE_URL . 'blocks/blocks.css', array(), ONF_CORE_VERSION );
		wp_register_script(
			'onf-blocks-editor',
			ONF_CORE_URL . 'blocks/editor.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render', 'wp-data', 'wp-core-data' ),
			ONF_CORE_VERSION,
			true
		);
		$callbacks = array(
			'donate'           => 'onf_render_donate_block',
			'progress'         => 'onf_render_progress_block',
			'donor-board'      => 'onf_render_donor_board_block',
			'leaderboard'      => 'onf_render_leaderboard_block',
			'foundation-total' => 'onf_render_foundation_total_block',
			'player-profile'   => 'onf_render_player_profile_block',
			'event-details'    => 'onf_render_event_details_block',
			'cards'            => 'onf_render_cards_block',
			'series-totals'    => 'onf_render_series_totals_block',
		);
		foreach ( $callbacks as $dir => $callback ) {
			register_block_type( ONF_CORE_DIR . 'blocks/' . $dir, array( 'render_callback' => $callback ) );
		}
	}
);

add_filter(
	'block_categories_all',
	static function ( $categories ) {
		array_unshift(
			$categories,
			array(
				'slug'  => 'onf',
				'title' => __( 'Open Net Foundation', 'onf-core' ),
			)
		);
		return $categories;
	}
);

// Goals, titles and statuses feed cached totals and leaderboards: refresh them when one is saved.
add_action(
	'save_post',
	static function ( $post_id, $post ) {
		if ( in_array( $post->post_type, array( 'player', 'onf_event', 'onf_fund' ), true ) && ! wp_is_post_revision( $post_id ) ) {
			onf_flush_totals();
		}
	},
	30,
	2
);

/*
 * ------------------------------------------------------------------------------------------------
 * Player pages: GiveWP form or ONF donations (Gifts → Settings → Player-page donations).
 * ------------------------------------------------------------------------------------------------
 */

function onf_player_donations_onf() {
	return 'onf' === onf_setting( 'player_donations' );
}

/**
 * On player pages, hide what the blocks now show — at display time only; the stored content is untouched,
 * so switching the setting back restores the old page exactly.
 * - ONF mode: the old [give_form] shortcodes.
 * - EliteProspects ID filled in: the old [iframe] stats embed (the Player profile block shows the stats).
 */
add_filter(
	'the_content',
	static function ( $content ) {
		$post = get_post();
		if ( ! $post || 'player' !== $post->post_type ) {
			return $content;
		}
		if ( onf_player_donations_onf() ) {
			$content = preg_replace( '/\[give_form\b[^\]]*\]/i', '', $content );
		}
		if ( get_post_meta( $post->ID, 'ep_id', true ) ) {
			$content = preg_replace( '/\[iframe\b[^\]]*eliteprospects\.com[^\]]*\]/i', '', $content );
		}
		return $content;
	},
	1
);

/*
 * ------------------------------------------------------------------------------------------------
 * Helpers.
 * ------------------------------------------------------------------------------------------------
 */

/**
 * The post the block is shown on: block context (query loops), else the page being viewed,
 * else the post being edited (editor preview).
 */
function onf_block_post_id( $block ) {
	if ( $block instanceof WP_Block && ! empty( $block->context['postId'] ) ) {
		return (int) $block->context['postId'];
	}
	if ( is_singular() ) {
		return (int) get_queried_object_id();
	}
	return (int) get_the_ID();
}

/**
 * Series term being viewed (series archive page).
 */
function onf_block_series_context() {
	if ( is_tax( 'onf_series' ) ) {
		return (int) get_queried_object_id();
	}
	return 0;
}

function onf_is_editor_preview() {
	return defined( 'REST_REQUEST' ) && REST_REQUEST;
}

/**
 * A note shown only in the editor (where a block has nothing to show on this screen); nothing on the site.
 */
function onf_block_note( $text ) {
	return onf_is_editor_preview() ? '<div class="onf-block-note">' . esc_html( $text ) . '</div>' : '';
}

function onf_block_wrap( string $class, string $html, array $extra = array() ) {
	return '<div ' . get_block_wrapper_attributes( array_merge( array( 'class' => $class ), $extra ) ) . '>' . $html . '</div>';
}

/**
 * Money for display: whole dollars ($1,240), cents only when there are any on a single gift ($52.50).
 */
function onf_display_money( $amount, bool $round = true ) {
	$amount = (float) $amount;
	if ( $round || abs( $amount - round( $amount ) ) < 0.005 ) {
		return '$' . number_format( round( $amount ) );
	}
	return '$' . number_format( $amount, 2 );
}

function onf_first_name( int $post_id ) {
	$parts = preg_split( '/\s+/', trim( get_the_title( $post_id ) ) );
	return $parts[0] ?? '';
}

/**
 * Link to a post when it's public; plain text otherwise (draft events, historical players).
 */
function onf_maybe_link( int $post_id, string $text = '', string $class = '' ) {
	$text = '' !== $text ? $text : get_the_title( $post_id );
	if ( 'publish' === get_post_status( $post_id ) ) {
		return '<a href="' . esc_url( get_permalink( $post_id ) ) . '"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $text ) . '</a>';
	}
	return '<span' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $text ) . '</span>';
}

/**
 * Goal bar. Uses a native <progress> element (no inline styles), with the percent for screen readers.
 */
function onf_progress_bar( float $total, float $goal, string $label = '' ) {
	if ( $goal <= 0 ) {
		return '';
	}
	$pct = (int) min( 100, floor( $total / $goal * 100 ) );
	/* translators: 1: amount raised, 2: goal */
	$aria = '' !== $label ? $label : sprintf( __( '%1$s of %2$s goal', 'onf-core' ), onf_display_money( $total ), onf_display_money( $goal ) );
	return sprintf(
		'<progress class="onf-bar" max="100" value="%d" aria-label="%s">%d%%</progress>',
		$pct,
		esc_attr( $aria ),
		$pct
	);
}

/**
 * Status words for visitors.
 */
function onf_event_status_label( int $event_id ) {
	$labels = array(
		'registration' => __( 'Registration open', 'onf-core' ),
		'fundraising'  => __( 'Fundraising now', 'onf-core' ),
		'closed'       => __( 'Final', 'onf-core' ),
	);
	$status = (string) get_post_meta( $event_id, 'status', true );
	return $labels[ $status ] ?? '';
}

function onf_event_is_open( int $event_id ) {
	return in_array( get_post_meta( $event_id, 'status', true ), array( 'registration', 'fundraising' ), true );
}

/**
 * "June 2 – 16, 2026", "Nov 28 – Dec 1, 2026", "June 2, 2026"; just the year when no date is set.
 */
function onf_event_dates( int $event_id ) {
	$start = (string) get_post_meta( $event_id, 'start_date', true );
	$end   = (string) get_post_meta( $event_id, 'end_date', true );
	$s     = $start ? strtotime( $start ) : 0;
	$e     = $end ? strtotime( $end ) : 0;
	if ( ! $s ) {
		return (string) onf_event_year( $event_id );
	}
	if ( ! $e || $e <= $s ) {
		return wp_date( 'F j, Y', $s, new DateTimeZone( 'UTC' ) );
	}
	$utc = new DateTimeZone( 'UTC' );
	if ( wp_date( 'Y', $s, $utc ) !== wp_date( 'Y', $e, $utc ) ) {
		return wp_date( 'M j, Y', $s, $utc ) . ' – ' . wp_date( 'M j, Y', $e, $utc );
	}
	if ( wp_date( 'm', $s, $utc ) !== wp_date( 'm', $e, $utc ) ) {
		return wp_date( 'M j', $s, $utc ) . ' – ' . wp_date( 'M j, Y', $e, $utc );
	}
	return wp_date( 'F j', $s, $utc ) . ' – ' . wp_date( 'j, Y', $e, $utc );
}

/**
 * Open events, soonest first: published, status Registration or Fundraising.
 *
 * @return int[]
 */
function onf_public_open_events( int $series = 0 ) {
	return onf_query_events( array( 'registration', 'fundraising' ), $series );
}

/**
 * @param string[] $statuses Event statuses ('' = no status set).
 * @return int[] Published events. Open ones soonest first; others newest first.
 */
function onf_query_events( array $statuses, int $series = 0 ) {
	$args = array(
		'post_type'      => 'onf_event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	);
	if ( $series ) {
		$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			array(
				'taxonomy' => 'onf_series',
				'terms'    => $series,
			),
		);
	}
	$ids = array_values(
		array_filter(
			array_map( 'intval', get_posts( $args ) ),
			static fn( $id ) => in_array( (string) get_post_meta( $id, 'status', true ), $statuses, true )
		)
	);
	$open = (bool) array_intersect( $statuses, array( 'registration', 'fundraising' ) ) && ! in_array( 'closed', $statuses, true );
	usort(
		$ids,
		static function ( $a, $b ) use ( $open ) {
			$ka = onf_event_year( $a ) . '|' . get_post_meta( $a, 'start_date', true ) . '|' . get_post_field( 'post_date', $a );
			$kb = onf_event_year( $b ) . '|' . get_post_meta( $b, 'start_date', true ) . '|' . get_post_field( 'post_date', $b );
			return $open ? strcmp( $ka, $kb ) : strcmp( $kb, $ka );
		}
	);
	return $ids;
}

/**
 * The URL of the Donate page: the page using the theme's "Donate page" template, else /donation/ (today's page).
 */
function onf_donate_page_url() {
	$pages = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => 'donate-page', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	if ( $pages ) {
		return get_permalink( $pages[0] );
	}
	$page = get_page_by_path( 'donation' );
	return $page ? get_permalink( $page ) : home_url( '/donation/' );
}

/**
 * Work out what a Progress or Donor board block is about.
 *
 * @param array $a Block attributes (source, scope, player, event, fund, series, funds, year).
 * @return array|null [ filter => gift filter, label => text, goal => float, kind => string, event => int ] or null.
 */
function onf_block_target( array $a, $block ) {
	$source = $a['source'] ?? 'auto';
	$scope  = $a['scope'] ?? 'current';
	$year   = (int) ( $a['year'] ?? 0 );
	$year   = 'year' === $scope ? ( $year ? $year : (int) current_time( 'Y' ) ) : 0;
	$post   = onf_block_post_id( $block );
	$type   = $post ? get_post_type( $post ) : '';

	if ( 'auto' === $source ) {
		$map    = array(
			'player'    => 'player',
			'onf_event' => 'event',
			'onf_fund'  => 'fund',
		);
		$source = $map[ $type ] ?? ( onf_block_series_context() ? 'series' : '' );
	}
	$pick = static fn( $key, $post_type ) => (int) ( $a[ $key ] ?? 0 ) ? (int) $a[ $key ] : ( get_post_type( $post ) === $post_type ? $post : 0 );

	switch ( $source ) {
		case 'player':
			$player = $pick( 'player', 'player' );
			if ( ! $player ) {
				return null;
			}
			$event = (int) ( $a['event'] ?? 0 );
			if ( 'current' === $scope ) {
				$event = onf_player_focus_event( $player );
			}
			if ( in_array( $scope, array( 'current', 'event' ), true ) && $event ) {
				$goal = 0.0;
				foreach ( onf_get_player_entries( $player ) as $entry ) {
					if ( (int) $entry->event_id === $event ) {
						$goal = (float) $entry->goal;
					}
				}
				$goal = $goal > 0 ? $goal : (float) get_post_meta( $event, 'player_goal', true );
				return array(
					'kind'   => 'player',
					'filter' => array(
						'player_id' => $player,
						'event_ids' => array( $event ),
					),
					/* translators: %s: event name */
					'label'  => sprintf( __( 'Raised for the %s', 'onf-core' ), get_the_title( $event ) ),
					'goal'   => $goal,
					'event'  => $event,
				);
			}
			return array(
				'kind'   => 'player',
				'filter' => array_filter(
					array(
						'player_id' => $player,
						'year'      => $year,
					)
				),
				/* translators: %d: year */
				'label'  => $year ? sprintf( __( 'Raised in %d', 'onf-core' ), $year ) : __( 'Raised all-time', 'onf-core' ),
				'goal'   => 0.0,
				'event'  => 0,
			);

		case 'event':
			$event = $pick( 'event', 'onf_event' );
			if ( ! $event ) {
				return null;
			}
			return array(
				'kind'   => 'event',
				'filter' => array( 'event_ids' => array( $event ) ),
				/* translators: %s: event name */
				'label'  => sprintf( __( 'Raised for the %s', 'onf-core' ), get_the_title( $event ) ),
				'goal'   => (float) get_post_meta( $event, 'goal', true ),
				'event'  => $event,
			);

		case 'fund':
		case 'funds':
			$funds = 'fund' === $source ? array( $pick( 'fund', 'onf_fund' ) ) : array_map( 'intval', (array) ( $a['funds'] ?? array() ) );
			$funds = array_values( array_filter( $funds ) );
			if ( ! $funds ) {
				return null;
			}
			$names = implode( ' + ', array_map( 'get_the_title', $funds ) );
			$event = 0;
			if ( 'current' === $scope && 'fund' === $source ) {
				$event = onf_series_current_event( (int) get_post_meta( $funds[0], 'series', true ) );
			} elseif ( 'event' === $scope ) {
				$event = (int) ( $a['event'] ?? 0 );
			} elseif ( 'current' === $scope ) {
				// Chosen funds: the chosen event, else the open event of the first fund's series.
				$event = (int) ( $a['event'] ?? 0 );
				foreach ( $funds as $fund_id ) {
					$event = $event ? $event : onf_series_current_event( (int) get_post_meta( $fund_id, 'series', true ) );
				}
			}
			$filter = array_filter(
				array(
					'fund_ids'  => $funds,
					'event_ids' => $event ? array( $event ) : null,
					'year'      => $year,
				)
			);
			if ( $event ) {
				/* translators: 1: fund names, 2: event name */
				$label = sprintf( __( '%1$s · %2$s', 'onf-core' ), $names, get_the_title( $event ) );
			} elseif ( $year ) {
				/* translators: 1: fund names, 2: year */
				$label = sprintf( __( '%1$s · %2$d', 'onf-core' ), $names, $year );
			} else {
				/* translators: %s: fund names */
				$label = sprintf( __( '%s · all-time', 'onf-core' ), $names );
			}
			$goal = 'fund' === $source ? (float) get_post_meta( $funds[0], 'goal', true ) : 0.0;
			return array(
				'kind'   => 'funds',
				'filter' => $filter,
				'label'  => $label,
				'goal'   => $goal,
				'event'  => $event,
			);

		case 'series':
			$series = (int) ( $a['series'] ?? 0 ) ? (int) $a['series'] : onf_block_series_context();
			if ( ! $series ) {
				return null;
			}
			$term = get_term( $series, 'onf_series' );
			$name = $term && ! is_wp_error( $term ) ? $term->name : '';
			if ( 'current' === $scope ) {
				$event = onf_series_focus_event( $series );
				return $event ? array(
					'kind'   => 'series',
					'filter' => array( 'event_ids' => array( $event ) ),
					/* translators: %s: event name */
					'label'  => sprintf( __( 'Raised for the %s', 'onf-core' ), get_the_title( $event ) ),
					'goal'   => (float) get_post_meta( $event, 'goal', true ),
					'event'  => $event,
				) : null;
			}
			return array(
				'kind'   => 'series',
				'filter' => array( 'event_ids' => onf_series_event_ids( $series, $year ) ),
				/* translators: 1: series name, 2: year */
				'label'  => $year ? sprintf( __( '%1$s %2$d', 'onf-core' ), $name, $year ) : sprintf( __( '%s · all years', 'onf-core' ), $name ),
				'goal'   => 0.0,
				'event'  => 0,
			);
	}
	return null;
}

/*
 * ------------------------------------------------------------------------------------------------
 * Donate.
 * ------------------------------------------------------------------------------------------------
 */

function onf_render_donate_block( $a, $content, $block ) {
	$post   = onf_block_post_id( $block );
	$type   = $post ? get_post_type( $post ) : '';
	$player = (int) $a['player'] ? (int) $a['player'] : ( 'player' === $type ? $post : 0 );
	$event  = (int) $a['event'] ? (int) $a['event'] : ( 'onf_event' === $type && ! $player ? $post : 0 );
	$fund   = (int) $a['fund'] ? (int) $a['fund'] : ( 'onf_fund' === $type && ! $player ? $post : 0 );
	$target = $player ? $player : ( $event ? $event : $fund );

	if ( $player && ! onf_player_donations_onf() ) {
		return onf_block_note( __( 'Donate: hidden on player pages until Gifts → Settings → Player-page donations is set to ONF (the old GiveWP form shows until then).', 'onf-core' ) );
	}
	if ( $fund && ! $player && ! get_post_meta( $fund, 'active', true ) ) {
		return onf_block_note( __( 'Donate: this fund is not accepting gifts (tick "Accepting gifts" on the fund).', 'onf-core' ) );
	}

	if ( 'button' === $a['mode'] ) {
		$label = '' !== trim( $a['label'] ) ? $a['label'] : __( 'Donate', 'onf-core' );
		if ( $target && $target === $post ) {
			$href = '#onf-donate';
		} elseif ( $target ) {
			$href = get_permalink( $target ) . '#onf-donate';
		} else {
			$href = onf_donate_page_url();
		}
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'wp-block-buttons onf-donate-button' ) ) . '><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></div></div>';
	}

	if ( ! $target ) {
		return onf_block_note( __( 'Donate form: shows on a player, event or fund page, or pick one in the block settings. Elsewhere use the button style, which goes to the Donate page.', 'onf-core' ) );
	}

	$heading = trim( $a['heading'] );
	if ( '' === $heading ) {
		/* translators: %s: first name or event/fund name */
		$heading = $player ? sprintf( __( 'Support %s', 'onf-core' ), onf_first_name( $player ) ) : sprintf( __( 'Give to the %s', 'onf-core' ), get_the_title( $target ) );
	}
	$for = '';
	if ( $player ) {
		$current = (int) $a['event'] ? (int) $a['event'] : onf_player_current_event( $player );
		/* translators: 1: first name, 2: event name */
		$for = $current ? sprintf( __( 'Your gift counts toward %1$s’s total for the %2$s.', 'onf-core' ), onf_first_name( $player ), get_the_title( $current ) ) : '';
	}
	$form = onf_donate_shortcode(
		array(
			'player' => $player,
			'event'  => $player ? (int) $a['event'] : $event,
			'fund'   => $player ? 0 : $fund,
		)
	);
	$html = '<h2 class="onf-donate-block__heading">' . esc_html( $heading ) . '</h2>'
		. ( $for ? '<p class="onf-donate-block__for">' . esc_html( $for ) . '</p>' : '' )
		. $form;
	return onf_block_wrap( 'onf-donate-block', $html );
}

/*
 * ------------------------------------------------------------------------------------------------
 * Progress / totals.
 * ------------------------------------------------------------------------------------------------
 */

function onf_render_progress_block( $a, $content, $block ) {
	$t = onf_block_target( $a, $block );
	if ( ! $t ) {
		return onf_block_note( __( 'Progress: shows on a player, event, fund or series page — or choose what to total in the block settings.', 'onf-core' ) );
	}
	$sum   = onf_sum( $t['filter'] );
	$goal  = (float) $a['goal'] > 0 ? (float) $a['goal'] : $t['goal'];
	$label = '' !== trim( $a['label'] ) ? $a['label'] : $t['label'];

	$html  = '<p class="onf-progress__label">' . esc_html( $label ) . '</p>';
	$html .= '<p class="onf-progress__amount"><strong>' . esc_html( onf_display_money( $sum['total'] ) ) . '</strong>';
	if ( $a['showGoal'] && $goal > 0 ) {
		/* translators: %s: goal */
		$html .= ' <span>' . esc_html( sprintf( __( 'of %s goal', 'onf-core' ), onf_display_money( $goal ) ) ) . '</span>';
	}
	$html .= '</p>';
	if ( $a['showGoal'] ) {
		$html .= onf_progress_bar( $sum['total'], $goal );
	}
	if ( $a['showCounts'] && $sum['gifts'] ) {
		/* translators: 1: gifts, 2: donors */
		$html .= '<p class="onf-progress__counts">' . esc_html( sprintf( _n( '%1$s gift', '%1$s gifts', $sum['gifts'], 'onf-core' ), number_format( $sum['gifts'] ) ) . ' · ' . sprintf( _n( '%s donor', '%s donors', $sum['donors'], 'onf-core' ), number_format( $sum['donors'] ) ) ) . '</p>';
	}
	if ( $a['breakdown'] ) {
		$parts = onf_sum_breakdown( $t['filter'] );
		if ( count( $parts ) > 1 ) {
			$html .= '<ul class="onf-progress__breakdown">';
			foreach ( $parts as $name => $amount ) {
				$html .= '<li><span>' . esc_html( $name ) . '</span><span>' . esc_html( onf_display_money( $amount ) ) . '</span></li>';
			}
			$html .= '</ul>';
		}
	}
	return onf_block_wrap( 'onf-progress', $html );
}

/*
 * ------------------------------------------------------------------------------------------------
 * Donor board.
 * ------------------------------------------------------------------------------------------------
 */

function onf_render_donor_board_block( $a, $content, $block ) {
	$t = onf_block_target( $a, $block );
	if ( ! $t ) {
		return onf_block_note( __( 'Donor board: shows on a player, event, fund or series page — or choose whose supporters to show in the block settings.', 'onf-core' ) );
	}
	$rows    = onf_gift_list( $t['filter'], $a['order'], 500 );
	$heading = trim( $a['heading'] );
	$post    = onf_block_post_id( $block );
	$html    = '';
	if ( '' !== $heading ) {
		$html .= '<h2 class="onf-donors__heading">' . esc_html( $heading ) . ( $rows ? ' <span class="onf-donors__count">' . esc_html( number_format( count( $rows ) ) . ( 500 === count( $rows ) ? '+' : '' ) ) . '</span>' : '' ) . '</h2>';
	}
	if ( $t['event'] && 'player' === $t['kind'] ) {
		$html .= '<p class="onf-donors__scope">' . esc_html( get_the_title( $t['event'] ) ) . '</p>';
	}
	if ( ! $rows ) {
		if ( onf_event_is_open( (int) $t['event'] ) || 'onf_fund' === get_post_type( $post ) ) {
			$html .= '<p class="onf-donors__empty">' . esc_html__( 'No gifts yet. Be the first to give!', 'onf-core' ) . '</p>';
			return onf_block_wrap( 'onf-donors', $html );
		}
		return onf_block_note( __( 'Donor board: no gifts here yet (nothing shows on the site).', 'onf-core' ) );
	}

	$show_player = 'player' !== $t['kind'];
	$item        = static function ( $row ) use ( $a, $show_player ) {
		$out  = '<li class="onf-donor"><div class="onf-donor__top"><span class="onf-donor__name">' . esc_html( $row->name ) . '</span>';
		$out .= $a['showAmounts'] ? '<span class="onf-donor__amount">' . esc_html( onf_display_money( $row->amount, false ) ) . '</span>' : '';
		$out .= '</div>';
		if ( $a['showMessages'] && '' !== trim( (string) $row->message ) ) {
			$out .= '<p class="onf-donor__message">' . esc_html( wp_trim_words( $row->message, 60 ) ) . '</p>';
		}
		$meta = array( wp_date( 'M j, Y', strtotime( $row->gift_date ), new DateTimeZone( 'UTC' ) ) );
		if ( $show_player && $row->player_id ) {
			/* translators: %s: player name */
			$meta[] = sprintf( __( 'for %s', 'onf-core' ), get_the_title( (int) $row->player_id ) );
		}
		return $out . '<p class="onf-donor__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p></li>';
	};
	$limit = max( 1, (int) $a['limit'] );
	$html .= '<ol class="onf-donors__list">' . implode( '', array_map( $item, array_slice( $rows, 0, $limit ) ) ) . '</ol>';
	if ( count( $rows ) > $limit ) {
		/* translators: %s: number of supporters */
		$html .= '<details class="onf-donors__more"><summary>' . esc_html( sprintf( __( 'Show all %s supporters', 'onf-core' ), number_format( count( $rows ) ) ) ) . '</summary>';
		$html .= '<ol class="onf-donors__list" start="' . ( $limit + 1 ) . '">' . implode( '', array_map( $item, array_slice( $rows, $limit ) ) ) . '</ol></details>';
	}
	return onf_block_wrap( 'onf-donors', $html );
}

/*
 * ------------------------------------------------------------------------------------------------
 * Leaderboard.
 * ------------------------------------------------------------------------------------------------
 */

function onf_render_leaderboard_block( $a, $content, $block ) {
	$post  = onf_block_post_id( $block );
	$event = (int) $a['event'];
	if ( ! $event && 'onf_event' === get_post_type( $post ) ) {
		$event = $post;
	}
	if ( ! $event ) {
		// The soonest event fundraising now (else open for registration) that has players.
		foreach ( array_merge( onf_query_events( array( 'fundraising' ) ), onf_query_events( array( 'registration' ) ) ) as $open ) {
			if ( onf_get_event_entries( $open ) ) {
				$event = $open;
				break;
			}
		}
	}
	if ( ! $event ) {
		return onf_block_note( __( 'Leaderboard: no event is open right now — pick an event in the block settings.', 'onf-core' ) );
	}
	$rows = onf_event_leaderboard( $event );
	if ( ! $rows ) {
		return onf_block_note( __( 'Leaderboard: no players in this event yet.', 'onf-core' ) );
	}
	$limit = (int) $a['limit'];
	$shown = $limit > 0 ? array_slice( $rows, 0, $limit ) : $rows;
	$grid  = 'grid' === $a['layout'];

	$html = '';
	if ( '' !== trim( $a['heading'] ) ) {
		$html .= '<h2 class="onf-leaderboard__heading">' . esc_html( $a['heading'] ) . '</h2>';
	}
	if ( 'onf_event' !== get_post_type( $post ) || $event !== $post ) {
		$html .= '<p class="onf-leaderboard__event">' . onf_maybe_link( $event ) . '</p>';
	}
	$html .= '<ol class="onf-leaderboard__list' . ( $grid ? ' is-grid' : '' ) . '">';
	foreach ( $shown as $i => $row ) {
		$thumb = get_the_post_thumbnail( $row->player_id, $grid ? 'medium' : 'thumbnail', array( 'class' => 'onf-leader__img', 'alt' => '' ) );
		if ( ! $thumb ) {
			$initials = implode( '', array_map( static fn( $w ) => mb_substr( $w, 0, 1 ), array_slice( preg_split( '/\s+/', trim( $row->name ) ), 0, 2 ) ) );
			$thumb    = '<span class="onf-leader__img onf-leader__img--initials" aria-hidden="true">' . esc_html( mb_strtoupper( $initials ) ) . '</span>';
		}
		$html .= '<li class="onf-leader">'
			. '<span class="onf-leader__rank">' . ( $i + 1 ) . '</span>'
			. $thumb
			. '<span class="onf-leader__body">' . onf_maybe_link( $row->player_id, $row->name, 'onf-leader__name' )
			. onf_progress_bar( $row->total, $row->goal )
			. '</span>'
			. '<span class="onf-leader__amount">' . esc_html( onf_display_money( $row->total ) ) . '</span>'
			. '</li>';
	}
	$html .= '</ol>';
	if ( $limit > 0 && count( $rows ) > $limit && $event !== $post ) {
		/* translators: %d: number of players */
		$html .= '<p class="onf-leaderboard__all">' . onf_maybe_link( $event, sprintf( __( 'See all %d players', 'onf-core' ), count( $rows ) ) ) . '</p>';
	}
	return onf_block_wrap( 'onf-leaderboard', $html );
}

/*
 * ------------------------------------------------------------------------------------------------
 * Foundation total.
 * ------------------------------------------------------------------------------------------------
 */

function onf_render_foundation_total_block( $a, $content, $block ) {
	$sum   = onf_sum( array() );
	$since = (int) $a['since'] ? (int) $a['since'] : onf_first_gift_year();
	if ( $sum['total'] <= 0 ) {
		return onf_block_note( __( 'Foundation total: no gifts recorded yet.', 'onf-core' ) );
	}
	if ( 'stats' !== $a['layout'] ) {
		/* translators: 1: amount, 2: year */
		$html = '<p class="onf-total__line">' . sprintf( esc_html__( '%1$s raised since %2$s', 'onf-core' ), '<strong>' . esc_html( onf_display_money( $sum['total'] ) ) . '</strong>', esc_html( (string) $since ) ) . '</p>';
		return onf_block_wrap( 'onf-total', $html );
	}
	$events = (int) onf_totals_cache(
		'event_count',
		array(),
		static function () {
			global $wpdb;
			return (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT event_id) FROM ' . onf_gifts_table() . ' WHERE ' . onf_totals_where( array() ) . ' AND event_id > 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	);
	$stats = array(
		/* translators: %d: year */
		array( onf_display_money( $sum['total'] ), sprintf( __( 'raised since %d', 'onf-core' ), $since ) ),
		array( number_format( $sum['gifts'] ), __( 'gifts', 'onf-core' ) ),
		array( number_format( $sum['donors'] ), __( 'donors', 'onf-core' ) ),
		array( number_format( $events ), __( 'events', 'onf-core' ) ),
	);
	$html = '<dl class="onf-stats">';
	foreach ( $stats as $s ) {
		$html .= '<div class="onf-stat"><dt>' . esc_html( $s[1] ) . '</dt><dd>' . esc_html( $s[0] ) . '</dd></div>';
	}
	return onf_block_wrap( 'onf-total onf-total--stats', $html . '</dl>' );
}

/*
 * ------------------------------------------------------------------------------------------------
 * Player profile.
 * ------------------------------------------------------------------------------------------------
 */

function onf_render_player_profile_block( $a, $content, $block ) {
	$player = onf_block_post_id( $block );
	if ( ! $player || 'player' !== get_post_type( $player ) ) {
		return onf_block_note( __( 'Player profile: shows the player whose page this is.', 'onf-core' ) );
	}
	$meta = static fn( $key ) => trim( (string) get_post_meta( $player, $key, true ) );
	$opts = onf_field_groups()['player']['onf_player_profile']['fields'];

	switch ( $a['part'] ) {
		case 'photo':
			$img = get_the_post_thumbnail( $player, 'large', array( 'class' => 'onf-player-photo__img' ) );
			if ( ! $img ) {
				$num = $meta( 'jersey' );
				$img = '<div class="onf-player-photo__placeholder" role="img" aria-label="' . esc_attr( get_the_title( $player ) ) . '">'
					. '<span>' . esc_html( '' !== $num ? '#' . $num : mb_substr( get_the_title( $player ), 0, 1 ) ) . '</span></div>';
			}
			return onf_block_wrap( 'onf-player-photo', $img );

		case 'eyebrow':
			$bits = array();
			if ( '' !== $meta( 'jersey' ) ) {
				$bits[] = '#' . ltrim( $meta( 'jersey' ), '#' );
			}
			if ( '' !== $meta( 'position' ) ) {
				$bits[] = $opts['position'][2][ $meta( 'position' ) ] ?? ucfirst( $meta( 'position' ) );
			}
			$current = onf_player_current_event( $player );
			if ( $current ) {
				$bits[] = get_the_title( $current );
			}
			return $bits ? onf_block_wrap( 'onf-eyebrow', esc_html( implode( ' · ', $bits ) ) ) : '';

		case 'facts':
			$facts = array();
			if ( '' !== $meta( 'shot' ) ) {
				$facts[ __( 'Shoots', 'onf-core' ) ] = $opts['shot'][2][ $meta( 'shot' ) ] ?? $meta( 'shot' );
			}
			$facts[ __( 'Hometown', 'onf-core' ) ] = $meta( 'hometown' );
			$size                                 = array_filter( array( $meta( 'height' ), '' !== $meta( 'weight' ) ? $meta( 'weight' ) . ' lbs' : '' ) );
			$facts[ __( 'Height / weight', 'onf-core' ) ] = implode( ' · ', $size );
			$facts[ __( 'Last team', 'onf-core' ) ]       = $meta( 'last_team' );
			$facts[ __( 'Favorite NHL team', 'onf-core' ) ] = $meta( 'favorite_nhl_team' );
			$facts[ __( 'Sponsored by', 'onf-core' ) ]      = $meta( 'sponsor' );
			$facts = array_filter( $facts, static fn( $v ) => '' !== $v );
			if ( ! $facts ) {
				return onf_block_note( __( 'Player profile: fill in the profile fields (right-hand side when editing the player) and they show here.', 'onf-core' ) );
			}
			$html = '<dl class="onf-facts">';
			foreach ( $facts as $label => $value ) {
				$html .= '<div class="onf-fact"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
			}
			return onf_block_wrap( 'onf-player-facts', $html . '</dl>' );

		case 'events':
			$entries = onf_get_player_entries( $player );
			if ( ! $entries ) {
				return onf_block_note( __( 'Player events: this player isn’t in any events yet.', 'onf-core' ) );
			}
			usort( $entries, static fn( $x, $y ) => onf_event_year( (int) $y->event_id ) <=> onf_event_year( (int) $x->event_id ) );
			$html = '<h2 class="onf-player-events__heading">' . esc_html__( 'Events', 'onf-core' ) . '</h2><ul class="onf-player-events__list">';
			foreach ( $entries as $entry ) {
				$raised = onf_sum(
					array(
						'player_id' => $player,
						'event_ids' => array( (int) $entry->event_id ),
					)
				)['total'];
				$html  .= '<li><span class="onf-player-events__name">' . onf_maybe_link( (int) $entry->event_id ) . '</span>'
					. '<span class="onf-player-events__raised">' . ( $raised > 0 ? esc_html( onf_display_money( $raised ) ) : '—' ) . '</span></li>';
			}
			$all   = onf_sum( array( 'player_id' => $player ) )['total'];
			$html .= '</ul>';
			if ( $all > 0 ) {
				$html .= '<p class="onf-player-events__total"><span>' . esc_html__( 'All-time', 'onf-core' ) . '</span><strong>' . esc_html( onf_display_money( $all ) ) . '</strong></p>';
			}
			return onf_block_wrap( 'onf-player-events', $html );

		case 'stats':
			$ep = absint( $meta( 'ep_id' ) );
			if ( ! $ep ) {
				return onf_block_note( __( 'Stats: add the EliteProspects ID in the player profile to show stats here.', 'onf-core' ) );
			}
			$src = 'https://www.eliteprospects.com/iframe_player_stats_small.php?player=' . $ep;
			return onf_block_wrap(
				'onf-player-stats',
				'<h2 class="onf-player-stats__heading">' . esc_html__( 'Stats', 'onf-core' ) . '</h2>'
				. '<iframe class="onf-embed onf-embed--stats" src="' . esc_url( $src ) . '" loading="lazy" title="' . esc_attr__( 'Player statistics from EliteProspects', 'onf-core' ) . '" referrerpolicy="no-referrer-when-downgrade"></iframe>'
			);

		case 'alltime':
			$all = onf_sum( array( 'player_id' => $player ) )['total'];
			if ( $all <= 0 ) {
				return '';
			}
			/* translators: %s: amount */
			return onf_block_wrap( 'onf-alltime', sprintf( esc_html__( '%s raised all-time', 'onf-core' ), '<strong>' . esc_html( onf_display_money( $all ) ) . '</strong>' ) );
	}
	return '';
}

/*
 * ------------------------------------------------------------------------------------------------
 * Event details.
 * ------------------------------------------------------------------------------------------------
 */

function onf_render_event_details_block( $a, $content, $block ) {
	$post  = onf_block_post_id( $block );
	$event = (int) $a['event'] ? (int) $a['event'] : ( 'onf_event' === get_post_type( $post ) ? $post : 0 );
	if ( ! $event ) {
		return onf_block_note( __( 'Event details: shows on an event page, or pick an event in the block settings.', 'onf-core' ) );
	}
	$part = $a['part'];
	$html = '';
	if ( in_array( $part, array( 'all', 'eyebrow' ), true ) ) {
		$series = onf_event_series_id( $event );
		$bits   = array_filter( array( $series ? get_term( $series )->name : '', onf_event_status_label( $event ) ) );
		$html  .= $bits ? '<p class="onf-eyebrow">' . esc_html( implode( ' · ', $bits ) ) . '</p>' : '';
	}
	if ( in_array( $part, array( 'all', 'meta' ), true ) ) {
		$venue = trim( (string) get_post_meta( $event, 'venue', true ) );
		$html .= '<p class="onf-event-meta"><span class="onf-event-meta__dates">' . esc_html( onf_event_dates( $event ) ) . '</span>'
			. ( '' !== $venue ? '<span class="onf-event-meta__venue">' . esc_html( $venue ) . '</span>' : '' ) . '</p>';
	}
	if ( in_array( $part, array( 'all', 'buttons' ), true ) ) {
		$buttons = '';
		$status  = (string) get_post_meta( $event, 'status', true );
		$reg     = (string) get_post_meta( $event, 'registration_url', true );
		if ( 'registration' === $status && $reg ) {
			$buttons .= '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $reg ) . '">' . esc_html__( 'Register', 'onf-core' ) . '</a></div>';
		}
		if ( onf_event_is_open( $event ) ) {
			$style    = $buttons ? ' is-style-on-dark' : '';
			$href     = $event === $post ? '#onf-donate' : get_permalink( $event ) . '#onf-donate';
			$buttons .= '<div class="wp-block-button' . $style . '"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $href ) . '">' . esc_html__( 'Donate', 'onf-core' ) . '</a></div>';
		}
		$html .= $buttons ? '<div class="wp-block-buttons onf-event-buttons">' . $buttons . '</div>' : '';
	}
	return '' !== $html ? onf_block_wrap( 'onf-event-details', $html ) : '';
}

/*
 * ------------------------------------------------------------------------------------------------
 * Event & fund cards.
 * ------------------------------------------------------------------------------------------------
 */

function onf_render_cards_block( $a, $content, $block ) {
	$series = (int) $a['series'] ? (int) $a['series'] : onf_block_series_context();
	if ( 'funds' === $a['source'] ) {
		$ids = array_values(
			array_filter(
				array_map(
					'intval',
					get_posts(
						array(
							'post_type'      => 'onf_fund',
							'post_status'    => 'publish',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
							'fields'         => 'ids',
						)
					)
				),
				static fn( $id ) => (bool) get_post_meta( $id, 'active', true )
			)
		);
	} elseif ( 'past' === $a['source'] ) {
		$ids = onf_query_events( array( 'closed', '' ), $series );
	} else {
		$ids = onf_public_open_events( $series );
	}
	if ( (int) $a['limit'] > 0 ) {
		$ids = array_slice( $ids, 0, (int) $a['limit'] );
	}
	if ( ! $ids ) {
		$what = array(
			'active' => __( 'Cards: no published events are open right now (status Registration open or Fundraising).', 'onf-core' ),
			'past'   => __( 'Cards: no published past events yet (publish closed events to list them here).', 'onf-core' ),
			'funds'  => __( 'Cards: no published funds are accepting gifts.', 'onf-core' ),
		);
		return onf_block_note( $what[ $a['source'] ] ?? '' );
	}

	$html = '' !== trim( $a['heading'] ) ? '<h2 class="onf-cards__heading">' . esc_html( $a['heading'] ) . '</h2>' : '';
	$html .= '<ul class="onf-cards__list">';
	foreach ( $ids as $id ) {
		$is_fund = 'onf_fund' === get_post_type( $id );
		$url     = get_permalink( $id );
		$img     = get_the_post_thumbnail( $id, 'medium_large', array( 'alt' => '' ) );
		$media   = $img ? $img : '<span class="onf-card__placeholder"><img src="' . esc_url( ONF_CORE_URL . 'assets/onf-mark-white.svg' ) . '" alt=""></span>';
		$html   .= '<li class="onf-card"><a class="onf-card__media" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true">' . $media . '</a><div class="onf-card__body">';

		if ( $is_fund ) {
			$event  = onf_series_current_event( (int) get_post_meta( $id, 'series', true ) );
			$filter = array_filter(
				array(
					'fund_ids'  => array( $id ),
					'event_ids' => $event ? array( $event ) : null,
				)
			);
			$sum    = onf_sum( $filter )['total'];
			$goal   = (float) get_post_meta( $id, 'goal', true );
			$html  .= '<p class="onf-card__eyebrow">' . esc_html( $event ? get_the_title( $event ) : __( 'Fund', 'onf-core' ) ) . '</p>';
			$html  .= '<h3 class="onf-card__title"><a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3>';
			$excerpt = get_the_excerpt( $id );
			$html  .= $excerpt ? '<p class="onf-card__meta">' . esc_html( wp_trim_words( $excerpt, 20 ) ) . '</p>' : '';
			$html  .= $sum > 0 ? '<p class="onf-card__raised"><strong>' . esc_html( onf_display_money( $sum ) ) . '</strong> ' . esc_html__( 'raised', 'onf-core' ) . '</p>' . onf_progress_bar( $sum, $goal ) : '';
			$html  .= '<p class="onf-card__cta"><a class="onf-card__button" href="' . esc_url( $url . '#onf-donate' ) . '">' . esc_html__( 'Give', 'onf-core' ) . '</a></p>';
		} else {
			$open   = onf_event_is_open( $id );
			$sum    = onf_sum( array( 'event_ids' => array( $id ) ) );
			$goal   = (float) get_post_meta( $id, 'goal', true );
			$players   = count( onf_get_event_entries( $id ) );
			/* translators: %s: number of players */
			$eyebrow   = $open ? onf_event_status_label( $id ) : ( $players ? sprintf( _n( '%s player', '%s players', $players, 'onf-core' ), number_format( $players ) ) : '' );
			$html  .= $eyebrow ? '<p class="onf-card__eyebrow">' . esc_html( $eyebrow ) . '</p>' : '';
			$html  .= '<h3 class="onf-card__title"><a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3>';
			$venue  = trim( (string) get_post_meta( $id, 'venue', true ) );
			$html  .= '<p class="onf-card__meta">' . esc_html( implode( ' · ', array_filter( array( onf_event_dates( $id ), $venue ) ) ) ) . '</p>';
			if ( $sum['total'] > 0 ) {
				$html .= '<p class="onf-card__raised"><strong>' . esc_html( onf_display_money( $sum['total'] ) ) . '</strong> ' . esc_html__( 'raised', 'onf-core' );
				$html .= $sum['donors'] ? ' <span>· ' . esc_html( sprintf( /* translators: %s: donors */ _n( '%s donor', '%s donors', $sum['donors'], 'onf-core' ), number_format( $sum['donors'] ) ) ) . '</span>' : '';
				$html .= '</p>';
			}
			if ( $open ) {
				$html .= $sum['total'] > 0 ? onf_progress_bar( $sum['total'], $goal ) : '';
				$reg   = 'registration' === get_post_meta( $id, 'status', true ) ? (string) get_post_meta( $id, 'registration_url', true ) : '';
				$html .= $reg
					? '<p class="onf-card__cta"><a class="onf-card__button" href="' . esc_url( $reg ) . '">' . esc_html__( 'Register', 'onf-core' ) . '</a></p>'
					: '<p class="onf-card__cta"><a class="onf-card__button" href="' . esc_url( $url . '#onf-donate' ) . '">' . esc_html__( 'Donate', 'onf-core' ) . '</a></p>';
			}
		}
		$html .= '</div></li>';
	}
	$extra = ! empty( $a['anchor'] ) ? array( 'id' => sanitize_html_class( $a['anchor'] ) ) : array();
	return onf_block_wrap( 'onf-cards onf-cards--' . sanitize_html_class( $a['source'] ), $html . '</ul>', $extra );
}

/*
 * ------------------------------------------------------------------------------------------------
 * Series totals.
 * ------------------------------------------------------------------------------------------------
 */

function onf_render_series_totals_block( $a, $content, $block ) {
	$series = (int) $a['series'] ? (int) $a['series'] : onf_block_series_context();
	if ( ! $series ) {
		return onf_block_note( __( 'Series totals: shows on a series page, or pick a series in the block settings.', 'onf-core' ) );
	}
	$grid = onf_series_grid_data( $series );
	if ( ! $grid['cols'] ) {
		return onf_block_note( __( 'Series totals: no gifts in this series yet.', 'onf-core' ) );
	}
	$years = array_count_values( wp_list_pluck( $grid['rows'], 'year' ) );
	$down  = array();
	$html  = '<table class="onf-series-table"><thead><tr><th scope="col">' . esc_html__( 'Year', 'onf-core' ) . '</th>';
	foreach ( $grid['cols'] as $label ) {
		$html .= '<th scope="col">' . esc_html( $label ) . '</th>';
	}
	$html .= '<th scope="col">' . esc_html__( 'Total', 'onf-core' ) . '</th></tr></thead><tbody>';
	foreach ( array_reverse( $grid['rows'], true ) as $event_id => $row ) {
		$label = $years[ $row['year'] ] > 1 ? $row['title'] : (string) $row['year'];
		$html .= '<tr><th scope="row">' . onf_maybe_link( (int) $event_id, $label ) . '</th>';
		foreach ( array_keys( $grid['cols'] ) as $col ) {
			$v            = $row['cells'][ $col ] ?? 0;
			$down[ $col ] = ( $down[ $col ] ?? 0 ) + $v;
			$html        .= '<td>' . esc_html( $v ? onf_display_money( $v ) : '—' ) . '</td>';
		}
		$html .= '<td><strong>' . esc_html( onf_display_money( array_sum( $row['cells'] ) ) ) . '</strong></td></tr>';
	}
	$html .= '</tbody><tfoot><tr><th scope="row">' . esc_html__( 'All years', 'onf-core' ) . '</th>';
	foreach ( array_keys( $grid['cols'] ) as $col ) {
		$html .= '<td>' . esc_html( onf_display_money( $down[ $col ] ?? 0 ) ) . '</td>';
	}
	$html .= '<td><strong>' . esc_html( onf_display_money( array_sum( $down ) ) ) . '</strong></td></tr></tfoot></table>';
	return onf_block_wrap( 'onf-series-totals', $html );
}

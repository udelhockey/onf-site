<?php
/**
 * Event series: the same event every year (Herb Mitchell Cup, Info Solutions Team Holiday Giving Fund, …).
 * Each yearly event belongs to one series, so totals add up across years.
 * A fund can belong to a series too (Dolan Fund → holiday giving): gifts to the fund then count toward
 * that year's event automatically, with no new form or fund each year.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		register_taxonomy(
			'onf_series',
			array( 'onf_event' ),
			array(
				'labels'            => array(
					'name'          => __( 'Event series', 'onf-core' ),
					'singular_name' => __( 'Event series', 'onf-core' ),
					'menu_name'     => __( 'Series', 'onf-core' ),
					'all_items'     => __( 'All series', 'onf-core' ),
					'edit_item'     => __( 'Edit series', 'onf-core' ),
					'add_new_item'  => __( 'Add new series', 'onf-core' ),
				),
				'public'            => true,
				'hierarchical'      => true, // Checkbox UI; series themselves stay flat.
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'       => 'series',
					'with_front' => false,
				),
			)
		);
	},
	9 // Before fields.php reads the series list.
);

/**
 * Series name from an event title: "2025 Herb Mitchell Cup" → "Herb Mitchell Cup",
 * "2022 Pre-Draft Showcase – Junior A" → "Pre-Draft Showcase".
 */
function onf_series_name_from_title( $title ) {
	$name = preg_replace( '/^\s*(19|20)\d{2}\s*/', '', (string) $title );
	$name = preg_replace( '/\s+[–—-]\s+.*$/u', '', $name );
	return trim( $name );
}

/**
 * The year an event belongs to: its start date, else a year at the start of its title, else its post date.
 */
function onf_event_year( int $event_id ) {
	$start = (string) get_post_meta( $event_id, 'start_date', true );
	if ( preg_match( '/^(\d{4})-/', $start, $m ) ) {
		return (int) $m[1];
	}
	if ( preg_match( '/^\s*((19|20)\d{2})\b/', get_the_title( $event_id ), $m ) ) {
		return (int) $m[1];
	}
	return (int) get_the_date( 'Y', $event_id );
}

function onf_event_series_id( int $event_id ) {
	$terms = get_the_terms( $event_id, 'onf_series' );
	return ( $terms && ! is_wp_error( $terms ) ) ? (int) $terms[0]->term_id : 0;
}

/**
 * Series options for selects: term_id => name.
 */
function onf_series_options() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'onf_series',
			'hide_empty' => false,
		)
	);
	return is_wp_error( $terms ) ? array() : wp_list_pluck( $terms, 'name', 'term_id' );
}

/**
 * The series' event that is open now (Fundraising, else Registration), newest first.
 */
function onf_series_current_event( int $term_id ) {
	if ( ! $term_id ) {
		return 0;
	}
	foreach ( array( 'fundraising', 'registration' ) as $status ) {
		$events = get_posts(
			array(
				'post_type'      => 'onf_event',
				'post_status'    => array( 'publish', 'draft', 'future' ),
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'onf_series',
						'terms'    => $term_id,
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => 'status',
						'value' => $status,
					),
				),
			)
		);
		if ( $events ) {
			return (int) $events[0];
		}
	}
	return 0;
}

/**
 * Put an event in the series named after it (created if new). Does nothing if it already has one.
 *
 * @return int Series term ID (0 if none).
 */
function onf_assign_series_from_title( int $event_id ) {
	$current = onf_event_series_id( $event_id );
	if ( $current ) {
		return $current;
	}
	$name = onf_series_name_from_title( get_the_title( $event_id ) );
	if ( '' === $name ) {
		return 0;
	}
	$term = term_exists( $name, 'onf_series' );
	if ( ! $term ) {
		$term = wp_insert_term( $name, 'onf_series' );
	}
	if ( is_wp_error( $term ) ) {
		return 0;
	}
	wp_set_object_terms( $event_id, array( (int) $term['term_id'] ), 'onf_series' );
	return (int) $term['term_id'];
}

// A new event is put in its series automatically ("2027 Herb Mitchell Cup" → Herb Mitchell Cup).
add_action(
	'save_post_onf_event',
	static function ( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status || '' === trim( $post->post_title ) ) {
			return;
		}
		onf_assign_series_from_title( (int) $post_id );
	},
	20,
	2
);

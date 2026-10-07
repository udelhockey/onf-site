<?php
/**
 * Fields for players, events and funds, stored as post meta.
 * Player meta keys match the old ACF field names, so existing values carry over untouched.
 * Private fields are never exposed through the REST API.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions per post type, grouped into meta boxes.
 * Field: key => [ label, type, (options) ]. Types: text, number, date, email, tel, textarea, select, checkbox.
 */
function onf_field_groups() {
	return array(
		'player'    => array(
			'onf_player_profile' => array(
				'title'   => __( 'Player profile', 'onf-core' ),
				'private' => false,
				'fields'  => array(
					'position'          => array( __( 'Position', 'onf-core' ), 'select', array( 'forward' => 'Forward', 'defenseman' => 'Defenseman', 'goaltender' => 'Goaltender' ) ),
					'jersey'            => array( __( 'Jersey number', 'onf-core' ), 'text' ),
					'shot'              => array( __( 'Shot', 'onf-core' ), 'select', array( 'left' => 'Left', 'right' => 'Right' ) ),
					'hometown'          => array( __( 'Hometown (City, ST)', 'onf-core' ), 'text' ),
					'height'            => array( __( "Height (e.g. 5'10\")", 'onf-core' ), 'text' ),
					'weight'            => array( __( 'Weight (lbs)', 'onf-core' ), 'number' ),
					'last_team'         => array( __( 'Last team', 'onf-core' ), 'text' ),
					'favorite_nhl_team' => array( __( 'Favorite NHL team', 'onf-core' ), 'text' ),
					'sponsor'           => array( __( 'Sponsor / company', 'onf-core' ), 'text' ),
					'ep_id'             => array( __( 'EliteProspects ID (the number in their EliteProspects address)', 'onf-core' ), 'number' ),
				),
			),
			'onf_player_private' => array(
				'title'   => __( 'Private (not shown on the site)', 'onf-core' ),
				'private' => true,
				'fields'  => array(
					'email'    => array( __( 'Email', 'onf-core' ), 'email' ),
					'phone'    => array( __( 'Phone', 'onf-core' ), 'tel' ),
					'birthday' => array( __( 'Birthday', 'onf-core' ), 'text' ),
					'notes'    => array( __( 'Notes', 'onf-core' ), 'textarea' ),
				),
			),
		),
		'onf_event' => array(
			'onf_event_details' => array(
				'title'   => __( 'Event details', 'onf-core' ),
				'private' => false,
				'fields'  => array(
					'status'      => array( __( 'Status', 'onf-core' ), 'select', onf_event_statuses() ),
					'start_date'  => array( __( 'Start date', 'onf-core' ), 'date' ),
					'end_date'    => array( __( 'End date', 'onf-core' ), 'date' ),
					'venue'       => array( __( 'Venue', 'onf-core' ), 'text' ),
					'goal'        => array( __( 'Event goal ($)', 'onf-core' ), 'number' ),
					'player_goal' => array( __( 'Default player goal ($)', 'onf-core' ), 'number' ),
					'registration_url' => array( __( 'Registration link (shows a Register button while registration is open)', 'onf-core' ), 'url' ),
				),
			),
		),
		'onf_fund'  => array(
			'onf_fund_details' => array(
				'title'   => __( 'Fund details', 'onf-core' ),
				'private' => false,
				'fields'  => array(
					'goal'   => array( __( 'Goal ($)', 'onf-core' ), 'number' ),
					'active' => array( __( 'Accepting gifts', 'onf-core' ), 'checkbox' ),
					'series' => array( __( 'Event series (gifts count toward that year\'s event)', 'onf-core' ), 'select', onf_series_options() ),
				),
			),
		),
	);
}

add_action(
	'init',
	static function () {
		foreach ( onf_field_groups() as $post_type => $groups ) {
			foreach ( $groups as $group ) {
				foreach ( $group['fields'] as $key => $field ) {
					register_post_meta(
						$post_type,
						$key,
						array(
							'type'          => 'number' === $field[1] ? 'number' : 'string',
							'single'        => true,
							'show_in_rest'  => ! $group['private'],
							'auth_callback' => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
						)
					);
				}
			}
		}
	},
	20
);

add_action(
	'add_meta_boxes',
	static function ( $post_type ) {
		$groups = onf_field_groups()[ $post_type ] ?? array();
		foreach ( $groups as $id => $group ) {
			add_meta_box(
				$id,
				$group['title'],
				static fn( $post ) => onf_render_fields( $post, $group['fields'], $id ),
				$post_type,
				'side'
			);
		}
	}
);

function onf_render_fields( WP_Post $post, array $fields, string $group_id ) {
	wp_nonce_field( 'onf_save_' . $group_id, $group_id . '_nonce' );
	foreach ( $fields as $key => $field ) {
		list( $label, $type ) = $field;
		$value = get_post_meta( $post->ID, $key, true );
		$name  = 'onf_fields[' . $key . ']';
		$id    = 'onf-field-' . $key;
		echo '<p>';
		if ( 'checkbox' === $type ) {
			printf(
				'<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
				esc_attr( $name ),
				checked( $value, '1', false ),
				esc_html( $label )
			);
			echo '</p>';
			continue;
		}
		printf( '<label for="%s"><strong>%s</strong></label><br>', esc_attr( $id ), esc_html( $label ) );
		if ( 'select' === $type ) {
			printf( '<select id="%s" name="%s" class="widefat"><option value="">—</option>', esc_attr( $id ), esc_attr( $name ) );
			foreach ( $field[2] as $option => $option_label ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $option_label ) );
			}
			echo '</select>';
		} elseif ( 'textarea' === $type ) {
			printf( '<textarea id="%s" name="%s" class="widefat" rows="3">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
		} else {
			printf(
				'<input type="%s" id="%s" name="%s" value="%s" class="widefat"%s>',
				esc_attr( $type ),
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( $value ),
				'number' === $type ? ' step="any" min="0"' : ''
			);
		}
		echo '</p>';
	}
}

add_action(
	'save_post',
	static function ( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$groups = onf_field_groups()[ $post->post_type ] ?? array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- each group's nonce is checked below.
		$input = isset( $_POST['onf_fields'] ) ? wp_unslash( (array) $_POST['onf_fields'] ) : array();

		foreach ( $groups as $group_id => $group ) {
			$nonce = $_POST[ $group_id . '_nonce' ] ?? '';
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'onf_save_' . $group_id ) ) {
				continue;
			}
			foreach ( $group['fields'] as $key => $field ) {
				$value = onf_sanitize_field( $input[ $key ] ?? '', $field );
				if ( '' === $value ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, $value );
				}
			}
		}
	},
	10,
	2
);

function onf_sanitize_field( $value, array $field ) {
	$type  = $field[1];
	$value = is_string( $value ) ? $value : '';
	switch ( $type ) {
		case 'textarea':
			return sanitize_textarea_field( $value );
		case 'email':
			return sanitize_email( $value );
		case 'url':
			return esc_url_raw( trim( $value ), array( 'http', 'https' ) );
		case 'number':
			return is_numeric( $value ) ? (string) ( 0 + $value ) : '';
		case 'date':
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		case 'checkbox':
			return '1' === $value ? '1' : '';
		case 'select':
			return array_key_exists( $value, $field[2] ) ? $value : '';
		default:
			return sanitize_text_field( $value );
	}
}

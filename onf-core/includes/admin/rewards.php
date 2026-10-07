<?php
/**
 * Top-fundraiser rewards on the event edit screen: up to five places, each a short name
 * ("ONF hoodie") and an optional picture from the Media Library. Shown quietly next to the
 * place number in the Top fundraisers list.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'add_meta_boxes_onf_event',
	static function () {
		add_meta_box( 'onf_event_rewards', __( 'Top fundraiser rewards', 'onf-core' ), 'onf_render_rewards_box', 'onf_event', 'normal' );
	}
);

add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && 'onf_event' === get_current_screen()->post_type ) {
			wp_enqueue_media();
			wp_enqueue_script( 'onf-admin-rewards', ONF_CORE_URL . 'assets/admin-rewards.js', array( 'jquery' ), ONF_CORE_VERSION, true );
		}
	}
);

function onf_render_rewards_box( WP_Post $post ) {
	wp_nonce_field( 'onf_save_rewards', 'onf_rewards_nonce' );
	$rewards = onf_event_rewards( $post->ID );
	echo '<p class="description">' . esc_html__( 'Optional. Shown as a small picture next to the place in the Top fundraisers list, with a one-line legend underneath. Leave a place blank for no reward.', 'onf-core' ) . '</p>';
	echo '<table class="widefat striped" style="max-width:640px"><tbody>';
	for ( $place = 1; $place <= ONF_REWARD_PLACES; $place++ ) {
		$r     = $rewards[ $place ] ?? array( 'label' => '', 'image' => 0 );
		$thumb = $r['image'] ? wp_get_attachment_image( $r['image'], array( 40, 40 ) ) : '';
		printf(
			'<tr><th scope="row" style="width:4em">%1$s</th><td><input type="text" class="regular-text" name="onf_rewards[%2$d][label]" value="%3$s" placeholder="%4$s" aria-label="%5$s"></td>'
			. '<td class="onf-reward-image"><input type="hidden" name="onf_rewards[%2$d][image]" value="%6$d"><span class="onf-reward-thumb">%7$s</span> '
			. '<button type="button" class="button onf-reward-pick">%8$s</button> <button type="button" class="button-link onf-reward-clear"%9$s>%10$s</button></td></tr>',
			esc_html( onf_ordinal( $place ) ),
			(int) $place,
			esc_attr( $r['label'] ),
			esc_attr( 1 === $place ? __( 'e.g. ONF hoodie', 'onf-core' ) : '' ),
			/* translators: %s: place, e.g. 1st */
			esc_attr( sprintf( __( '%s place reward', 'onf-core' ), onf_ordinal( $place ) ) ),
			(int) $r['image'],
			$thumb, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
			esc_html__( 'Picture…', 'onf-core' ),
			$r['image'] ? '' : ' hidden',
			esc_html__( 'Remove', 'onf-core' )
		);
	}
	echo '</tbody></table>';
}

add_action(
	'save_post_onf_event',
	static function ( $post_id ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['onf_rewards_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'onf_save_rewards' ) ) {
			return;
		}
		$in   = isset( $_POST['onf_rewards'] ) ? (array) wp_unslash( $_POST['onf_rewards'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$save = array();
		for ( $place = 1; $place <= ONF_REWARD_PLACES; $place++ ) {
			$label = sanitize_text_field( $in[ $place ]['label'] ?? '' );
			$image = absint( $in[ $place ]['image'] ?? 0 );
			$image = $image && wp_attachment_is_image( $image ) ? $image : 0;
			if ( '' !== $label || $image ) {
				$save[ $place ] = array(
					'label' => $label,
					'image' => $image,
				);
			}
		}
		if ( $save ) {
			update_post_meta( $post_id, '_onf_rewards', $save );
		} else {
			delete_post_meta( $post_id, '_onf_rewards' );
		}
	}
);

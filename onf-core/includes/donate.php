<?php
/**
 * Donate form: [onf_donate] (becomes a block in step 3).
 *
 * On a player page it gives to that player in their current event (the newest event they're in that is
 * Fundraising, else Registration); on an event page to the event; on a fund page to the fund.
 * Attributes override: [onf_donate player="12"] (current event added), [onf_donate player="12" event="34"], [onf_donate fund="56"].
 * The donor picks an amount here, then pays on Stripe's hosted page and comes back to a thank-you.
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'onf_donate', 'onf_donate_shortcode' );

/**
 * The event a player is fundraising for right now: their newest event that is fundraising
 * (or, failing that, open for registration).
 */
function onf_player_current_event( int $player_id ) {
	foreach ( array( 'fundraising', 'registration' ) as $status ) {
		foreach ( onf_get_player_entries( $player_id ) as $entry ) {
			if ( get_post_meta( $entry->event_id, 'status', true ) === $status ) {
				return (int) $entry->event_id;
			}
		}
	}
	return 0;
}

/**
 * Work out (and validate) what a gift is for.
 *
 * @return array player_id, event_id, fund_id — or WP_Error.
 */
function onf_donation_target( $player_id, $event_id, $fund_id ) {
	$player_id = 'player' === get_post_type( (int) $player_id ) && 'publish' === get_post_status( (int) $player_id ) ? (int) $player_id : 0;
	$event_id  = 'onf_event' === get_post_type( (int) $event_id ) ? (int) $event_id : 0;
	$fund_id   = 'onf_fund' === get_post_type( (int) $fund_id ) ? (int) $fund_id : 0;

	// No event given for a player: use the event they're fundraising in now (Fundraising, else Registration).
	// An event chosen explicitly counts even when it's closed (late gifts after an event).
	if ( $player_id && ! $event_id ) {
		$event_id = onf_player_current_event( $player_id );
	}
	// A fund in a series (Dolan Fund → holiday giving) counts toward that series' open event.
	if ( $fund_id && ! $event_id ) {
		$event_id = onf_series_current_event( (int) get_post_meta( $fund_id, 'series', true ) );
	}
	if ( $fund_id && ! get_post_meta( $fund_id, 'active', true ) ) {
		return new WP_Error( 'fund_closed', __( 'This fund is not accepting gifts right now.', 'onf-core' ) );
	}
	return array(
		'player_id' => $player_id,
		'event_id'  => $event_id,
		'fund_id'   => $fund_id,
	);
}

function onf_donate_messages() {
	return array(
		'amount'      => __( 'Please choose an amount.', 'onf-core' ),
		'min'         => sprintf( /* translators: %s: amount */ __( 'The minimum gift is %s.', 'onf-core' ), onf_money( onf_setting( 'min_amount' ) ) ),
		'max'         => __( 'For gifts over $25,000 please contact us.', 'onf-core' ),
		'name'        => __( 'Please enter your first and last name.', 'onf-core' ),
		'email'       => __( 'Please enter a valid email address for your receipt.', 'onf-core' ),
		'busy'        => __( 'Too many attempts. Please wait a few minutes and try again.', 'onf-core' ),
		'unavailable' => __( 'Online giving is unavailable right now. Please try again later.', 'onf-core' ),
		'fund_closed' => __( 'This fund is not accepting gifts right now.', 'onf-core' ),
		'stripe'      => __( 'We could not start the payment. Please try again.', 'onf-core' ),
	);
}

function onf_donate_shortcode( $atts ) {
	static $instance = 0;
	++$instance;
	$atts = shortcode_atts(
		array(
			'player' => 0,
			'event'  => 0,
			'fund'   => 0,
		),
		$atts,
		'onf_donate'
	);

	$player = (int) $atts['player'];
	$event  = (int) $atts['event'];
	$fund   = (int) $atts['fund'];
	if ( ! $player && ! $event && ! $fund && is_singular() ) {
		$id   = get_queried_object_id();
		$type = get_post_type( $id );
		if ( 'player' === $type ) {
			$player = $id; // Their current event is added by onf_donation_target().
		} elseif ( 'onf_event' === $type ) {
			$event = $id;
		} elseif ( 'onf_fund' === $type ) {
			$fund = $id;
		}
	}

	ob_start();
	echo '<div class="onf-donate" id="onf-donate' . ( $instance > 1 ? '-' . (int) $instance : '' ) . '">';

	// Coming back from Stripe (only the first form on a page handles it).
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only return from Stripe; the session is re-fetched from Stripe.
	$session_id = sanitize_text_field( wp_unslash( $_GET['onf_session'] ?? '' ) );
	if ( 1 === $instance && preg_match( '/^cs_(test|live)_[A-Za-z0-9]+$/', $session_id ) ) {
		echo onf_donate_thanks( $session_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '</div>';
		return ob_get_clean();
	}
	if ( 1 === $instance && isset( $_GET['onf_cancelled'] ) ) {
		echo '<p class="onf-donate__notice">' . esc_html__( 'Payment cancelled — you were not charged.', 'onf-core' ) . '</p>';
	}
	$error = sanitize_key( $_GET['onf_donate_error'] ?? '' );
	// phpcs:enable
	if ( 1 === $instance && isset( onf_donate_messages()[ $error ] ) ) {
		echo '<p class="onf-donate__error" role="alert">' . esc_html( onf_donate_messages()[ $error ] ) . '</p>';
	}

	if ( '' === onf_stripe_secret() ) {
		echo '<p class="onf-donate__notice">' . esc_html( onf_donate_messages()['unavailable'] ) . '</p></div>';
		return ob_get_clean();
	}

	$target = onf_donation_target( $player, $event, $fund );
	if ( is_wp_error( $target ) ) {
		echo '<p class="onf-donate__notice">' . esc_html( $target->get_error_message() ) . '</p></div>';
		return ob_get_clean();
	}

	$uid     = 'onf-donate-' . $instance;
	$return  = remove_query_arg( array( 'onf_session', 'onf_cancelled', 'onf_donate_error' ), get_permalink( get_queried_object_id() ) ? get_permalink( get_queried_object_id() ) : home_url( add_query_arg( array() ) ) );
	$amounts = onf_suggested_amounts();
	?>
	<form class="onf-donate__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="onf_donate">
		<input type="hidden" name="player" value="<?php echo (int) $target['player_id']; ?>">
		<input type="hidden" name="event" value="<?php echo (int) $target['event_id']; ?>">
		<input type="hidden" name="fund" value="<?php echo (int) $target['fund_id']; ?>">
		<input type="hidden" name="return" value="<?php echo esc_url( $return ); ?>">

		<fieldset class="onf-donate__amounts">
			<legend><?php esc_html_e( 'Choose an amount', 'onf-core' ); ?></legend>
			<?php foreach ( $amounts as $i => $amount ) : ?>
				<label class="onf-donate__amount">
					<input type="radio" name="amount_choice" value="<?php echo esc_attr( $amount ); ?>" <?php checked( 1 === $i ); ?>>
					<span><?php echo esc_html( onf_money( $amount ) ); ?></span>
				</label>
			<?php endforeach; ?>
			<label class="onf-donate__amount onf-donate__amount--other">
				<input type="radio" name="amount_choice" value="other">
				<span><?php esc_html_e( 'Other', 'onf-core' ); ?></span>
			</label>
			<label class="onf-donate__other" for="<?php echo esc_attr( $uid ); ?>-other"><?php esc_html_e( 'Other amount ($)', 'onf-core' ); ?></label>
			<input type="number" id="<?php echo esc_attr( $uid ); ?>-other" name="amount_other" min="<?php echo esc_attr( onf_setting( 'min_amount' ) ); ?>" step="1" inputmode="decimal">
		</fieldset>

		<label class="onf-donate__check">
			<input type="checkbox" name="cover_fee" value="1">
			<?php
			/* translators: 1: percent, 2: fixed fee */
			echo esc_html( sprintf( __( 'Add the card processing fee (%1$s%% + %2$s) so ONF receives my full gift', 'onf-core' ), onf_setting( 'fee_percent' ), onf_money( onf_setting( 'fee_fixed' ) ) ) );
			?>
		</label>

		<div class="onf-donate__fields">
			<p><label for="<?php echo esc_attr( $uid ); ?>-first"><?php esc_html_e( 'First name', 'onf-core' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $uid ); ?>-first" name="first_name" required autocomplete="given-name"></p>
			<p><label for="<?php echo esc_attr( $uid ); ?>-last"><?php esc_html_e( 'Last name', 'onf-core' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $uid ); ?>-last" name="last_name" required autocomplete="family-name"></p>
			<p><label for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'Email (for your receipt)', 'onf-core' ); ?></label>
				<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="email" required autocomplete="email"></p>
			<p><label for="<?php echo esc_attr( $uid ); ?>-company"><?php esc_html_e( 'Company (optional)', 'onf-core' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $uid ); ?>-company" name="company" autocomplete="organization"></p>
			<p><label for="<?php echo esc_attr( $uid ); ?>-message"><?php esc_html_e( 'Message (optional, shown on the donor board)', 'onf-core' ); ?></label>
				<textarea id="<?php echo esc_attr( $uid ); ?>-message" name="message" rows="3" maxlength="500"></textarea></p>
			<p hidden aria-hidden="true"><label>Website <input type="text" name="onf_website" tabindex="-1" autocomplete="off"></label></p>
		</div>

		<label class="onf-donate__check">
			<input type="checkbox" name="anonymous" value="1">
			<?php esc_html_e( 'Show my gift as Anonymous on the donor board', 'onf-core' ); ?>
		</label>

		<p><button type="submit" class="wp-element-button onf-donate__submit"><?php esc_html_e( 'Continue to secure payment', 'onf-core' ); ?></button></p>
		<p class="onf-donate__small"><?php esc_html_e( 'You will pay on Stripe’s secure page (card, Apple Pay, Google Pay). We never see your card number.', 'onf-core' ); ?></p>
	</form>
	<?php
	echo '</div>';
	return ob_get_clean();
}

/**
 * Thank-you after Stripe. Re-reads the session from Stripe (never trusts the URL) and records the gift
 * if the webhook hasn't yet.
 */
function onf_donate_thanks( $session_id ) {
	$session = onf_stripe_request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
	if ( is_wp_error( $session ) || ( $session['metadata']['onf_site'] ?? '' ) !== onf_site_host() ) {
		return '<p class="onf-donate__notice">' . esc_html__( 'Thank you! We could not look up your payment just now, but if it went through you will receive an email receipt shortly.', 'onf-core' ) . '</p>';
	}
	if ( 'paid' !== ( $session['payment_status'] ?? '' ) ) {
		return '<p class="onf-donate__notice">' . esc_html__( 'Thank you! Your payment is processing. You will receive an email receipt when it completes.', 'onf-core' ) . '</p>';
	}
	onf_record_checkout_session( $session );

	$first  = $session['metadata']['onf_first'] ?? '';
	$amount = (float) ( $session['metadata']['onf_amount'] ?? 0 );
	$email  = $session['customer_details']['email'] ?? '';
	return '<div class="onf-donate__thanks" role="status"><h2>'
		/* translators: %s: first name */
		. esc_html( $first ? sprintf( __( 'Thank you, %s!', 'onf-core' ), $first ) : __( 'Thank you!', 'onf-core' ) )
		. '</h2><p>'
		/* translators: 1: amount, 2: email */
		. esc_html( sprintf( __( 'Your gift of %1$s has been received. A receipt is on its way to %2$s.', 'onf-core' ), onf_money( $amount ), $email ) )
		. '</p></div>';
}

add_action( 'admin_post_onf_donate', 'onf_handle_donate' );
add_action( 'admin_post_nopriv_onf_donate', 'onf_handle_donate' );

function onf_handle_donate() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- public donation form; a nonce would break on cached pages. Input is validated below and payment happens on Stripe.
	$in     = wp_unslash( $_POST );
	$return = wp_validate_redirect( esc_url_raw( (string) ( $in['return'] ?? '' ) ), home_url( '/' ) );
	// phpcs:enable
	$fail = static function ( $code ) use ( $return ) {
		wp_safe_redirect( add_query_arg( 'onf_donate_error', $code, $return ) . '#onf-donate' );
		exit;
	};

	if ( ! empty( $in['onf_website'] ) ) {
		$fail( 'stripe' ); // Honeypot filled: a bot.
	}

	// At most 10 checkout attempts per visitor per 10 minutes (card-testing protection).
	$ip_key = 'onf_donate_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	$tries  = (int) get_transient( $ip_key );
	if ( $tries >= 10 ) {
		$fail( 'busy' );
	}
	set_transient( $ip_key, $tries + 1, 10 * MINUTE_IN_SECONDS );

	$choice = (string) ( $in['amount_choice'] ?? '' );
	$amount = round( (float) ( 'other' === $choice || '' === $choice ? ( $in['amount_other'] ?? 0 ) : $choice ), 2 );
	if ( $amount <= 0 ) {
		$fail( 'amount' );
	}
	if ( $amount < (float) onf_setting( 'min_amount' ) ) {
		$fail( 'min' );
	}
	if ( $amount > 25000 ) {
		$fail( 'max' );
	}

	$first = sanitize_text_field( $in['first_name'] ?? '' );
	$last  = sanitize_text_field( $in['last_name'] ?? '' );
	$email = sanitize_email( $in['email'] ?? '' );
	if ( '' === $first || '' === $last ) {
		$fail( 'name' );
	}
	if ( ! is_email( $email ) ) {
		$fail( 'email' );
	}

	$target = onf_donation_target( $in['player'] ?? 0, $in['event'] ?? 0, $in['fund'] ?? 0 );
	if ( is_wp_error( $target ) ) {
		$fail( $target->get_error_code() );
	}
	if ( '' === onf_stripe_secret() ) {
		$fail( 'unavailable' );
	}

	$session = onf_stripe_create_checkout(
		array_merge(
			$target,
			array(
				'amount'     => $amount,
				'fee'        => ! empty( $in['cover_fee'] ) ? onf_fee_for( $amount ) : 0.0,
				'first_name' => $first,
				'last_name'  => $last,
				'email'      => $email,
				'company'    => sanitize_text_field( $in['company'] ?? '' ),
				'anonymous'  => ! empty( $in['anonymous'] ),
				'message'    => sanitize_textarea_field( $in['message'] ?? '' ),
				'return_url' => $return,
			)
		)
	);
	if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
		$fail( 'stripe' );
	}

	// Stripe's own domain, so wp_redirect (not wp_safe_redirect).
	wp_redirect( $session['url'], 303 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	exit;
}

<?php
/**
 * Gifts → Settings: Stripe keys, donate form, organization/receipt details, email templates.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	static function () {
		add_submenu_page( 'onf-gifts', __( 'Gift settings', 'onf-core' ), __( 'Settings', 'onf-core' ), ONF_GIFTS_CAP, 'onf-settings', 'onf_render_settings_page' );
	},
	12
);

function onf_secret_fields() {
	return array( 'stripe_test_secret', 'stripe_test_webhook_secret', 'stripe_live_secret', 'stripe_live_webhook_secret' );
}

function onf_render_settings_page() {
	$s = onf_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Gift settings', 'onf-core' ); ?></h1>
		<?php onf_render_settings_notice(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="onf_save_settings">
			<?php wp_nonce_field( 'onf_save_settings' ); ?>

			<h2><?php esc_html_e( 'Stripe', 'onf-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Mode', 'onf-core' ); ?></th><td>
					<label><input type="radio" name="stripe_mode" value="test" <?php checked( 'test', $s['stripe_mode'] ); ?>> <?php esc_html_e( 'Test (no real charges)', 'onf-core' ); ?></label><br>
					<label><input type="radio" name="stripe_mode" value="live" <?php checked( 'live', $s['stripe_mode'] ); ?> <?php disabled( ! onf_live_allowed() ); ?>> <?php esc_html_e( 'Live', 'onf-core' ); ?></label>
					<?php if ( ! onf_live_allowed() ) : ?>
						<p class="description"><?php esc_html_e( 'Live mode only works on opennetfoundation.org. This site always uses test mode.', 'onf-core' ); ?></p>
					<?php endif; ?>
					<p><strong><?php esc_html_e( 'Active now:', 'onf-core' ); ?></strong> <?php echo esc_html( 'live' === onf_stripe_mode() ? __( 'LIVE', 'onf-core' ) : __( 'test', 'onf-core' ) ); ?></p>
				</td></tr>
				<?php
				$labels = array(
					'stripe_test_secret'         => __( 'Test secret key (sk_test_…)', 'onf-core' ),
					'stripe_test_webhook_secret' => __( 'Test webhook signing secret (whsec_…)', 'onf-core' ),
					'stripe_live_secret'         => __( 'Live secret key (sk_live_… or rk_live_…)', 'onf-core' ),
					'stripe_live_webhook_secret' => __( 'Live webhook signing secret (whsec_…)', 'onf-core' ),
				);
				foreach ( $labels as $key => $label ) :
					if ( str_contains( $key, 'live' ) && ! onf_live_allowed() ) {
						continue;
					}
					$saved = (string) $s[ $key ];
					?>
					<tr><th><label for="onf-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td>
						<input type="password" id="onf-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $saved ? __( 'Saved — leave blank to keep', 'onf-core' ) : __( 'Not set', 'onf-core' ) ); ?>">
						<?php if ( $saved ) : ?>
							<?php /* translators: %s: last 4 characters */ ?>
							<span class="description"><?php echo esc_html( sprintf( __( 'ends in …%s', 'onf-core' ), substr( $saved, -4 ) ) ); ?></span>
							<label><input type="checkbox" name="clear_<?php echo esc_attr( $key ); ?>" value="1"> <?php esc_html_e( 'Remove', 'onf-core' ); ?></label>
						<?php endif; ?>
					</td></tr>
				<?php endforeach; ?>
				<tr><th><?php esc_html_e( 'Webhook', 'onf-core' ); ?></th><td>
					<p><?php esc_html_e( 'In Stripe → Developers → Webhooks, add an endpoint with this URL:', 'onf-core' ); ?></p>
					<p><code><?php echo esc_html( rest_url( 'onf/v1/stripe-webhook' ) ); ?></code></p>
					<p><?php esc_html_e( 'Events to send: checkout.session.completed, checkout.session.async_payment_succeeded, charge.refunded. Then paste its signing secret above.', 'onf-core' ); ?></p>
				</td></tr>
			</table>

			<h2><?php esc_html_e( 'Website', 'onf-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th><label for="onf-player-donations"><?php esc_html_e( 'Player-page donations', 'onf-core' ); ?></label></th>
					<td><select id="onf-player-donations" name="player_donations">
						<option value="givewp" <?php selected( $s['player_donations'], 'givewp' ); ?>><?php esc_html_e( 'GiveWP (the old form on each player page)', 'onf-core' ); ?></option>
						<option value="onf" <?php selected( $s['player_donations'], 'onf' ); ?>><?php esc_html_e( 'ONF (our Donate block; old GiveWP forms hidden on player pages)', 'onf-core' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Switching hides or shows the old [give_form] on player pages only; the page content itself is not changed, so you can switch back at any time.', 'onf-core' ); ?></p></td></tr>
			</table>

			<h2><?php esc_html_e( 'Donate form', 'onf-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th><label for="onf-amounts"><?php esc_html_e( 'Suggested amounts ($)', 'onf-core' ); ?></label></th>
					<td><input type="text" id="onf-amounts" name="amounts" value="<?php echo esc_attr( $s['amounts'] ); ?>" class="regular-text"> <span class="description"><?php esc_html_e( 'Comma-separated. The second one is pre-selected.', 'onf-core' ); ?></span></td></tr>
				<tr><th><label for="onf-min"><?php esc_html_e( 'Minimum gift ($)', 'onf-core' ); ?></label></th>
					<td><input type="number" id="onf-min" name="min_amount" value="<?php echo esc_attr( $s['min_amount'] ); ?>" min="1" step="1" class="small-text"></td></tr>
				<tr><th><?php esc_html_e( 'Card fee (for "cover the fee")', 'onf-core' ); ?></th>
					<td><input type="number" name="fee_percent" value="<?php echo esc_attr( $s['fee_percent'] ); ?>" step="0.01" min="0" max="10" class="small-text" aria-label="<?php esc_attr_e( 'Percent', 'onf-core' ); ?>"> % +
						$<input type="number" name="fee_fixed" value="<?php echo esc_attr( $s['fee_fixed'] ); ?>" step="0.01" min="0" class="small-text" aria-label="<?php esc_attr_e( 'Fixed fee', 'onf-core' ); ?>">
						<span class="description"><?php esc_html_e( 'Match your Stripe rate (standard 2.9% + $0.30; nonprofit rates are lower).', 'onf-core' ); ?></span></td></tr>
			</table>

			<h2><?php esc_html_e( 'Organization & receipts', 'onf-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				foreach ( array(
					'org_name'    => __( 'Name', 'onf-core' ),
					'org_tagline' => __( 'Tagline', 'onf-core' ),
					'org_ein'     => __( 'Federal Non-Profit ID (EIN)', 'onf-core' ),
					'org_email'   => __( 'Contact email', 'onf-core' ),
				) as $key => $label ) :
					?>
					<tr><th><label for="onf-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input type="text" id="onf-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>" class="regular-text"></td></tr>
				<?php endforeach; ?>
				<tr><th><label for="onf-org_address"><?php esc_html_e( 'Mailing address', 'onf-core' ); ?></label></th>
					<td><textarea id="onf-org_address" name="org_address" rows="2" class="regular-text"><?php echo esc_textarea( $s['org_address'] ); ?></textarea></td></tr>
				<tr><th><label for="onf-receipt_next"><?php esc_html_e( 'Receipt numbers', 'onf-core' ); ?></label></th>
					<td>
						<?php
						$year = current_time( 'Y' );
						$next = (int) get_option( 'onf_receipt_next_' . $year, 1 );
						/* translators: 1: example receipt number, 2: year */
						echo esc_html( sprintf( __( 'Numbered by year and restart each January (e.g. %1$s). Next number for %2$s:', 'onf-core' ), substr( $year, 2 ) . '-0001', $year ) );
						?>
						<input type="number" id="onf-receipt_next" name="receipt_next" value="<?php echo esc_attr( $next ); ?>" min="1" step="1" class="small-text">
						<?php /* translators: %s: receipt number */ ?>
						<span class="description"><?php echo esc_html( sprintf( __( '→ %s', 'onf-core' ), substr( $year, 2 ) . '-' . str_pad( (string) $next, 4, '0', STR_PAD_LEFT ) ) ); ?></span>
					</td></tr>
			</table>

			<h2><?php esc_html_e( 'Emails', 'onf-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th><label for="onf-from_name"><?php esc_html_e( 'From', 'onf-core' ); ?></label></th>
					<td><input type="text" id="onf-from_name" name="from_name" value="<?php echo esc_attr( $s['from_name'] ); ?>" class="regular-text" aria-label="<?php esc_attr_e( 'From name', 'onf-core' ); ?>">
						<input type="email" name="from_email" value="<?php echo esc_attr( $s['from_email'] ); ?>" class="regular-text" aria-label="<?php esc_attr_e( 'From email', 'onf-core' ); ?>"></td></tr>
				<tr><th><label for="onf-admin_emails"><?php esc_html_e( 'Admin notification emails', 'onf-core' ); ?></label></th>
					<td><textarea id="onf-admin_emails" name="admin_emails" rows="2" class="regular-text"><?php echo esc_textarea( $s['admin_emails'] ); ?></textarea>
						<p><label><input type="checkbox" name="notify_admins" value="1" <?php checked( $s['notify_admins'] ); ?>> <?php esc_html_e( 'Email admins about each online gift', 'onf-core' ); ?></label></p>
						<p><label><input type="checkbox" name="notify_players" value="1" <?php checked( $s['notify_players'] ); ?>> <?php esc_html_e( 'Email the player (their Email field) about each gift to them', 'onf-core' ); ?></label></p></td></tr>
				<?php
				foreach ( array(
					'receipt' => __( 'Donor receipt', 'onf-core' ),
					'player'  => __( 'Player notification', 'onf-core' ),
					'admin'   => __( 'Admin notification', 'onf-core' ),
				) as $key => $label ) :
					?>
					<tr><th><label for="onf-<?php echo esc_attr( $key ); ?>_subject"><?php echo esc_html( $label ); ?></label></th>
						<td><input type="text" id="onf-<?php echo esc_attr( $key ); ?>_subject" name="<?php echo esc_attr( $key ); ?>_subject" value="<?php echo esc_attr( $s[ $key . '_subject' ] ); ?>" class="large-text" aria-label="<?php esc_attr_e( 'Subject', 'onf-core' ); ?>">
							<textarea name="<?php echo esc_attr( $key ); ?>_body" rows="10" class="large-text" aria-label="<?php esc_attr_e( 'Message', 'onf-core' ); ?>"><?php echo esc_textarea( $s[ $key . '_body' ] ); ?></textarea></td></tr>
				<?php endforeach; ?>
				<tr><th><?php esc_html_e( 'Tags', 'onf-core' ); ?></th>
					<td><p class="description"><code>{first_name} {donor_name} {donor_email} {donor_shown} {amount} {fee_line} {date} {receipt_number} {for} {for_line} {player} {player_first_name} {event} {event_line} {fund} {message} {message_line} {player_event_total} {source} {payment_method} {ein} {org_name} {org_address} {org_email} {site_url} {sitename}</code></p>
						<p class="description"><?php esc_html_e( 'Plain text; line breaks are kept. {donor_shown} respects "Anonymous". The donor receipt gets the PDF attached.', 'onf-core' ); ?></p></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Check the Stripe connection', 'onf-core' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="onf_stripe_test">
			<?php wp_nonce_field( 'onf_stripe_test' ); ?>
			<?php submit_button( __( 'Test connection', 'onf-core' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

function onf_render_settings_notice() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	$msg   = sanitize_text_field( wp_unslash( $_GET['onf_msg'] ?? '' ) );
	$error = sanitize_text_field( wp_unslash( $_GET['onf_error'] ?? '' ) );
	// phpcs:enable
	if ( 'saved' === $msg ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'onf-core' ) . '</p></div>';
	} elseif ( '' !== $msg ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
	if ( $error ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
	}
}

add_action(
	'admin_post_onf_save_settings',
	static function () {
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_save_settings' );
		$in       = wp_unslash( $_POST );
		$old      = onf_settings();
		$defaults = onf_settings_defaults();
		$new      = array();

		$new['stripe_mode'] = ( 'live' === ( $in['stripe_mode'] ?? '' ) && onf_live_allowed() ) ? 'live' : 'test';
		$prefixes           = array(
			'stripe_test_secret'         => array( 'sk_test_', 'rk_test_' ),
			'stripe_test_webhook_secret' => array( 'whsec_' ),
			'stripe_live_secret'         => array( 'sk_live_', 'rk_live_' ),
			'stripe_live_webhook_secret' => array( 'whsec_' ),
		);
		$errors             = array();
		foreach ( onf_secret_fields() as $key ) {
			$value     = trim( (string) ( $in[ $key ] ?? '' ) );
			$new[ $key ] = $old[ $key ];
			if ( ! empty( $in[ 'clear_' . $key ] ) ) {
				$new[ $key ] = '';
			} elseif ( '' !== $value ) {
				$ok = false;
				foreach ( $prefixes[ $key ] as $prefix ) {
					$ok = $ok || str_starts_with( $value, $prefix );
				}
				if ( $ok && preg_match( '/^[A-Za-z0-9_]+$/', $value ) ) {
					$new[ $key ] = $value;
				} else {
					/* translators: %s: expected prefix */
					$errors[] = sprintf( __( 'That key doesn’t look right (should start with %s) — not saved.', 'onf-core' ), implode( ' or ', $prefixes[ $key ] ) );
				}
			}
		}
		if ( ! onf_live_allowed() ) {
			$new['stripe_live_secret']         = '';
			$new['stripe_live_webhook_secret'] = '';
		}

		$new['amounts']     = implode( ', ', array_map( static fn( $a ) => 0 + $a, array_filter( array_map( 'floatval', preg_split( '/[\s,]+/', (string) ( $in['amounts'] ?? '' ) ) ) ) ) );
		$new['amounts']     = '' !== $new['amounts'] ? $new['amounts'] : $defaults['amounts'];
		$new['min_amount']  = max( 1, (int) ( $in['min_amount'] ?? $defaults['min_amount'] ) );
		$new['fee_percent'] = min( 10, max( 0, (float) ( $in['fee_percent'] ?? 0 ) ) );
		$new['fee_fixed']   = max( 0, round( (float) ( $in['fee_fixed'] ?? 0 ), 2 ) );

		foreach ( array( 'org_name', 'org_tagline', 'org_ein', 'from_name', 'receipt_subject', 'player_subject', 'admin_subject' ) as $key ) {
			$new[ $key ] = sanitize_text_field( $in[ $key ] ?? '' );
		}
		foreach ( array( 'org_email', 'from_email' ) as $key ) {
			$new[ $key ] = sanitize_email( $in[ $key ] ?? '' );
		}
		foreach ( array( 'org_address', 'admin_emails', 'receipt_body', 'player_body', 'admin_body' ) as $key ) {
			$new[ $key ] = sanitize_textarea_field( $in[ $key ] ?? '' );
		}
		$new['player_donations'] = 'onf' === ( $in['player_donations'] ?? '' ) ? 'onf' : 'givewp';
		$new['notify_admins']   = empty( $in['notify_admins'] ) ? 0 : 1;
		$new['notify_players']  = empty( $in['notify_players'] ) ? 0 : 1;

		update_option( 'onf_settings', $new );
		$next = absint( $in['receipt_next'] ?? 0 );
		if ( $next ) {
			update_option( 'onf_receipt_next_' . current_time( 'Y' ), $next, false );
		}

		$url = add_query_arg( 'onf_msg', 'saved', admin_url( 'admin.php?page=onf-settings' ) );
		if ( $errors ) {
			$url = add_query_arg( 'onf_error', rawurlencode( implode( ' ', $errors ) ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
);

add_action(
	'admin_post_onf_stripe_test',
	static function () {
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_stripe_test' );
		$result = onf_stripe_request( 'GET', 'checkout/sessions', array( 'limit' => 1 ) );
		$back   = admin_url( 'admin.php?page=onf-settings' );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'onf_error', rawurlencode( $result->get_error_message() ), $back ) );
			exit;
		}
		$webhook = '' !== onf_stripe_webhook_secret() ? __( 'Webhook secret is set.', 'onf-core' ) : __( 'Webhook secret is NOT set yet.', 'onf-core' );
		/* translators: 1: mode, 2: webhook status */
		wp_safe_redirect( add_query_arg( 'onf_msg', rawurlencode( sprintf( __( 'Connected to Stripe (%1$s mode). %2$s', 'onf-core' ), onf_stripe_mode(), $webhook ) ), $back ) );
		exit;
	}
);

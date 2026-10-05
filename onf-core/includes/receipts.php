<?php
/**
 * Receipts and gift emails.
 * - PDF receipt for any completed gift (download in admin, attached to the donor's email).
 * - Donor receipt, player notification and admin notification, from editable templates.
 * - Every email is logged (Gifts → Email log), including on staging where sending is switched off.
 */

defined( 'ABSPATH' ) || exit;

require_once ONF_CORE_DIR . 'includes/lib/class-onf-pdf.php';

function onf_email_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'onf_email_log';
}

/**
 * Template tags for a gift. Values are plain text.
 *
 * @return array tag => value
 */
function onf_gift_tags( $gift ) {
	$donor_name  = trim( $gift->donor_first_name . ' ' . $gift->donor_last_name );
	$player      = $gift->player_id ? get_the_title( $gift->player_id ) : '';
	$event       = $gift->event_id ? get_the_title( $gift->event_id ) : '';
	$fund        = $gift->fund_id ? get_the_title( $gift->fund_id ) : '';
	$for         = onf_gift_for_label( $gift );
	$shown       = $gift->anonymous ? __( 'Anonymous', 'onf-core' ) : ( $gift->display_name ? $gift->display_name : ( $donor_name ? $donor_name : __( 'A donor', 'onf-core' ) ) );
	$player_goal = $gift->player_id ? onf_player_total( (int) $gift->player_id, (int) $gift->event_id ) : 0;

	return array(
		'first_name'         => $gift->donor_first_name ? $gift->donor_first_name : __( 'Friend', 'onf-core' ),
		'donor_name'         => $donor_name ? $donor_name : __( '(no name)', 'onf-core' ),
		'donor_email'        => $gift->donor_email ? $gift->donor_email : __( 'no email', 'onf-core' ),
		'donor_shown'        => $shown,
		'amount'             => onf_money( $gift->amount ),
		'fee_line'           => (float) $gift->fee_covered > 0 ? sprintf( ' (+ %s card fee covered by donor)', onf_money( $gift->fee_covered ) ) : '',
		'date'               => mysql2date( get_option( 'date_format' ), $gift->gift_date ),
		'receipt_number'     => $gift->receipt_number ? $gift->receipt_number : '#' . $gift->id,
		'for'                => $for,
		'for_line'           => ( $player || $fund || $event ) ? ' for ' . $for : '',
		'player'             => $player,
		'player_first_name'  => $player ? strtok( $player, ' ' ) : '',
		'event'              => $event,
		'event_line'         => $event ? ' for ' . $event : '',
		'fund'               => $fund,
		'message'            => (string) $gift->message,
		'message_line'       => $gift->message ? 'Message: ' . $gift->message . "\n" : '',
		'player_event_total' => onf_money( $player_goal ),
		'source'             => trim( ( onf_gift_sources()[ $gift->source ] ?? $gift->source ) . ' ' . ( $gift->method ? '(' . ( onf_gift_methods()[ $gift->method ] ?? $gift->method ) . ')' : '' ) ),
		'payment_method'     => 'stripe' === $gift->source ? __( 'Card (online)', 'onf-core' ) : ( onf_gift_methods()[ $gift->method ] ?? ( onf_gift_sources()[ $gift->source ] ?? $gift->source ) ),
		'ein'                => onf_setting( 'org_ein' ),
		'org_name'           => onf_setting( 'org_name' ),
		'org_address'        => onf_setting( 'org_address' ),
		'org_email'          => onf_setting( 'org_email' ),
		'site_url'           => home_url( '/' ),
		'sitename'           => get_bloginfo( 'name' ),
	);
}

function onf_fill_tags( $template, array $tags, $escape = false ) {
	$pairs = array();
	foreach ( $tags as $tag => $value ) {
		$pairs[ '{' . $tag . '}' ] = $escape ? esc_html( $value ) : $value;
	}
	return strtr( $escape ? esc_html( $template ) : $template, $pairs );
}

/**
 * Wrap plain-text email content in a simple branded HTML layout.
 */
function onf_email_html( $text_html ) {
	$logo = esc_url( ONF_CORE_URL . 'assets/email-logo.png?v=' . ONF_CORE_VERSION );
	$name = esc_attr( onf_setting( 'org_name' ) );
	return '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f6f8;font-family:Helvetica,Arial,sans-serif;color:#1f2933">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
		. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-top:4px solid #0F2A3F">'
		. '<tr><td style="padding:24px 32px 8px"><img src="' . $logo . '" alt="' . $name . '" width="300" style="display:block;width:300px;max-width:100%;height:auto"></td></tr>'
		. '<tr><td style="padding:8px 32px 32px;font-size:16px;line-height:1.5">' . nl2br( $text_html ) . '</td></tr>'
		. '</table></td></tr></table></body></html>';
}

/**
 * Send (and log) an email.
 *
 * @param string|string[] $to
 * @param array           $attachments File paths.
 * @return bool
 */
function onf_send_email( $to, $subject, $html, $type, $gift_id = 0, array $attachments = array() ) {
	global $wpdb;
	$to      = (array) $to;
	$subject = trim( preg_replace( '/[\r\n]+/', ' ', wp_strip_all_tags( $subject ) ) );
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	$from    = sanitize_email( (string) onf_setting( 'from_email' ) );
	if ( $from ) {
		$headers[] = sprintf( 'From: %s <%s>', str_replace( array( '<', '>', '"' ), '', (string) onf_setting( 'from_name' ) ), $from );
	}

	$error  = '';
	$catch  = static function ( $wp_error ) use ( &$error ) {
		$error = $wp_error->get_error_message();
	};
	add_action( 'wp_mail_failed', $catch );
	$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
	remove_action( 'wp_mail_failed', $catch );

	$wpdb->insert(
		onf_email_log_table(),
		array(
			'gift_id'    => (int) $gift_id,
			'type'       => $type,
			'recipient'  => implode( ', ', $to ),
			'subject'    => $subject,
			'body'       => $html,
			'status'     => $sent ? 'sent' : 'failed',
			'error'      => $error,
			'created_at' => current_time( 'mysql' ),
		)
	);
	// Keep the log to the latest 2,000 emails.
	$wpdb->query( 'DELETE FROM ' . onf_email_log_table() . ' WHERE id <= ' . ( (int) $wpdb->insert_id - 2000 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	return $sent;
}

/**
 * Send the emails for a gift.
 *
 * @param string[] $types Any of receipt, player, admin.
 * @return string[] Types actually sent.
 */
function onf_send_gift_emails( int $gift_id, array $types = array( 'receipt', 'player', 'admin' ) ) {
	$gift = onf_get_gift( $gift_id );
	if ( ! $gift || 'completed' !== $gift->status ) {
		return array();
	}
	$tags = onf_gift_tags( $gift );
	$done = array();

	if ( in_array( 'receipt', $types, true ) && is_email( $gift->donor_email ) && 'donation' === $gift->type ) {
		$pdf = onf_receipt_pdf_file( $gift );
		$ok  = onf_send_email(
			$gift->donor_email,
			onf_fill_tags( onf_setting( 'receipt_subject' ), $tags ),
			onf_email_html( onf_fill_tags( onf_setting( 'receipt_body' ), $tags, true ) ),
			'receipt',
			$gift_id,
			$pdf ? array( $pdf ) : array()
		);
		if ( $pdf ) {
			wp_delete_file( $pdf );
			@rmdir( dirname( $pdf ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		if ( $ok ) {
			$done[] = 'receipt';
		}
	}

	if ( in_array( 'player', $types, true ) && $gift->player_id && onf_setting( 'notify_players' ) ) {
		$email = sanitize_email( (string) get_post_meta( $gift->player_id, 'email', true ) );
		if ( is_email( $email ) && onf_send_email(
			$email,
			onf_fill_tags( onf_setting( 'player_subject' ), $tags ),
			onf_email_html( onf_fill_tags( onf_setting( 'player_body' ), $tags, true ) ),
			'player',
			$gift_id
		) ) {
			$done[] = 'player';
		}
	}

	if ( in_array( 'admin', $types, true ) && onf_setting( 'notify_admins' ) && onf_admin_emails() ) {
		if ( onf_send_email(
			onf_admin_emails(),
			onf_fill_tags( onf_setting( 'admin_subject' ), $tags ),
			onf_email_html( onf_fill_tags( onf_setting( 'admin_body' ), $tags, true ) ),
			'admin',
			$gift_id
		) ) {
			$done[] = 'admin';
		}
	}
	return $done;
}

/**
 * Build the PDF receipt.
 *
 * @return string PDF bytes.
 */
function onf_receipt_pdf( $gift ) {
	$tags  = onf_gift_tags( $gift );
	$navy  = array( 0.059, 0.165, 0.247 ); // #0F2A3F
	$gray  = array( 0.42, 0.45, 0.5 );
	$pdf   = new ONF_PDF();
	$left  = 72;
	$width = 468;

	$logo_h = $pdf->jpeg( ONF_CORE_DIR . 'assets/receipt-logo.jpg', $left, 56, 260 );
	$y      = 56 + $logo_h + 22;
	$pdf->text( $left, $y, onf_setting( 'org_tagline' ), 10, 'italic', $gray );

	$y += 40;
	$pdf->text( $left, $y, 'refunded' === $gift->status ? 'Donation Receipt (REFUNDED)' : 'Receipt of Charitable Donation', 20, 'bold', $navy );
	$y += 30;
	$pdf->text( $left, $y, 'Dear ' . $tags['first_name'] . ',', 11 );
	$y += 20;
	$y  = $pdf->paragraph(
		$left,
		$y,
		$width,
		sprintf( 'Thank you for your donation%s on %s. Your generosity is greatly appreciated.', $tags['for_line'], $tags['date'] )
	);

	// Donor block.
	$y += 14;
	$pdf->text( $left, $y, 'DONOR', 9, 'bold', $gray );
	$y    += 16;
	$donor = $gift->donor_id ? onf_get_donor( (int) $gift->donor_id ) : null;
	$lines = array_filter(
		array(
			trim( $gift->donor_first_name . ' ' . $gift->donor_last_name ),
			$gift->donor_company,
			$donor ? $donor->address1 : '',
			$donor ? $donor->address2 : '',
			$donor ? trim( $donor->city . ( $donor->state ? ', ' . $donor->state : '' ) . ' ' . $donor->zip ) : '',
			$gift->donor_email,
		)
	);
	foreach ( $lines as $line ) {
		$pdf->text( $left, $y, $line, 11 );
		$y += 15;
	}

	// Details table.
	$y += 16;
	$rows = array(
		'Donation for'   => $tags['for'],
		'Amount'         => $tags['amount'],
		'Payment method' => $tags['payment_method'],
		'Date'           => $tags['date'],
		'Receipt number' => $tags['receipt_number'],
	);
	if ( $gift->stripe_payment_intent ) {
		$rows['Transaction ID'] = $gift->stripe_payment_intent;
	} elseif ( $gift->reference ) {
		$rows['Reference'] = $gift->reference;
	}
	$top = $y - 4;
	$pdf->rect( $left - 12, $top - 12, $width + 24, count( $rows ) * 22 + 12, array( 0.965, 0.973, 0.98 ) );
	foreach ( $rows as $label => $value ) {
		$pdf->text( $left, $y + 6, strtoupper( $label ), 9, 'bold', $gray );
		$pdf->text( $left + 130, $y + 6, $value, 11, 'regular', array( 0.1, 0.1, 0.1 ) );
		$y += 22;
	}

	// Tax statement.
	$y += 24;
	$y  = $pdf->paragraph(
		$left,
		$y,
		$width,
		sprintf( 'The %s Federal Non-Profit ID is %s. No goods or services were provided in exchange for this contribution.', onf_setting( 'org_name' ), onf_setting( 'org_ein' ) ),
		10
	);
	if ( (float) $gift->fee_covered > 0 ) {
		$y = $pdf->paragraph(
			$left,
			$y + 4,
			$width,
			sprintf( 'You also covered %1$s in card processing fees, so your total tax-deductible gift is %2$s.', onf_money( $gift->fee_covered ), onf_money( (float) $gift->amount + (float) $gift->fee_covered ) ),
			10
		);
	}

	// Footer.
	$pdf->line( $left, 700, $left + $width, 700 );
	$footer = array_merge(
		array( onf_setting( 'org_name' ) ),
		preg_split( "/\r\n|\n/", (string) onf_setting( 'org_address' ) ),
		array( onf_setting( 'org_email' ), preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) ) )
	);
	$pdf->text( $left, 718, implode( '  ·  ', array_filter( array_map( 'trim', $footer ) ) ), 9, 'regular', $gray );

	return $pdf->output();
}

/**
 * Write the PDF receipt to a temporary file (for attaching). Caller deletes it.
 *
 * @return string File path, or '' on failure.
 */
function onf_receipt_pdf_file( $gift ) {
	$dir = trailingslashit( get_temp_dir() ) . 'onf-receipt-' . wp_generate_password( 12, false );
	if ( ! wp_mkdir_p( $dir ) ) {
		return '';
	}
	$file = $dir . '/' . sanitize_file_name( 'ONF-Receipt-' . ( $gift->receipt_number ? $gift->receipt_number : $gift->id ) . '.pdf' );
	return false !== file_put_contents( $file, onf_receipt_pdf( $gift ) ) ? $file : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}

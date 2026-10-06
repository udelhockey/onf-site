<?php
/**
 * Gifts admin: list with filters, CSV export, add a manual (check/cash) gift, change a gift's status.
 */

defined( 'ABSPATH' ) || exit;

const ONF_GIFTS_CAP = 'manage_options';

add_action(
	'admin_menu',
	static function () {
		add_menu_page( __( 'Gifts', 'onf-core' ), __( 'Gifts', 'onf-core' ), ONF_GIFTS_CAP, 'onf-gifts', 'onf_render_gifts_page', 'dashicons-money-alt', 26 );
		add_submenu_page( 'onf-gifts', __( 'All gifts', 'onf-core' ), __( 'All gifts', 'onf-core' ), ONF_GIFTS_CAP, 'onf-gifts', 'onf_render_gifts_page' );
		add_submenu_page( 'onf-gifts', __( 'Add manual gift', 'onf-core' ), __( 'Add manual gift', 'onf-core' ), ONF_GIFTS_CAP, 'onf-add-gift', 'onf_render_add_gift_page' );
		add_submenu_page( 'onf-gifts', __( 'Email log', 'onf-core' ), __( 'Email log', 'onf-core' ), ONF_GIFTS_CAP, 'onf-email-log', 'onf_render_email_log_page' );
	}
);

/**
 * Filters from the request, shared by the list and the CSV export.
 */
function onf_gift_filters_from_request() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
	$filters = array(
		'event_id'  => absint( $_GET['event_id'] ?? 0 ),
		'player_id' => absint( $_GET['player_id'] ?? 0 ),
		'fund_id'   => absint( $_GET['fund_id'] ?? 0 ),
		'donor_id'  => absint( $_GET['donor_id'] ?? 0 ),
		'source'    => sanitize_key( $_GET['source'] ?? '' ),
		'status'    => sanitize_key( $_GET['status'] ?? '' ),
		's'         => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
	);
	// phpcs:enable
	return $filters;
}

/**
 * WHERE clause for the given filters.
 */
function onf_gift_where( array $filters ) {
	global $wpdb;
	$where = array( '1=1' );
	foreach ( array( 'event_id', 'player_id', 'fund_id', 'donor_id' ) as $column ) {
		if ( ! empty( $filters[ $column ] ) ) {
			$where[] = $wpdb->prepare( "g.$column = %d", $filters[ $column ] );
		}
	}
	if ( array_key_exists( $filters['source'] ?? '', onf_gift_sources() ) ) {
		$where[] = $wpdb->prepare( 'g.source = %s', $filters['source'] );
	}
	if ( array_key_exists( $filters['status'] ?? '', onf_gift_statuses() ) ) {
		$where[] = $wpdb->prepare( 'g.status = %s', $filters['status'] );
	}
	if ( '' !== ( $filters['s'] ?? '' ) ) {
		$like    = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
		$where[] = $wpdb->prepare(
			"(CONCAT(g.donor_first_name, ' ', g.donor_last_name) LIKE %s OR g.donor_email LIKE %s OR g.donor_company LIKE %s OR g.reference LIKE %s)",
			$like,
			$like,
			$like,
			$like
		);
	}
	return implode( ' AND ', $where );
}

function onf_gift_query( array $filters, int $limit = 0, int $offset = 0 ) {
	global $wpdb;
	$sql = "SELECT g.*, pl.post_title AS player_name, ev.post_title AS event_name, fu.post_title AS fund_name
		FROM " . onf_gifts_table() . " g
		LEFT JOIN {$wpdb->posts} pl ON pl.ID = g.player_id
		LEFT JOIN {$wpdb->posts} ev ON ev.ID = g.event_id
		LEFT JOIN {$wpdb->posts} fu ON fu.ID = g.fund_id
		WHERE " . onf_gift_where( $filters ) . ' ORDER BY g.gift_date DESC, g.id DESC';
	if ( $limit ) {
		$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
	}
	return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- parts prepared above.
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ONF_Gifts_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'gift',
				'plural'   => 'gifts',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox">',
			'gift_date' => __( 'Date', 'onf-core' ),
			'amount'    => __( 'Amount', 'onf-core' ),
			'donor'     => __( 'Donor', 'onf-core' ),
			'for'       => __( 'For', 'onf-core' ),
			'source'    => __( 'Source', 'onf-core' ),
			'status'    => __( 'Status', 'onf-core' ),
		);
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="gift[]" value="%d" aria-label="%s">', (int) $item->id, esc_attr__( 'Select gift', 'onf-core' ) );
	}

	protected function get_bulk_actions() {
		return array( 'assign_event' => __( 'Assign to event…', 'onf-core' ) );
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<div class="alignleft actions">';
		onf_post_select( 'bulk_event_id', 'onf_event', 0, __( '— Event for "Assign to event" —', 'onf-core' ) );
		echo '<p class="description">' . esc_html__( 'Tick gifts, choose "Assign to event…" and an event, then Apply. Choose no event to remove them from their event.', 'onf-core' ) . '</p>';
		echo '</div>';
	}

	public function prepare_items() {
		global $wpdb;
		$filters  = onf_gift_filters_from_request();
		$per_page = 50;
		$total    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . onf_gifts_table() . ' g WHERE ' . onf_gift_where( $filters ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->items           = onf_gift_query( $filters, $per_page, ( $this->get_pagenum() - 1 ) * $per_page );
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	protected function column_gift_date( $item ) {
		$out     = esc_html( mysql2date( get_option( 'date_format' ), $item->gift_date ) );
		$actions = array();
		foreach ( onf_gift_statuses() as $status => $label ) {
			if ( $status === $item->status ) {
				continue;
			}
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=onf_gift_status&gift=' . (int) $item->id . '&status=' . $status ),
				'onf_gift_status_' . $item->id
			);
			/* translators: %s: status name */
			$actions[ $status ] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( sprintf( __( 'Mark %s', 'onf-core' ), strtolower( $label ) ) ) );
		}
		$actions = array( 'edit' => sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=onf-gifts&edit=' . (int) $item->id ) ), esc_html__( 'Edit', 'onf-core' ) ) ) + $actions;
		if ( 'stripe' === $item->source && 'completed' === $item->status && $item->stripe_payment_intent ) {
			$refund            = wp_nonce_url( admin_url( 'admin-post.php?action=onf_refund_gift&gift=' . (int) $item->id ), 'onf_refund_gift_' . $item->id );
			$confirm           = esc_js( sprintf( /* translators: %s: amount */ __( 'Refund %s to the donor through Stripe? This cannot be undone.', 'onf-core' ), onf_money( (float) $item->amount + (float) $item->fee_covered ) ) );
			$actions['refund'] = sprintf( '<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>', esc_url( $refund ), $confirm, esc_html__( 'Refund…', 'onf-core' ) );
			unset( $actions['refunded'] ); // A Stripe gift is refunded through Stripe, not just relabeled.
		}
		if ( 'completed' === $item->status || 'refunded' === $item->status ) {
			$pdf                = wp_nonce_url( admin_url( 'admin-post.php?action=onf_receipt_pdf&gift=' . (int) $item->id ), 'onf_receipt_pdf_' . $item->id );
			$actions['receipt'] = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $pdf ), esc_html__( 'Receipt PDF', 'onf-core' ) );
		}
		if ( 'completed' === $item->status && is_email( $item->donor_email ) ) {
			$resend            = wp_nonce_url( admin_url( 'admin-post.php?action=onf_resend_receipt&gift=' . (int) $item->id ), 'onf_resend_receipt_' . $item->id );
			$actions['resend'] = sprintf( '<a href="%s">%s</a>', esc_url( $resend ), esc_html__( 'Resend receipt', 'onf-core' ) );
		}
		$out .= $item->receipt_number ? '<br><small>' . esc_html( $item->receipt_number ) . '</small>' : '';
		return $out . $this->row_actions( $actions );
	}

	protected function column_amount( $item ) {
		$out = esc_html( onf_money( $item->amount ) );
		if ( (float) $item->fee_covered > 0 ) {
			/* translators: %s: fee amount */
			$out .= '<br><small>' . esc_html( sprintf( __( '+ %s fee covered', 'onf-core' ), onf_money( $item->fee_covered ) ) ) . '</small>';
		}
		return $out;
	}

	protected function column_donor( $item ) {
		$name = trim( $item->donor_first_name . ' ' . $item->donor_last_name );
		$out  = esc_html( '' !== $name ? $name : '—' );
		if ( $item->donor_id ) {
			$out = sprintf( '<a href="%s">%s</a>', esc_url( onf_donor_url( $item->donor_id ) ), $out );
		}
		if ( $item->donor_company ) {
			$out .= '<br>' . esc_html( $item->donor_company );
		}
		if ( $item->donor_email ) {
			$out .= '<br><small>' . esc_html( $item->donor_email ) . '</small>';
		}
		if ( $item->anonymous ) {
			$out .= '<br><small>' . esc_html__( '(anonymous on site)', 'onf-core' ) . '</small>';
		}
		return $out;
	}

	protected function column_for( $item ) {
		$parts = array_filter( array( $item->player_name, $item->event_name, $item->fund_name ) );
		return esc_html( $parts ? implode( ' · ', $parts ) : __( 'General', 'onf-core' ) );
	}

	protected function column_source( $item ) {
		$out = esc_html( onf_gift_sources()[ $item->source ] ?? $item->source );
		if ( $item->method ) {
			$out .= ' · ' . esc_html( onf_gift_methods()[ $item->method ] ?? $item->method );
		}
		if ( $item->reference ) {
			$out .= '<br><small>' . esc_html( $item->reference ) . '</small>';
		}
		return $out;
	}

	protected function column_status( $item ) {
		return esc_html( onf_gift_statuses()[ $item->status ] ?? $item->status );
	}

	public function no_items() {
		esc_html_e( 'No gifts found.', 'onf-core' );
	}
}

/**
 * <select> of posts of a type.
 */
function onf_post_select( string $name, string $post_type, int $selected, string $placeholder ) {
	$posts = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'draft', 'future' ),
			'posts_per_page' => -1,
			'orderby'        => 'onf_event' === $post_type ? 'date' : 'title',
			'order'          => 'onf_event' === $post_type ? 'DESC' : 'ASC',
		)
	);
	printf( '<select name="%s" id="onf-%s">', esc_attr( $name ), esc_attr( $name ) );
	printf( '<option value="0">%s</option>', esc_html( $placeholder ) );
	foreach ( $posts as $post ) {
		printf( '<option value="%d" %s>%s</option>', (int) $post->ID, selected( $selected, $post->ID, false ), esc_html( $post->post_title ) );
	}
	echo '</select>';
}

function onf_render_gifts_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
	if ( isset( $_GET['edit'] ) ) {
		onf_render_edit_gift_page( absint( $_GET['edit'] ) );
		return;
	}
	$table   = new ONF_Gifts_List_Table();
	$filters = onf_gift_filters_from_request();
	$table->prepare_items();

	$export = wp_nonce_url( add_query_arg( array_filter( $filters ) + array( 'action' => 'onf_export_gifts' ), admin_url( 'admin-post.php' ) ), 'onf_export_gifts' );
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Gifts', 'onf-core' ); ?></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=onf-add-gift' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add manual gift', 'onf-core' ); ?></a>
		<a href="<?php echo esc_url( $export ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'onf-core' ); ?></a>
		<hr class="wp-header-end">
		<?php onf_render_gift_notice(); ?>
		<?php if ( $filters['donor_id'] && ( $donor = onf_get_donor( $filters['donor_id'] ) ) ) : ?>
			<p>
				<?php /* translators: %s: donor name */ ?>
				<?php echo esc_html( sprintf( __( 'Showing gifts from %s.', 'onf-core' ), onf_donor_name( $donor ) ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=onf-gifts' ) ); ?>"><?php esc_html_e( 'Show all', 'onf-core' ); ?></a>
			</p>
		<?php endif; ?>
		<form method="get">
			<input type="hidden" name="page" value="onf-gifts">
			<?php if ( $filters['donor_id'] ) : ?>
				<input type="hidden" name="donor_id" value="<?php echo (int) $filters['donor_id']; ?>">
			<?php endif; ?>
			<div class="tablenav top">
				<div class="alignleft actions">
					<?php
					onf_post_select( 'event_id', 'onf_event', $filters['event_id'], __( 'All events', 'onf-core' ) );
					onf_post_select( 'player_id', 'player', $filters['player_id'], __( 'All players', 'onf-core' ) );
					onf_post_select( 'fund_id', 'onf_fund', $filters['fund_id'], __( 'All funds', 'onf-core' ) );
					?>
					<select name="source">
						<option value=""><?php esc_html_e( 'All sources', 'onf-core' ); ?></option>
						<?php foreach ( onf_gift_sources() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['source'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'onf-core' ); ?></option>
						<?php foreach ( onf_gift_statuses() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Filter', 'onf-core' ), '', '', false ); ?>
				</div>
			</div>
			<?php
			$table->search_box( __( 'Search donors', 'onf-core' ), 'onf-gift-search' );
			$table->display();
			?>
		</form>
	</div>
	<?php
}

function onf_render_gift_notice() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	$messages = array(
		'added'         => __( 'Gift added. Any emails are listed in Gifts → Email log.', 'onf-core' ),
		'updated'       => __( 'Gift status updated.', 'onf-core' ),
		'donor_saved'   => __( 'Donor saved.', 'onf-core' ),
		'donor_merged'  => __( 'Donors merged.', 'onf-core' ),
		'donor_deleted' => __( 'Donor deleted.', 'onf-core' ),
		'resent'        => __( 'Receipt sent again (see Gifts → Email log).', 'onf-core' ),
		'refunded'      => __( 'Refunded through Stripe. The gift is now marked Refunded and left the totals.', 'onf-core' ),
		'gift_saved'    => __( 'Gift updated. Totals are refreshed.', 'onf-core' ),
		'assigned'      => __( 'Gifts assigned. Totals are refreshed.', 'onf-core' ),
	);
	$key      = sanitize_key( $_GET['onf_msg'] ?? '' );
	$error    = sanitize_text_field( wp_unslash( $_GET['onf_error'] ?? '' ) );
	// phpcs:enable
	if ( isset( $messages[ $key ] ) ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $key ] ) );
	}
	if ( $error ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
	}
}

function onf_render_add_gift_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Add manual gift', 'onf-core' ); ?></h1>
		<p><?php esc_html_e( 'For checks, cash and other gifts that did not come through the website. Counts toward the chosen player, event and fund totals.', 'onf-core' ); ?></p>
		<?php onf_render_gift_notice(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="onf_add_manual_gift">
			<?php wp_nonce_field( 'onf_add_manual_gift' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="onf-amount"><?php esc_html_e( 'Amount ($)', 'onf-core' ); ?></label></th>
					<td><input type="number" step="0.01" min="0.01" id="onf-amount" name="amount" required></td></tr>
				<tr><th><label for="onf-gift-date"><?php esc_html_e( 'Date received', 'onf-core' ); ?></label></th>
					<td><input type="date" id="onf-gift-date" name="gift_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required></td></tr>
				<tr><th><label for="onf-method"><?php esc_html_e( 'Method', 'onf-core' ); ?></label></th>
					<td><select id="onf-method" name="method">
						<?php foreach ( array( 'check', 'cash', 'other' ) as $method ) : ?>
							<option value="<?php echo esc_attr( $method ); ?>"><?php echo esc_html( onf_gift_methods()[ $method ] ); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="onf-reference"><?php esc_html_e( 'Check # / note', 'onf-core' ); ?></label>
					<input type="text" id="onf-reference" name="reference" class="regular-text"></td></tr>
				<tr><th><?php esc_html_e( 'For', 'onf-core' ); ?></th>
					<td>
						<?php onf_post_select( 'player_id', 'player', 0, __( '— No player —', 'onf-core' ) ); ?>
						<?php onf_post_select( 'event_id', 'onf_event', 0, __( '— No event —', 'onf-core' ) ); ?>
						<?php onf_post_select( 'fund_id', 'onf_fund', 0, __( '— No fund —', 'onf-core' ) ); ?>
						<p class="description"><?php esc_html_e( 'A gift for a player needs the event too.', 'onf-core' ); ?></p>
					</td></tr>
				<tr><th><?php esc_html_e( 'Donor', 'onf-core' ); ?></th>
					<td>
						<?php
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefill only.
						onf_donor_picker( 'donor_pick', __( 'Existing donor: start typing a name or email', 'onf-core' ), 0, absint( $_GET['donor_id'] ?? 0 ) );
						?>
						<p class="description"><?php esc_html_e( 'Or, for a new donor, fill in below (email optional). Leave everything blank for an unnamed gift.', 'onf-core' ); ?></p>
						<p>
							<input type="text" id="onf-first" name="donor_first_name" placeholder="<?php esc_attr_e( 'First name', 'onf-core' ); ?>" aria-label="<?php esc_attr_e( 'First name', 'onf-core' ); ?>">
							<input type="text" name="donor_last_name" placeholder="<?php esc_attr_e( 'Last name', 'onf-core' ); ?>" aria-label="<?php esc_attr_e( 'Last name', 'onf-core' ); ?>">
							<input type="email" name="donor_email" placeholder="<?php esc_attr_e( 'Email', 'onf-core' ); ?>" aria-label="<?php esc_attr_e( 'Email', 'onf-core' ); ?>">
							<input type="text" name="donor_company" placeholder="<?php esc_attr_e( 'Company', 'onf-core' ); ?>" aria-label="<?php esc_attr_e( 'Company', 'onf-core' ); ?>">
						</p>
					</td></tr>
				<tr><th><label for="onf-display"><?php esc_html_e( 'Name on donor board', 'onf-core' ); ?></label></th>
					<td><input type="text" id="onf-display" name="display_name" class="regular-text" placeholder="<?php esc_attr_e( 'Defaults to donor name', 'onf-core' ); ?>">
					<label><input type="checkbox" name="anonymous" value="1"> <?php esc_html_e( 'Show as Anonymous', 'onf-core' ); ?></label></td></tr>
				<tr><th><label for="onf-message"><?php esc_html_e( 'Message', 'onf-core' ); ?></label></th>
					<td><textarea id="onf-message" name="message" rows="3" class="large-text"></textarea></td></tr>
				<tr><th><label for="onf-status"><?php esc_html_e( 'Status', 'onf-core' ); ?></label></th>
					<td><select id="onf-status" name="status">
						<option value="completed"><?php esc_html_e( 'Received (counts toward totals)', 'onf-core' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pledged, not received yet', 'onf-core' ); ?></option>
					</select></td></tr>
				<tr><th><?php esc_html_e( 'Emails', 'onf-core' ); ?></th>
					<td><label><input type="checkbox" name="send_receipt" value="1" checked> <?php esc_html_e( 'Email the donor a receipt (if received and an email is on file)', 'onf-core' ); ?></label><br>
						<label><input type="checkbox" name="notify_player" value="1" checked> <?php esc_html_e( 'Let the player know (if the gift is for a player)', 'onf-core' ); ?></label>
						<p class="description"><?php esc_html_e( 'A pledge sends these when you later mark it Completed.', 'onf-core' ); ?></p></td></tr>
			</table>
			<?php submit_button( __( 'Add gift', 'onf-core' ) ); ?>
		</form>
	</div>
	<?php
}

add_action(
	'admin_post_onf_add_manual_gift',
	static function () {
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_add_manual_gift' );
		$input = wp_unslash( $_POST );
		$back  = admin_url( 'admin.php?page=onf-add-gift' );

		if ( absint( $input['player_id'] ?? 0 ) && ! absint( $input['event_id'] ?? 0 ) ) {
			wp_safe_redirect( add_query_arg( 'onf_error', rawurlencode( __( 'Choose the event this player gift belongs to.', 'onf-core' ) ), $back ) );
			exit;
		}

		$donor_id = onf_donor_id_from_pick( $input['donor_pick'] ?? '' );
		if ( '' !== trim( (string) ( $input['donor_pick'] ?? '' ) ) && ! onf_get_donor( $donor_id ) ) {
			wp_safe_redirect( add_query_arg( 'onf_error', rawurlencode( __( 'Pick the donor from the list, or clear that box and enter a new donor.', 'onf-core' ) ), $back ) );
			exit;
		}

		$date   = sanitize_text_field( $input['gift_date'] ?? '' );
		$result = onf_insert_gift(
			array(
				'amount'           => $input['amount'] ?? 0,
				'gift_date'        => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date . ' 12:00:00' : '',
				'method'           => $input['method'] ?? '',
				'reference'        => $input['reference'] ?? '',
				'player_id'        => $input['player_id'] ?? 0,
				'event_id'         => $input['event_id'] ?? 0,
				'fund_id'          => $input['fund_id'] ?? 0,
				'donor_id'         => $donor_id,
				'donor_first_name' => $input['donor_first_name'] ?? '',
				'donor_last_name'  => $input['donor_last_name'] ?? '',
				'donor_email'      => $input['donor_email'] ?? '',
				'donor_company'    => $input['donor_company'] ?? '',
				'display_name'     => $input['display_name'] ?? '',
				'anonymous'        => ! empty( $input['anonymous'] ),
				'message'          => $input['message'] ?? '',
				'status'           => in_array( $input['status'] ?? '', array( 'completed', 'pending' ), true ) ? $input['status'] : 'completed',
				'source'           => 'manual',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'onf_error', rawurlencode( $result->get_error_message() ), $back ) );
			exit;
		}
		$emails = array();
		if ( ! empty( $input['send_receipt'] ) ) {
			$emails[] = 'receipt';
		}
		if ( ! empty( $input['notify_player'] ) ) {
			$emails[] = 'player';
		}
		if ( $emails ) {
			onf_send_gift_emails( $result, $emails ); // Only sends for completed gifts.
			if ( 'pending' === ( $input['status'] ?? '' ) ) {
				update_option( 'onf_pledge_emails_' . $result, $emails, false );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=onf-gifts&onf_msg=added' ) );
		exit;
	}
);

add_action(
	'admin_post_onf_gift_status',
	static function () {
		$gift_id = absint( $_GET['gift'] ?? 0 );
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_gift_status_' . $gift_id );
		$before = onf_get_gift( $gift_id );
		$status = sanitize_key( $_GET['status'] ?? '' );
		if ( onf_set_gift_status( $gift_id, $status ) && $before && 'pending' === $before->status && 'completed' === $status ) {
			// A pledge has been received: send the emails chosen when it was entered.
			$emails = get_option( 'onf_pledge_emails_' . $gift_id, array() );
			if ( $emails ) {
				onf_send_gift_emails( $gift_id, (array) $emails );
			}
			delete_option( 'onf_pledge_emails_' . $gift_id );
		}
		wp_safe_redirect( wp_get_referer() ? add_query_arg( 'onf_msg', 'updated', wp_get_referer() ) : admin_url( 'admin.php?page=onf-gifts&onf_msg=updated' ) );
		exit;
	}
);

add_action(
	'admin_post_onf_export_gifts',
	static function () {
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_export_gifts' );

		$gifts = onf_gift_query( onf_gift_filters_from_request() );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=onf-gifts-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'ID', 'Donor ID', 'Date', 'Amount', 'Fee covered', 'Type', 'Status', 'Source', 'Method', 'Reference', 'First name', 'Last name', 'Email', 'Company', 'Display name', 'Anonymous', 'Message', 'Player', 'Event', 'Fund', 'Receipt #', 'Stripe payment', 'GiveWP ID' ) );
		foreach ( $gifts as $g ) {
			$row = array( $g->id, $g->donor_id ?: '', $g->gift_date, $g->amount, $g->fee_covered, $g->type, $g->status, $g->source, $g->method, $g->reference, $g->donor_first_name, $g->donor_last_name, $g->donor_email, $g->donor_company, $g->display_name, $g->anonymous ? 'yes' : 'no', $g->message, $g->player_name, $g->event_name, $g->fund_name, $g->receipt_number, $g->stripe_payment_intent, $g->givewp_id ?: '' );
			onf_csv_row( $out, $row );
		}
		fclose( $out );
		exit;
	}
);

/**
 * Write a CSV row, stopping spreadsheet apps from running donor-entered text as a formula.
 */
function onf_csv_row( $handle, array $row ) {
	$row = array_map( static fn( $v ) => is_string( $v ) && preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v, $row );
	fputcsv( $handle, $row );
}

add_action(
	'admin_post_onf_receipt_pdf',
	static function () {
		$gift_id = absint( $_GET['gift'] ?? 0 );
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_receipt_pdf_' . $gift_id );
		$gift = onf_get_gift( $gift_id );
		if ( ! $gift ) {
			wp_die( esc_html__( 'Gift not found.', 'onf-core' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename=' . sanitize_file_name( 'ONF-Receipt-' . ( $gift->receipt_number ? $gift->receipt_number : $gift->id ) . '.pdf' ) );
		echo onf_receipt_pdf( $gift ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}
);

add_action(
	'admin_post_onf_resend_receipt',
	static function () {
		$gift_id = absint( $_GET['gift'] ?? 0 );
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_resend_receipt_' . $gift_id );
		onf_send_gift_emails( $gift_id, array( 'receipt' ) );
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=onf-gifts' );
		wp_safe_redirect( add_query_arg( 'onf_msg', 'resent', $back ) );
		exit;
	}
);

function onf_render_email_log_page() {
	global $wpdb;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
	$view = absint( $_GET['email'] ?? 0 );
	echo '<div class="wrap"><h1>' . esc_html__( 'Email log', 'onf-core' ) . '</h1>';
	echo '<p>' . esc_html__( 'Every receipt and notification onf-core sends. On staging, outgoing mail is switched off in WP Mail SMTP, so emails show here but nobody receives them.', 'onf-core' ) . '</p>';

	if ( $view ) {
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . onf_email_log_table() . ' WHERE id = %d', $view ) );
		if ( $row ) {
			printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( admin_url( 'admin.php?page=onf-email-log' ) ), esc_html__( 'All emails', 'onf-core' ) );
			printf( '<p><strong>%s</strong> %s<br><strong>%s</strong> %s<br><strong>%s</strong> %s</p>', esc_html__( 'To:', 'onf-core' ), esc_html( $row->recipient ), esc_html__( 'Subject:', 'onf-core' ), esc_html( $row->subject ), esc_html__( 'Sent:', 'onf-core' ), esc_html( $row->created_at . ' · ' . $row->status . ( $row->error ? ' · ' . $row->error : '' ) ) );
			// Show the email exactly as sent, isolated from the admin page.
			printf( '<iframe title="%s" sandbox="" srcdoc="%s" width="100%%" height="700"></iframe>', esc_attr__( 'Email preview', 'onf-core' ), esc_attr( $row->body ) );
		}
		echo '</div>';
		return;
	}

	$rows = $wpdb->get_results( 'SELECT id, gift_id, type, recipient, subject, status, error, created_at FROM ' . onf_email_log_table() . ' ORDER BY id DESC LIMIT 200' );
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'onf-core' ) . '</th><th>' . esc_html__( 'Type', 'onf-core' ) . '</th><th>' . esc_html__( 'To', 'onf-core' ) . '</th><th>' . esc_html__( 'Subject', 'onf-core' ) . '</th><th>' . esc_html__( 'Status', 'onf-core' ) . '</th></tr></thead><tbody>';
	if ( ! $rows ) {
		echo '<tr><td colspan="5">' . esc_html__( 'No emails yet.', 'onf-core' ) . '</td></tr>';
	}
	foreach ( $rows as $row ) {
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a></td><td>%s</td></tr>',
			esc_html( $row->created_at ),
			esc_html( $row->type ),
			esc_html( $row->recipient ),
			esc_url( admin_url( 'admin.php?page=onf-email-log&email=' . (int) $row->id ) ),
			esc_html( $row->subject ),
			esc_html( $row->status . ( $row->error ? ': ' . $row->error : '' ) )
		);
	}
	echo '</tbody></table></div>';
}

function onf_render_edit_gift_page( int $gift_id ) {
	$gift = onf_get_gift( $gift_id );
	echo '<div class="wrap"><h1>' . esc_html__( 'Edit gift', 'onf-core' ) . '</h1>';
	if ( ! $gift ) {
		echo '<p>' . esc_html__( 'Gift not found.', 'onf-core' ) . '</p></div>';
		return;
	}
	onf_render_gift_notice();
	$manual = 'manual' === $gift->source;
	printf(
		'<p>%s · %s · %s · %s</p>',
		esc_html( onf_money( $gift->amount ) ),
		esc_html( trim( $gift->donor_first_name . ' ' . $gift->donor_last_name ) ),
		esc_html( onf_gift_sources()[ $gift->source ] ?? $gift->source ),
		esc_html( $gift->receipt_number ? $gift->receipt_number : '#' . $gift->id )
	);
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="onf_save_gift">
		<input type="hidden" name="gift" value="<?php echo (int) $gift->id; ?>">
		<?php wp_nonce_field( 'onf_save_gift_' . $gift->id ); ?>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'For', 'onf-core' ); ?></th><td>
				<?php
				onf_post_select( 'player_id', 'player', (int) $gift->player_id, __( '— No player —', 'onf-core' ) );
				onf_post_select( 'event_id', 'onf_event', (int) $gift->event_id, __( '— No event —', 'onf-core' ) );
				onf_post_select( 'fund_id', 'onf_fund', (int) $gift->fund_id, __( '— No fund —', 'onf-core' ) );
				?>
				<p class="description"><?php esc_html_e( 'Moves the gift between player, event and fund totals. The donor, receipt and payment stay as they are.', 'onf-core' ); ?></p>
			</td></tr>
			<?php if ( $manual ) : ?>
				<tr><th><label for="onf-amount"><?php esc_html_e( 'Amount ($)', 'onf-core' ); ?></label></th>
					<td><input type="number" step="0.01" min="0.01" id="onf-amount" name="amount" value="<?php echo esc_attr( $gift->amount ); ?>" required></td></tr>
				<tr><th><label for="onf-gift-date"><?php esc_html_e( 'Date received', 'onf-core' ); ?></label></th>
					<td><input type="date" id="onf-gift-date" name="gift_date" value="<?php echo esc_attr( substr( $gift->gift_date, 0, 10 ) ); ?>"></td></tr>
				<tr><th><label for="onf-method"><?php esc_html_e( 'Method', 'onf-core' ); ?></label></th>
					<td><select id="onf-method" name="method">
						<?php foreach ( array( 'check', 'cash', 'other' ) as $method ) : ?>
							<option value="<?php echo esc_attr( $method ); ?>" <?php selected( $gift->method, $method ); ?>><?php echo esc_html( onf_gift_methods()[ $method ] ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" name="reference" value="<?php echo esc_attr( $gift->reference ); ?>" class="regular-text" aria-label="<?php esc_attr_e( 'Check # / note', 'onf-core' ); ?>"></td></tr>
			<?php else : ?>
				<tr><th><?php esc_html_e( 'Amount', 'onf-core' ); ?></th>
					<td><?php echo esc_html( onf_money( $gift->amount ) ); ?> <span class="description"><?php esc_html_e( '(online and imported gifts keep the amount that was paid)', 'onf-core' ); ?></span></td></tr>
			<?php endif; ?>
			<tr><th><label for="onf-display"><?php esc_html_e( 'Name on donor board', 'onf-core' ); ?></label></th>
				<td><input type="text" id="onf-display" name="display_name" value="<?php echo esc_attr( $gift->display_name ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Defaults to donor name', 'onf-core' ); ?>">
				<label><input type="checkbox" name="anonymous" value="1" <?php checked( $gift->anonymous ); ?>> <?php esc_html_e( 'Show as Anonymous', 'onf-core' ); ?></label></td></tr>
			<tr><th><label for="onf-message"><?php esc_html_e( 'Message', 'onf-core' ); ?></label></th>
				<td><textarea id="onf-message" name="message" rows="3" class="large-text"><?php echo esc_textarea( $gift->message ); ?></textarea></td></tr>
		</table>
		<?php submit_button( __( 'Save gift', 'onf-core' ) ); ?>
	</form>
	<?php
	echo '</div>';
}

add_action(
	'admin_post_onf_save_gift',
	static function () {
		$gift_id = absint( $_POST['gift'] ?? 0 );
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_save_gift_' . $gift_id );
		$result = onf_update_gift( $gift_id, wp_unslash( $_POST ) );
		$back   = admin_url( 'admin.php?page=onf-gifts&edit=' . $gift_id );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'onf_error', rawurlencode( $result->get_error_message() ), $back ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=onf-gifts&onf_msg=gift_saved' ) );
		exit;
	}
);

add_action(
	'admin_post_onf_refund_gift',
	static function () {
		$gift_id = absint( $_GET['gift'] ?? 0 );
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_refund_gift_' . $gift_id );
		$gift = onf_get_gift( $gift_id );
		$back = admin_url( 'admin.php?page=onf-gifts' );
		if ( ! $gift || 'stripe' !== $gift->source || 'completed' !== $gift->status || ! $gift->stripe_payment_intent ) {
			wp_safe_redirect( add_query_arg( 'onf_error', rawurlencode( __( 'Only completed online gifts can be refunded here.', 'onf-core' ) ), $back ) );
			exit;
		}
		$refund = onf_stripe_request(
			'POST',
			'refunds',
			array( 'payment_intent' => $gift->stripe_payment_intent ),
			array( 'Idempotency-Key' => 'onf-refund-' . $gift->id )
		);
		if ( is_wp_error( $refund ) ) {
			wp_safe_redirect( add_query_arg( 'onf_error', rawurlencode( $refund->get_error_message() ), $back ) );
			exit;
		}
		onf_set_gift_status( $gift_id, 'refunded' ); // The charge.refunded webhook arrives too; it's harmless.
		wp_safe_redirect( add_query_arg( 'onf_msg', 'refunded', $back ) );
		exit;
	}
);

// Bulk "Assign to event": runs before the Gifts page prints anything, then redirects back.
add_action(
	'load-toplevel_page_onf-gifts',
	static function () {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked below once the action is known.
		$action = sanitize_key( $_REQUEST['action'] ?? '' );
		if ( 'assign_event' !== $action ) {
			$action = sanitize_key( $_REQUEST['action2'] ?? '' );
		}
		if ( 'assign_event' !== $action ) {
			return;
		}
		// phpcs:enable
		check_admin_referer( 'bulk-gifts' );
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		global $wpdb;
		$event_id = absint( $_REQUEST['bulk_event_id'] ?? 0 );
		$event_id = 'onf_event' === get_post_type( $event_id ) ? $event_id : 0;
		$ids      = array_filter( array_map( 'absint', (array) ( $_REQUEST['gift'] ?? array() ) ) );
		foreach ( $ids as $id ) {
			$wpdb->update(
				onf_gifts_table(),
				array(
					'event_id'   => $event_id,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $id )
			);
		}
		onf_flush_totals();
		$back = remove_query_arg( array( 'action', 'action2', 'gift', 'bulk_event_id', '_wpnonce', '_wp_http_referer' ), wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=onf-gifts' ) );
		wp_safe_redirect( add_query_arg( 'onf_msg', $ids ? 'assigned' : '', $back ) );
		exit;
	}
);

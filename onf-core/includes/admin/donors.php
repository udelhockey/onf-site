<?php
/**
 * Donors admin (Gifts → Donors): list with lifetime giving, search, sort, CSV export;
 * donor page with editable details, gift history, merge and delete.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	static function () {
		add_submenu_page( 'onf-gifts', __( 'Donors', 'onf-core' ), __( 'Donors', 'onf-core' ), ONF_GIFTS_CAP, 'onf-donors', 'onf_render_donors_page' );
	},
	11
);

function onf_donor_url( $donor_id = 0, array $args = array() ) {
	$args = array( 'page' => 'onf-donors' ) + ( $donor_id ? array( 'donor' => $donor_id ) : array() ) + $args;
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

class ONF_Donors_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'donor',
				'plural'   => 'donors',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'name'      => __( 'Donor', 'onf-core' ),
			'email'     => __( 'Email', 'onf-core' ),
			'location'  => __( 'City', 'onf-core' ),
			'gifts'     => __( 'Gifts', 'onf-core' ),
			'total'     => __( 'Total given', 'onf-core' ),
			'last_gift' => __( 'Last gift', 'onf-core' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'name'      => array( 'name', false ),
			'gifts'     => array( 'gifts', true ),
			'total'     => array( 'total', true ),
			'last_gift' => array( 'last_gift', true ),
		);
	}

	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list.
		$search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$orderby = sanitize_key( $_GET['orderby'] ?? 'total' );
		$order   = sanitize_key( $_GET['order'] ?? 'desc' );
		// phpcs:enable
		$per_page    = 50;
		$this->items = onf_query_donors(
			array(
				'search'  => $search,
				'orderby' => $orderby,
				'order'   => $order,
				'limit'   => $per_page,
				'offset'  => ( $this->get_pagenum() - 1 ) * $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => onf_count_donors( $search ),
				'per_page'    => $per_page,
			)
		);
	}

	protected function column_name( $item ) {
		$out = sprintf( '<strong><a href="%s">%s</a></strong>', esc_url( onf_donor_url( $item->id ) ), esc_html( onf_donor_name( $item ) ) );
		if ( $item->company && trim( $item->first_name . $item->last_name ) ) {
			$out .= '<br>' . esc_html( $item->company );
		}
		return $out . $this->row_actions(
			array(
				'edit'  => sprintf( '<a href="%s">%s</a>', esc_url( onf_donor_url( $item->id ) ), esc_html__( 'Open', 'onf-core' ) ),
				'gifts' => sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=onf-gifts&donor_id=' . (int) $item->id ) ), esc_html__( 'Gifts', 'onf-core' ) ),
			)
		);
	}

	protected function column_email( $item ) {
		return $item->email ? esc_html( $item->email ) : '—';
	}

	protected function column_location( $item ) {
		return esc_html( trim( $item->city . ( $item->state ? ', ' . $item->state : '' ), ', ' ) );
	}

	protected function column_gifts( $item ) {
		return (int) $item->gift_count;
	}

	protected function column_total( $item ) {
		return esc_html( onf_money( $item->total ) );
	}

	protected function column_last_gift( $item ) {
		return $item->last_gift ? esc_html( mysql2date( get_option( 'date_format' ), $item->last_gift ) ) : '—';
	}

	public function no_items() {
		esc_html_e( 'No donors found.', 'onf-core' );
	}
}

function onf_render_donors_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
	$donor = sanitize_key( $_GET['donor'] ?? '' );
	if ( 'new' === $donor ) {
		onf_render_donor_edit( null );
		return;
	}
	if ( $donor ) {
		$row = onf_get_donor( (int) $donor );
		if ( ! $row ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Donor not found', 'onf-core' ) . '</h1><p><a href="' . esc_url( onf_donor_url() ) . '">' . esc_html__( 'Back to donors', 'onf-core' ) . '</a></p></div>';
			return;
		}
		onf_render_donor_edit( $row );
		return;
	}

	$table = new ONF_Donors_List_Table();
	$table->prepare_items();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	$export = wp_nonce_url( add_query_arg( array_filter( array( 'action' => 'onf_export_donors', 's' => $search ) ), admin_url( 'admin-post.php' ) ), 'onf_export_donors' );
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Donors', 'onf-core' ); ?></h1>
		<a href="<?php echo esc_url( onf_donor_url( 'new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add donor', 'onf-core' ); ?></a>
		<a href="<?php echo esc_url( $export ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'onf-core' ); ?></a>
		<hr class="wp-header-end">
		<?php onf_render_gift_notice(); ?>
		<form method="get">
			<input type="hidden" name="page" value="onf-donors">
			<?php
			$table->search_box( __( 'Search donors', 'onf-core' ), 'onf-donor-search' );
			$table->display();
			?>
		</form>
	</div>
	<?php
}

function onf_render_donor_edit( $donor ) {
	$is_new = ! $donor;
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php echo esc_html( $is_new ? __( 'Add donor', 'onf-core' ) : onf_donor_name( $donor ) ); ?></h1>
		<a href="<?php echo esc_url( onf_donor_url() ); ?>" class="page-title-action"><?php esc_html_e( 'All donors', 'onf-core' ); ?></a>
		<?php if ( ! $is_new ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=onf-add-gift&donor_id=' . (int) $donor->id ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add gift from this donor', 'onf-core' ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end">
		<?php onf_render_gift_notice(); ?>

		<?php if ( ! $is_new ) : ?>
			<?php
			$stats = onf_query_donors( array( 'id' => $donor->id ) );
			$stats = reset( $stats );
			?>
			<p>
				<?php
				printf(
					/* translators: 1: total, 2: number of gifts, 3: first gift date, 4: last gift date */
					esc_html__( 'Total given: %1$s · %2$d gifts · first %3$s · last %4$s', 'onf-core' ),
					'<strong>' . esc_html( onf_money( $stats->total ?? 0 ) ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
					(int) ( $stats->gift_count ?? 0 ),
					esc_html( ! empty( $stats->first_gift ) ? mysql2date( get_option( 'date_format' ), $stats->first_gift ) : '—' ),
					esc_html( ! empty( $stats->last_gift ) ? mysql2date( get_option( 'date_format' ), $stats->last_gift ) : '—' )
				);
				?>
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="onf_save_donor">
			<input type="hidden" name="donor_id" value="<?php echo (int) ( $donor->id ?? 0 ); ?>">
			<?php wp_nonce_field( 'onf_save_donor' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( onf_donor_fields() as $field => $label ) : ?>
					<tr>
						<th><label for="onf-donor-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<?php if ( 'notes' === $field ) : ?>
								<textarea id="onf-donor-notes" name="notes" rows="4" class="large-text"><?php echo esc_textarea( $donor->notes ?? '' ); ?></textarea>
							<?php else : ?>
								<input type="<?php echo 'email' === $field ? 'email' : 'text'; ?>" id="onf-donor-<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $donor->$field ?? '' ); ?>" class="regular-text">
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p class="description"><?php esc_html_e( 'Changing details here does not change past gifts or receipts; they keep the name and email given at the time.', 'onf-core' ); ?></p>
			<?php submit_button( $is_new ? __( 'Add donor', 'onf-core' ) : __( 'Save donor', 'onf-core' ) ); ?>
		</form>

		<?php
		if ( $is_new ) {
			echo '</div>';
			return;
		}
		onf_render_donor_gifts( (int) $donor->id );
		onf_render_donor_merge( $donor );
		?>

		<h2><?php esc_html_e( 'Delete', 'onf-core' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="onf_delete_donor">
			<input type="hidden" name="donor_id" value="<?php echo (int) $donor->id; ?>">
			<?php wp_nonce_field( 'onf_delete_donor_' . $donor->id ); ?>
			<p><?php esc_html_e( 'Only donors with no gifts can be deleted.', 'onf-core' ); ?></p>
			<?php submit_button( __( 'Delete donor', 'onf-core' ), 'delete', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Delete this donor?', 'onf-core' ) ) . "');" ) ); ?>
		</form>
	</div>
	<?php
}

function onf_render_donor_gifts( int $donor_id ) {
	$gifts = onf_gift_query( array( 'donor_id' => $donor_id ) );
	echo '<h2>' . esc_html__( 'Gifts', 'onf-core' ) . '</h2>';
	if ( ! $gifts ) {
		echo '<p>' . esc_html__( 'No gifts yet.', 'onf-core' ) . '</p>';
		return;
	}
	echo '<table class="widefat striped" style="max-width:1000px"><thead><tr>';
	foreach ( array( __( 'Date', 'onf-core' ), __( 'Amount', 'onf-core' ), __( 'For', 'onf-core' ), __( 'Shown as', 'onf-core' ), __( 'Source', 'onf-core' ), __( 'Status', 'onf-core' ) ) as $heading ) {
		echo '<th>' . esc_html( $heading ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $gifts as $g ) {
		$for   = array_filter( array( $g->player_name, $g->event_name, $g->fund_name ) );
		$shown = $g->anonymous ? __( 'Anonymous', 'onf-core' ) : ( $g->display_name ? $g->display_name : trim( $g->donor_first_name . ' ' . $g->donor_last_name ) );
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( mysql2date( get_option( 'date_format' ), $g->gift_date ) ),
			esc_html( onf_money( $g->amount ) ),
			esc_html( $for ? implode( ' · ', $for ) : __( 'General', 'onf-core' ) ),
			esc_html( $shown ),
			esc_html( trim( ( onf_gift_sources()[ $g->source ] ?? $g->source ) . ' ' . ( $g->method ? '· ' . ( onf_gift_methods()[ $g->method ] ?? $g->method ) : '' ) . ' ' . $g->reference ) ),
			esc_html( onf_gift_statuses()[ $g->status ] ?? $g->status )
		);
	}
	echo '</tbody></table>';
}

function onf_render_donor_merge( $donor ) {
	?>
	<h2><?php esc_html_e( 'Merge with another donor', 'onf-core' ); ?></h2>
	<p><?php esc_html_e( 'Same person under two records (e.g. two emails)? Pick the other record: its gifts move here, blank details here are filled from it, and the other record is removed.', 'onf-core' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="onf_merge_donor">
		<input type="hidden" name="into_id" value="<?php echo (int) $donor->id; ?>">
		<?php wp_nonce_field( 'onf_merge_donor_' . $donor->id ); ?>
		<?php onf_donor_picker( 'from_pick', __( 'Donor to merge into this one', 'onf-core' ), (int) $donor->id ); ?>
		<?php submit_button( __( 'Merge into this donor', 'onf-core' ), 'secondary', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Merge the chosen donor into this one? This cannot be undone.', 'onf-core' ) ) . "');" ) ); ?>
	</form>
	<?php
}

/**
 * Type-ahead donor picker (a text input with a datalist). Submits "Name — email (#ID)".
 */
function onf_donor_picker( string $name, string $placeholder, int $exclude = 0, int $selected = 0 ) {
	static $printed = array();
	$list_id = 'onf-donor-list-' . $exclude;
	$value   = '';
	if ( $selected && ( $donor = onf_get_donor( $selected ) ) ) {
		$value = onf_donor_pick_label( $donor );
	}
	printf(
		'<input type="text" name="%s" list="%s" value="%s" placeholder="%s" class="regular-text" autocomplete="off" aria-label="%s">',
		esc_attr( $name ),
		esc_attr( $list_id ),
		esc_attr( $value ),
		esc_attr( $placeholder ),
		esc_attr( $placeholder )
	);
	if ( isset( $printed[ $list_id ] ) ) {
		return;
	}
	$printed[ $list_id ] = true;
	echo '<datalist id="' . esc_attr( $list_id ) . '">';
	foreach ( onf_query_donors( array( 'orderby' => 'name', 'order' => 'ASC' ) ) as $donor ) {
		if ( (int) $donor->id !== $exclude ) {
			echo '<option value="' . esc_attr( onf_donor_pick_label( $donor ) ) . '"></option>';
		}
	}
	echo '</datalist>';
}

function onf_donor_pick_label( $donor ) {
	return onf_donor_name( $donor ) . ( $donor->email ? ' — ' . $donor->email : '' ) . ' (#' . (int) $donor->id . ')';
}

/**
 * Donor ID from a picker value ("… (#123)"), or 0.
 */
function onf_donor_id_from_pick( $value ) {
	return is_string( $value ) && preg_match( '/\(#(\d+)\)\s*$/', $value, $m ) ? (int) $m[1] : 0;
}

add_action(
	'admin_post_onf_save_donor',
	static function () {
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_save_donor' );
		$input    = wp_unslash( $_POST );
		$donor_id = absint( $input['donor_id'] ?? 0 );
		$data     = array_intersect_key( $input, onf_donor_fields() );

		if ( $donor_id ) {
			$result = onf_update_donor( $donor_id, $data );
			$back   = onf_donor_url( $donor_id );
		} else {
			$result   = onf_create_donor( $data );
			$donor_id = is_wp_error( $result ) ? 0 : $result;
			$back     = onf_donor_url( $donor_id ? $donor_id : 'new' );
		}
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'onf_error', rawurlencode( $result->get_error_message() ), $back ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'onf_msg', 'donor_saved', $back ) );
		exit;
	}
);

add_action(
	'admin_post_onf_merge_donor',
	static function () {
		$into_id = absint( $_POST['into_id'] ?? 0 );
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_merge_donor_' . $into_id );
		$from_id = onf_donor_id_from_pick( wp_unslash( $_POST['from_pick'] ?? '' ) );
		$result  = $from_id ? onf_merge_donors( $from_id, $into_id ) : new WP_Error( 'onf_pick', __( 'Pick a donor from the list.', 'onf-core' ) );
		$back    = onf_donor_url( $into_id );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'onf_error', rawurlencode( $result->get_error_message() ), $back ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'onf_msg', 'donor_merged', $back ) );
		exit;
	}
);

add_action(
	'admin_post_onf_delete_donor',
	static function () {
		$donor_id = absint( $_POST['donor_id'] ?? 0 );
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_delete_donor_' . $donor_id );
		$result = onf_delete_donor( $donor_id );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'onf_error', rawurlencode( $result->get_error_message() ), onf_donor_url( $donor_id ) ) );
			exit;
		}
		wp_safe_redirect( onf_donor_url( 0, array( 'onf_msg' => 'donor_deleted' ) ) );
		exit;
	}
);

add_action(
	'admin_post_onf_export_donors',
	static function () {
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_export_donors' );
		$donors = onf_query_donors(
			array(
				'search'  => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=onf-donors-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'ID', 'First name', 'Last name', 'Email', 'Company', 'Phone', 'Address', 'Address 2', 'City', 'State', 'ZIP', 'Gifts', 'Total given', 'First gift', 'Last gift', 'Notes' ) );
		foreach ( $donors as $d ) {
			onf_csv_row( $out, array( $d->id, $d->first_name, $d->last_name, $d->email, $d->company, $d->phone, $d->address1, $d->address2, $d->city, $d->state, $d->zip, $d->gift_count, $d->total, $d->first_gift, $d->last_gift, $d->notes ) );
		}
		fclose( $out );
		exit;
	}
);

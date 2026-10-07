<?php
/**
 * PDF fundraising report built from the Totals (Gifts → Totals → "PDF report").
 * Pick the years and the sections to include; it downloads as a landscape PDF with the
 * ONF logo on top and "Prepared by Madkel" in the footer.
 */

defined( 'ABSPATH' ) || exit;

const ONF_REPORT_NAVY = array( 0.059, 0.165, 0.247 ); // #0F2A3F
const ONF_REPORT_GRAY = array( 0.42, 0.45, 0.5 );
const ONF_REPORT_INK  = array( 0.12, 0.12, 0.12 );

function onf_report_sections() {
	return array(
		'summary' => __( 'Summary: totals, gifts and donors by year', 'onf-core' ),
		'series'  => __( 'Event series by year', 'onf-core' ),
		'grids'   => __( 'Campaign grids (years × funds) for the series ticked below', 'onf-core' ),
		'funds'   => __( 'Funds by year', 'onf-core' ),
		'players' => __( 'Top fundraisers', 'onf-core' ),
	);
}

/**
 * Report options form (shown on the Totals screen).
 */
function onf_render_report_form() {
	global $wpdb;
	$years = array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT YEAR(gift_date) FROM ' . onf_gifts_table() . ' ORDER BY 1' ) );
	if ( ! $years ) {
		return;
	}
	$series = onf_series_options();
	asort( $series );
	?>
	<h2 id="onf-report"><?php esc_html_e( 'PDF report', 'onf-core' ); ?></h2>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank">
		<input type="hidden" name="action" value="onf_totals_pdf">
		<?php wp_nonce_field( 'onf_totals_pdf', '_wpnonce', false ); ?>
		<table class="form-table" role="presentation">
			<tr><th><label for="onf-report-title"><?php esc_html_e( 'Title', 'onf-core' ); ?></label></th>
				<td><input type="text" id="onf-report-title" name="title" class="regular-text" value="<?php esc_attr_e( 'Fundraising Report', 'onf-core' ); ?>">
				<input type="text" name="subtitle" class="regular-text" placeholder="<?php esc_attr_e( 'Subtitle, e.g. Prepared for the board (optional)', 'onf-core' ); ?>" aria-label="<?php esc_attr_e( 'Subtitle', 'onf-core' ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Years', 'onf-core' ); ?></th>
				<td><label><?php esc_html_e( 'From', 'onf-core' ); ?> <select name="from">
					<?php foreach ( $years as $y ) : ?>
						<option value="<?php echo (int) $y; ?>" <?php selected( $y, reset( $years ) ); ?>><?php echo (int) $y; ?></option>
					<?php endforeach; ?>
				</select></label>
				<label><?php esc_html_e( 'to', 'onf-core' ); ?> <select name="to">
					<?php foreach ( $years as $y ) : ?>
						<option value="<?php echo (int) $y; ?>" <?php selected( $y, end( $years ) ); ?>><?php echo (int) $y; ?></option>
					<?php endforeach; ?>
				</select></label></td></tr>
			<tr><th><?php esc_html_e( 'Include', 'onf-core' ); ?></th>
				<td>
					<?php foreach ( onf_report_sections() as $key => $label ) : ?>
						<label><input type="checkbox" name="sections[]" value="<?php echo esc_attr( $key ); ?>" checked> <?php echo esc_html( $label ); ?></label><br>
					<?php endforeach; ?>
					<label><?php esc_html_e( 'How many top fundraisers', 'onf-core' ); ?> <input type="number" name="top" value="10" min="3" max="50" class="small-text"></label>
				</td></tr>
			<tr><th><?php esc_html_e( 'Series for the campaign grids', 'onf-core' ); ?></th>
				<td>
					<?php foreach ( $series as $term_id => $name ) : ?>
						<label><input type="checkbox" name="series[]" value="<?php echo (int) $term_id; ?>" checked> <?php echo esc_html( $name ); ?></label><br>
					<?php endforeach; ?>
				</td></tr>
		</table>
		<?php submit_button( __( 'Create PDF report', 'onf-core' ), 'primary', 'submit', false ); ?>
	</form>
	<?php
}

/**
 * Report layout helper: page header/footer, section titles and tables that flow over pages.
 */
class ONF_Report {

	/** @var ONF_PDF */
	public $pdf;
	public $y;
	private $title;
	private $left   = 40;
	private $right  = 752;
	private $bottom = 556;

	public function __construct( $title, $subtitle, $range_label ) {
		$this->pdf   = new ONF_PDF( true );
		$this->title = $title;

		// First page: big header.
		$logo_h = $this->pdf->jpeg( ONF_CORE_DIR . 'assets/receipt-logo.jpg', $this->left, 36, 230 );
		$this->pdf->text( $this->right, 52, onf_setting( 'org_tagline' ), 9, 'italic', ONF_REPORT_GRAY, 'right' );
		$this->y = 36 + $logo_h + 34;
		$this->pdf->text( $this->left, $this->y, $title, 22, 'bold', ONF_REPORT_NAVY );
		$this->y += 18;
		$this->pdf->text( $this->left, $this->y, trim( $subtitle ? $subtitle . '  ·  ' . $range_label : $range_label ), 11, 'regular', ONF_REPORT_GRAY );
		$this->y += 26;
	}

	public function new_page() {
		$this->pdf->add_page();
		$this->pdf->jpeg( ONF_CORE_DIR . 'assets/receipt-logo.jpg', $this->left, 28, 120 );
		$this->pdf->text( $this->right, 40, $this->title, 9, 'regular', ONF_REPORT_GRAY, 'right' );
		$this->pdf->line( $this->left, 54, $this->right, 54 );
		$this->y = 78;
	}

	public function need( $height ) {
		if ( $this->y + $height > $this->bottom ) {
			$this->new_page();
		}
	}

	public function heading( $text, $note = '' ) {
		$this->need( 70 );
		$this->y += 6;
		$this->pdf->text( $this->left, $this->y, $text, 14, 'bold', ONF_REPORT_NAVY );
		$this->y += 8;
		$this->pdf->rect( $this->left, $this->y, 36, 2, array( 0.161, 0.671, 0.886 ) ); // logo blue accent
		$this->y += 14;
		if ( $note ) {
			$this->pdf->text( $this->left, $this->y, $note, 9, 'italic', ONF_REPORT_GRAY );
			$this->y += 18;
		}
	}

	public function subheading( $text ) {
		$this->need( 60 );
		$this->y += 6;
		$this->pdf->text( $this->left, $this->y, $text, 11, 'bold', ONF_REPORT_INK );
		$this->y += 12;
	}

	/**
	 * Big figure tiles: [ [label, value], … ].
	 */
	public function tiles( array $tiles ) {
		$this->need( 60 );
		$w = ( $this->right - $this->left - 12 * ( count( $tiles ) - 1 ) ) / count( $tiles );
		$x = $this->left;
		foreach ( $tiles as $tile ) {
			$this->pdf->rect( $x, $this->y, $w, 48, array( 0.957, 0.965, 0.973 ) );
			$this->pdf->text( $x + 12, $this->y + 17, strtoupper( $tile[0] ), 8, 'bold', ONF_REPORT_GRAY );
			$this->pdf->text( $x + 12, $this->y + 38, $tile[1], 18, 'bold', ONF_REPORT_NAVY );
			$x += $w + 12;
		}
		$this->y += 64;
	}

	/**
	 * Table that continues on the next page (header repeated).
	 *
	 * @param array $cols  [ [ label, width, align ], … ] — widths in points; one width may be 0 = the rest.
	 * @param array $rows  Each row: array of cell strings. A row with key 'bold' => true is a total row.
	 * @param int   $size  Font size.
	 */
	public function table( array $cols, array $rows, $size = 9 ) {
		$fixed = array_sum( array_column( $cols, 1 ) );
		foreach ( $cols as $i => $col ) {
			if ( 0 === $col[1] ) {
				$cols[ $i ][1] = max( 60, $this->right - $this->left - $fixed );
			}
		}
		$row_h  = $size + 8;
		$header = function () use ( $cols, $size, $row_h ) {
			$x = $this->left;
			$this->pdf->rect( $this->left, $this->y, array_sum( array_column( $cols, 1 ) ), $row_h + 2, ONF_REPORT_NAVY );
			foreach ( $cols as $col ) {
				$tx = 'right' === $col[2] ? $x + $col[1] - 6 : $x + 6;
				$this->pdf->text( $tx, $this->y + $row_h - 3, $this->pdf->fit( $col[0], $col[1] - 10, $size, 'bold' ), $size, 'bold', array( 1, 1, 1 ), $col[2] );
				$x += $col[1];
			}
			$this->y += $row_h + 2;
		};
		$this->need( $row_h * 3 );
		$header();
		foreach ( $rows as $n => $row ) {
			if ( $this->y + $row_h > $this->bottom ) {
				$this->new_page();
				$header();
			}
			$bold = ! empty( $row['bold'] );
			unset( $row['bold'] );
			if ( $bold ) {
				$this->pdf->rect( $this->left, $this->y, array_sum( array_column( $cols, 1 ) ), $row_h, array( 0.88, 0.91, 0.94 ) );
			} elseif ( $n % 2 ) {
				$this->pdf->rect( $this->left, $this->y, array_sum( array_column( $cols, 1 ) ), $row_h, array( 0.965, 0.973, 0.98 ) );
			}
			$x = $this->left;
			foreach ( array_values( $row ) as $i => $cell ) {
				$col = $cols[ $i ];
				$tx  = 'right' === $col[2] ? $x + $col[1] - 6 : $x + 6;
				$cell = 'right' === $col[2] ? $cell : $this->pdf->fit( $cell, $col[1] - 10, $size, $bold ? 'bold' : 'regular' ); // Numbers are never shortened.
				$this->pdf->text( $tx, $this->y + $row_h - 4, $cell, $size, $bold ? 'bold' : 'regular', ONF_REPORT_INK, $col[2] );
				$x += $col[1];
			}
			$this->y += $row_h;
		}
		$this->y += 18;
	}

	/**
	 * Rows × years table with a total column, from [ id => [ year => total ] ].
	 */
	public function year_table( array $data, array $years, callable $label, $first_col ) {
		$used = array();
		foreach ( $data as $cells ) {
			foreach ( array_keys( $cells ) as $y ) {
				$used[ $y ] = true;
			}
		}
		$cols_years = array_values( array_filter( $years, static fn( $y ) => isset( $used[ $y ] ) ) );
		if ( ! $cols_years ) {
			$this->pdf->text( $this->left, $this->y, __( 'Nothing in these years.', 'onf-core' ), 9, 'italic', ONF_REPORT_GRAY );
			$this->y += 20;
			return;
		}
		$label_w = count( $cols_years ) > 9 ? 150 : 170;
		$col_w   = min( 70, ( $this->right - $this->left - $label_w ) / ( count( $cols_years ) + 1 ) );
		$cols    = array( array( $first_col, $label_w, 'left' ) );
		foreach ( $cols_years as $y ) {
			$cols[] = array( (string) $y, $col_w, 'right' );
		}
		$cols[] = array( __( 'Total', 'onf-core' ), $col_w, 'right' );

		$rows = array();
		$down = array();
		uasort( $data, static fn( $a, $b ) => array_sum( $b ) <=> array_sum( $a ) );
		foreach ( $data as $id => $cells ) {
			$row = array( $label( $id ) );
			foreach ( $cols_years as $y ) {
				$v          = $cells[ $y ] ?? 0;
				$down[ $y ] = ( $down[ $y ] ?? 0 ) + $v;
				$row[]      = onf_whole_dollars( $v );
			}
			$row[]  = onf_whole_dollars( array_sum( $cells ) );
			$rows[] = $row;
		}
		$total = array( __( 'Total', 'onf-core' ) );
		foreach ( $cols_years as $y ) {
			$total[] = onf_whole_dollars( $down[ $y ] ?? 0 );
		}
		$total[]         = onf_whole_dollars( array_sum( $down ) );
		$total['bold']   = true;
		$rows[]          = $total;
		$this->table( $cols, $rows, count( $cols_years ) > 9 ? 8 : 9 );
	}

	/**
	 * Footers on every page: generated date + page n of N on the left, "Prepared by" + Madkel logo on the right.
	 */
	public function finish() {
		$count = $this->pdf->page_count();
		for ( $i = 0; $i < $count; $i++ ) {
			$this->pdf->set_page( $i );
			$this->pdf->line( $this->left, 572, $this->right, 572 );
			/* translators: 1: date, 2: page, 3: pages */
			$this->pdf->text( $this->left, 588, sprintf( __( '%1$s  ·  Page %2$d of %3$d', 'onf-core' ), wp_date( get_option( 'date_format' ) ), $i + 1, $count ), 8, 'regular', ONF_REPORT_GRAY );
			$logo_w = 62;
			$this->pdf->jpeg( ONF_CORE_DIR . 'assets/madkel-logo.jpg', $this->right - $logo_w, 579, $logo_w );
			$this->pdf->text( $this->right - $logo_w - 6, 588, __( 'Prepared by', 'onf-core' ), 8, 'regular', ONF_REPORT_GRAY, 'right' );
		}
		return $this->pdf->output();
	}
}

/**
 * Build the report.
 *
 * @param array $o title, subtitle, from, to, sections[], series[], top.
 * @return string PDF bytes.
 */
function onf_build_totals_report( array $o ) {
	global $wpdb;
	$from     = (int) $o['from'];
	$to       = (int) $o['to'];
	$sections = (array) $o['sections'];
	$range    = $from === $to ? (string) $from : $from . '–' . $to;
	$t        = onf_totals_data( $from, $to );
	$names    = onf_series_options();
	$r        = new ONF_Report( $o['title'], $o['subtitle'], $range );

	if ( in_array( 'summary', $sections, true ) ) {
		$stats    = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT YEAR(gift_date) AS y, COUNT(*) AS gifts, COUNT(DISTINCT NULLIF(donor_id, 0)) AS donors, SUM(amount) AS total FROM ' . onf_gifts_table() . "
				WHERE status = 'completed' AND type = 'donation' AND YEAR(gift_date) BETWEEN %d AND %d GROUP BY y ORDER BY y",
				$from,
				$to
			)
		);
		$all_time = (float) $wpdb->get_var( 'SELECT SUM(amount) FROM ' . onf_gifts_table() . " WHERE status = 'completed' AND type = 'donation'" );
		$donors   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT donor_id) FROM ' . onf_gifts_table() . " WHERE status = 'completed' AND type = 'donation' AND donor_id > 0 AND YEAR(gift_date) BETWEEN %d AND %d", $from, $to ) );
		$sum      = array_sum( wp_list_pluck( $stats, 'total' ) );
		$gifts    = array_sum( wp_list_pluck( $stats, 'gifts' ) );

		/* translators: %s: year range */
		$r->heading( __( 'Summary', 'onf-core' ), __( 'Completed donations only; refunds excluded. Includes GiveWP history.', 'onf-core' ) );
		$r->tiles(
			array(
				/* translators: %s: year range */
				array( sprintf( __( 'Raised %s', 'onf-core' ), $range ), onf_whole_dollars( $sum ) ),
				array( __( 'Raised all-time', 'onf-core' ), onf_whole_dollars( $all_time ) ),
				array( __( 'Gifts', 'onf-core' ), number_format( $gifts ) ),
				array( __( 'Donors', 'onf-core' ), number_format( $donors ) ),
			)
		);
		$rows = array();
		foreach ( $stats as $s ) {
			$rows[] = array( (string) $s->y, number_format( (int) $s->gifts ), number_format( (int) $s->donors ), onf_whole_dollars( (float) $s->total / max( 1, (int) $s->gifts ) ), onf_whole_dollars( $s->total ) );
		}
		$rows[] = array( __( 'Total', 'onf-core' ), number_format( $gifts ), number_format( $donors ), onf_whole_dollars( $sum / max( 1, $gifts ) ), onf_whole_dollars( $sum ), 'bold' => true );
		$r->table(
			array(
				array( __( 'Year', 'onf-core' ), 120, 'left' ),
				array( __( 'Gifts', 'onf-core' ), 110, 'right' ),
				array( __( 'Donors', 'onf-core' ), 110, 'right' ),
				array( __( 'Average gift', 'onf-core' ), 120, 'right' ),
				array( __( 'Raised', 'onf-core' ), 130, 'right' ),
			),
			$rows
		);
	}

	if ( in_array( 'series', $sections, true ) ) {
		$r->heading( __( 'Event series by year', 'onf-core' ), __( 'Each yearly event counted in its event\'s year.', 'onf-core' ) );
		$r->year_table(
			$t['by_series'],
			$t['years'],
			static function ( $id ) use ( $names ) {
				if ( -1 === $id ) {
					return __( 'General / not tied to an event', 'onf-core' );
				}
				return $id ? ( $names[ $id ] ?? '#' . $id ) : __( 'Events with no series', 'onf-core' );
			},
			__( 'Series', 'onf-core' )
		);
	}

	if ( in_array( 'grids', $sections, true ) ) {
		$chosen = array_map( 'intval', (array) $o['series'] );
		$first  = true;
		foreach ( $chosen as $term_id ) {
			$grid = onf_series_grid_data( $term_id, $from, $to );
			if ( ! $grid['cols'] ) {
				continue;
			}
			if ( $first ) {
				$r->heading( __( 'Campaigns by year and fund', 'onf-core' ), __( 'Across a row: that year\'s campaign, all funds together. Down a column: that fund over the years.', 'onf-core' ) );
				$first = false;
			}
			$r->subheading( $names[ $term_id ] ?? '#' . $term_id );
			$years_count = array_count_values( wp_list_pluck( $grid['rows'], 'year' ) );
			$label_w     = 140;
			$col_w       = min( 135, ( 712 - $label_w ) / ( count( $grid['cols'] ) + 1 ) );
			$cols        = array( array( __( 'Year', 'onf-core' ), $label_w, 'left' ) );
			foreach ( $grid['cols'] as $label ) {
				$cols[] = array( $label, $col_w, 'right' );
			}
			$cols[] = array( __( 'Campaign total', 'onf-core' ), $col_w, 'right' );
			$rows   = array();
			$down   = array();
			foreach ( $grid['rows'] as $row ) {
				$line = array( $years_count[ $row['year'] ] > 1 ? $row['title'] : (string) $row['year'] );
				foreach ( array_keys( $grid['cols'] ) as $col ) {
					$v            = $row['cells'][ $col ] ?? 0;
					$down[ $col ] = ( $down[ $col ] ?? 0 ) + $v;
					$line[]       = onf_whole_dollars( $v );
				}
				$line[] = onf_whole_dollars( array_sum( $row['cells'] ) );
				$rows[] = $line;
			}
			$total = array( __( 'All years', 'onf-core' ) );
			foreach ( array_keys( $grid['cols'] ) as $col ) {
				$total[] = onf_whole_dollars( $down[ $col ] ?? 0 );
			}
			$total[]       = onf_whole_dollars( array_sum( $down ) );
			$total['bold'] = true;
			$rows[]        = $total;
			$r->table( $cols, $rows );
		}
	}

	if ( in_array( 'funds', $sections, true ) ) {
		$r->heading( __( 'Funds by year', 'onf-core' ), __( 'Counted in the year the gift was made.', 'onf-core' ) );
		$r->year_table( $t['by_fund'], $t['years'], static fn( $id ) => get_the_title( $id ), __( 'Fund', 'onf-core' ) );
	}

	if ( in_array( 'players', $sections, true ) ) {
		$top     = max( 3, min( 50, (int) $o['top'] ) );
		$players = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT player_id, COUNT(*) AS gifts, COUNT(DISTINCT NULLIF(event_id, 0)) AS events, SUM(amount) AS total FROM ' . onf_gifts_table() . "
				WHERE status = 'completed' AND type = 'donation' AND player_id > 0 AND YEAR(gift_date) BETWEEN %d AND %d
				GROUP BY player_id ORDER BY total DESC LIMIT %d",
				$from,
				$to,
				$top
			)
		);
		/* translators: 1: number, 2: year range */
		$r->heading( sprintf( __( 'Top %1$d fundraisers, %2$s', 'onf-core' ), $top, $range ) );
		$rows = array();
		foreach ( $players as $i => $p ) {
			$rows[] = array( (string) ( $i + 1 ), get_the_title( $p->player_id ), number_format( (int) $p->events ), number_format( (int) $p->gifts ), onf_whole_dollars( $p->total ) );
		}
		$r->table(
			array(
				array( '#', 40, 'right' ),
				array( __( 'Player', 'onf-core' ), 260, 'left' ),
				array( __( 'Events', 'onf-core' ), 90, 'right' ),
				array( __( 'Gifts', 'onf-core' ), 90, 'right' ),
				array( __( 'Raised', 'onf-core' ), 120, 'right' ),
			),
			$rows
		);
	}

	return $r->finish();
}

add_action(
	'admin_post_onf_totals_pdf',
	static function () {
		if ( ! current_user_can( ONF_GIFTS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'onf-core' ) );
		}
		check_admin_referer( 'onf_totals_pdf' );
		$in       = wp_unslash( $_GET );
		$from     = absint( $in['from'] ?? 0 );
		$to       = absint( $in['to'] ?? 0 );
		$sections = array_values( array_intersect( (array) ( $in['sections'] ?? array() ), array_keys( onf_report_sections() ) ) );
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		$pdf = onf_build_totals_report(
			array(
				'title'    => sanitize_text_field( $in['title'] ?? '' ) ? sanitize_text_field( $in['title'] ) : __( 'Fundraising Report', 'onf-core' ),
				'subtitle' => sanitize_text_field( $in['subtitle'] ?? '' ),
				'from'     => $from,
				'to'       => $to ? $to : (int) current_time( 'Y' ),
				'sections' => $sections ? $sections : array_keys( onf_report_sections() ),
				'series'   => array_map( 'absint', (array) ( $in['series'] ?? array() ) ),
				'top'      => absint( $in['top'] ?? 10 ),
			)
		);
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename=' . sanitize_file_name( 'ONF-Fundraising-Report-' . $from . '-' . $to . '.pdf' ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}
);

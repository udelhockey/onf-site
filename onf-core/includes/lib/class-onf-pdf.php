<?php
/**
 * A small PDF writer for receipts and reports: text in the built-in Helvetica fonts, lines,
 * filled rectangles, JPEG images, several pages, portrait or landscape. No external library needed.
 * Coordinates are in points from the TOP-left of the page (US Letter: 612 × 792).
 */

defined( 'ABSPATH' ) || exit;

class ONF_PDF {

	public $width;
	public $height;

	private $pages   = array( array() );
	private $current = 0;
	private $images  = array(); // path => [ data, width, height, cs, name ]

	private $fonts = array(
		'regular' => 'Helvetica',
		'bold'    => 'Helvetica-Bold',
		'italic'  => 'Helvetica-Oblique',
	);

	/**
	 * Helvetica widths (1/1000 em) for the characters money and numbers use; other characters
	 * are estimated. Enough to right-align figures exactly.
	 */
	private static $widths = array(
		'0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
		'$' => 556, ',' => 278, '.' => 278, ' ' => 278, '-' => 333, '%' => 889, '(' => 333, ')' => 333,
	);

	public function __construct( $landscape = false ) {
		$this->width  = $landscape ? 792 : 612;
		$this->height = $landscape ? 612 : 792;
	}

	public function add_page() {
		$this->pages[] = array();
		$this->current = count( $this->pages ) - 1;
		return $this->current;
	}

	public function page_count() {
		return count( $this->pages );
	}

	/** Go back to a page (e.g. to add "Page 2 of 5" footers at the end). */
	public function set_page( $index ) {
		$this->current = max( 0, min( (int) $index, count( $this->pages ) - 1 ) );
	}

	/**
	 * Width of a string in points (exact for digits and money, estimated for other text).
	 */
	public function text_width( $text, $size = 11, $style = 'regular' ) {
		$w = 0;
		foreach ( preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( isset( self::$widths[ $ch ] ) ) {
				$w += self::$widths[ $ch ];
			} elseif ( '—' === $ch ) {
				$w += 1000;
			} elseif ( preg_match( '/[A-Z]/', $ch ) ) {
				$w += 667;
			} elseif ( preg_match( '/[ilj\'|]/', $ch ) ) {
				$w += 240;
			} elseif ( preg_match( '/[mw]/', $ch ) ) {
				$w += 833;
			} else {
				$w += 540;
			}
		}
		return $w * $size / 1000 * ( 'bold' === $style ? 1.06 : 1 );
	}

	/**
	 * @param float  $x     Left edge.
	 * @param float  $y     Baseline, from the top.
	 * @param string $style regular|bold|italic.
	 * @param array  $rgb   0–1 floats.
	 * @param string $align left|right (right: $x is the right edge).
	 */
	public function text( $x, $y, $text, $size = 11, $style = 'regular', array $rgb = array( 0.2, 0.2, 0.2 ), $align = 'left' ) {
		if ( 'right' === $align ) {
			$x -= $this->text_width( $text, $size, $style );
		}
		$font                            = array_search( $style, array_keys( $this->fonts ), true ) + 1;
		$this->pages[ $this->current ][] = sprintf(
			'BT %.3F %.3F %.3F rg /F%d %.2F Tf %.2F %.2F Td (%s) Tj ET',
			$rgb[0],
			$rgb[1],
			$rgb[2],
			$font,
			$size,
			$x,
			$this->height - $y,
			$this->escape( $text )
		);
	}

	/**
	 * Shorten text to fit a width, adding "…".
	 */
	public function fit( $text, $width, $size = 11, $style = 'regular' ) {
		$text = (string) $text;
		if ( $this->text_width( $text, $size, $style ) <= $width ) {
			return $text;
		}
		while ( mb_strlen( $text ) > 1 && $this->text_width( $text . '…', $size, $style ) > $width ) {
			$text = mb_substr( $text, 0, -1 );
		}
		return rtrim( $text ) . '…';
	}

	/**
	 * Word-wrapped paragraph. Width is estimated (Helvetica averages ~0.5em per character).
	 *
	 * @return float The y below the last line.
	 */
	public function paragraph( $x, $y, $width, $text, $size = 11, $style = 'regular', $leading = 1.45, array $rgb = array( 0.2, 0.2, 0.2 ) ) {
		$chars = max( 20, (int) floor( $width / ( $size * 0.5 ) ) );
		foreach ( preg_split( "/\r\n|\n/", (string) $text ) as $para ) {
			foreach ( explode( "\n", wordwrap( $para, $chars, "\n", true ) ) as $line ) {
				$this->text( $x, $y, $line, $size, $style, $rgb );
				$y += $size * $leading;
			}
		}
		return $y;
	}

	public function line( $x1, $y1, $x2, $y2, array $rgb = array( 0.85, 0.85, 0.85 ), $width = 0.75 ) {
		$this->pages[ $this->current ][] = sprintf( '%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S', $rgb[0], $rgb[1], $rgb[2], $width, $x1, $this->height - $y1, $x2, $this->height - $y2 );
	}

	public function rect( $x, $y, $w, $h, array $rgb ) {
		$this->pages[ $this->current ][] = sprintf( '%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f', $rgb[0], $rgb[1], $rgb[2], $x, $this->height - $y - $h, $w, $h );
	}

	/**
	 * Place a JPEG. Height follows the image's aspect ratio. The same file is stored once.
	 *
	 * @return float Image height in points (0 if the file isn't a JPEG).
	 */
	public function jpeg( $path, $x, $y, $width ) {
		if ( ! isset( $this->images[ $path ] ) ) {
			$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $info || IMAGETYPE_JPEG !== $info[2] ) {
				return 0;
			}
			$this->images[ $path ] = array(
				'data'   => file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				'width'  => $info[0],
				'height' => $info[1],
				'cs'     => ( isset( $info['channels'] ) && 1 === $info['channels'] ) ? '/DeviceGray' : '/DeviceRGB',
				'name'   => 'Im' . ( count( $this->images ) + 1 ),
			);
		}
		$img                             = $this->images[ $path ];
		$height                          = $width * $img['height'] / $img['width'];
		$this->pages[ $this->current ][] = sprintf( 'q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $width, $height, $x, $this->height - $y - $height, $img['name'] );
		return $height;
	}

	/**
	 * @return string The PDF file contents.
	 */
	public function output() {
		$objects = array(
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
		);
		$next    = 3;

		$font_refs = '';
		foreach ( array_values( $this->fonts ) as $i => $base ) {
			$objects[ $next ] = "<< /Type /Font /Subtype /Type1 /BaseFont /$base /Encoding /WinAnsiEncoding >>";
			$font_refs       .= sprintf( ' /F%d %d 0 R', $i + 1, $next );
			++$next;
		}
		$xobjects = '';
		foreach ( $this->images as $img ) {
			$objects[ $next ] = sprintf(
				"<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
				$img['width'],
				$img['height'],
				$img['cs'],
				strlen( $img['data'] ),
				$img['data']
			);
			$xobjects        .= sprintf( ' /%s %d 0 R', $img['name'], $next );
			++$next;
		}
		$resources = '<< /Font <<' . $font_refs . ' >>' . ( $xobjects ? ' /XObject <<' . $xobjects . ' >>' : '' ) . ' >>';

		$kids = array();
		foreach ( $this->pages as $ops ) {
			$stream           = implode( "\n", $ops );
			$content          = $next++;
			$page             = $next++;
			$objects[ $content ] = sprintf( "<< /Length %d >>\nstream\n%s\nendstream", strlen( $stream ), $stream );
			$objects[ $page ]    = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources %s /Contents %d 0 R >>', $this->width, $this->height, $resources, $content );
			$kids[]              = $page . ' 0 R';
		}
		$objects[2] = sprintf( '<< /Type /Pages /Kids [%s] /Count %d >>', implode( ' ', $kids ), count( $kids ) );
		ksort( $objects );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $num => $body ) {
			$offsets[ $num ] = strlen( $pdf );
			$pdf            .= "$num 0 obj\n$body\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= sprintf( "xref\n0 %d\n0000000000 65535 f \n", count( $objects ) + 1 );
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= sprintf( "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count( $objects ) + 1, $xref );
		return $pdf;
	}

	/**
	 * UTF-8 → Windows-1252 (what WinAnsiEncoding expects), with PDF string escaping.
	 */
	private function escape( $text ) {
		$text = (string) $text;
		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $converted ) {
				$text = $converted;
			}
		}
		return strtr(
			$text,
			array(
				'\\' => '\\\\',
				'('  => '\\(',
				')'  => '\\)',
				"\r" => '',
				"\n" => ' ',
			)
		);
	}
}

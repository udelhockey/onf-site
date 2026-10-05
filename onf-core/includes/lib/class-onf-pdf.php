<?php
/**
 * A tiny single-page PDF writer for receipts: text in the built-in Helvetica fonts,
 * lines, filled rectangles and one JPEG image. No external library needed.
 * Coordinates are in points from the TOP-left of a US Letter page (612 × 792).
 */

defined( 'ABSPATH' ) || exit;

class ONF_PDF {

	const WIDTH  = 612;
	const HEIGHT = 792;

	private $ops   = array();
	private $image = null;

	private $fonts = array(
		'regular' => 'Helvetica',
		'bold'    => 'Helvetica-Bold',
		'italic'  => 'Helvetica-Oblique',
	);

	/**
	 * @param float  $x     Left edge.
	 * @param float  $y     Baseline, from the top.
	 * @param string $style regular|bold|italic.
	 * @param array  $rgb   0–1 floats.
	 */
	public function text( $x, $y, $text, $size = 11, $style = 'regular', array $rgb = array( 0.2, 0.2, 0.2 ) ) {
		$font        = array_search( $style, array_keys( $this->fonts ), true ) + 1;
		$this->ops[] = sprintf(
			'BT %.3F %.3F %.3F rg /F%d %.2F Tf %.2F %.2F Td (%s) Tj ET',
			$rgb[0],
			$rgb[1],
			$rgb[2],
			$font,
			$size,
			$x,
			self::HEIGHT - $y,
			$this->escape( $text )
		);
	}

	/**
	 * Word-wrapped paragraph. Width is estimated (Helvetica averages ~0.5em per character).
	 *
	 * @return float The y below the last line.
	 */
	public function paragraph( $x, $y, $width, $text, $size = 11, $style = 'regular', $leading = 1.45 ) {
		$chars = max( 20, (int) floor( $width / ( $size * 0.5 ) ) );
		foreach ( preg_split( "/\r\n|\n/", (string) $text ) as $para ) {
			foreach ( explode( "\n", wordwrap( $para, $chars, "\n", true ) ) as $line ) {
				$this->text( $x, $y, $line, $size, $style );
				$y += $size * $leading;
			}
		}
		return $y;
	}

	public function line( $x1, $y1, $x2, $y2, array $rgb = array( 0.85, 0.85, 0.85 ), $width = 0.75 ) {
		$this->ops[] = sprintf( '%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S', $rgb[0], $rgb[1], $rgb[2], $width, $x1, self::HEIGHT - $y1, $x2, self::HEIGHT - $y2 );
	}

	public function rect( $x, $y, $w, $h, array $rgb ) {
		$this->ops[] = sprintf( '%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f', $rgb[0], $rgb[1], $rgb[2], $x, self::HEIGHT - $y - $h, $w, $h );
	}

	/**
	 * Place a JPEG (one per document). Height follows the image's aspect ratio.
	 *
	 * @return float Image height in points.
	 */
	public function jpeg( $path, $x, $y, $width ) {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || IMAGETYPE_JPEG !== $info[2] ) {
			return 0;
		}
		$height      = $width * $info[1] / $info[0];
		$this->image = array(
			'data'   => file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			'width'  => $info[0],
			'height' => $info[1],
			'cs'     => ( isset( $info['channels'] ) && 1 === $info['channels'] ) ? '/DeviceGray' : '/DeviceRGB',
		);
		$this->ops[] = sprintf( 'q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q', $width, $height, $x, self::HEIGHT - $y - $height );
		return $height;
	}

	/**
	 * @return string The PDF file contents.
	 */
	public function output() {
		$objects = array();
		$stream  = implode( "\n", $this->ops );

		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

		$font_refs = '';
		$next      = 5;
		foreach ( array_values( $this->fonts ) as $i => $base ) {
			$objects[ $next ] = "<< /Type /Font /Subtype /Type1 /BaseFont /$base /Encoding /WinAnsiEncoding >>";
			$font_refs       .= sprintf( ' /F%d %d 0 R', $i + 1, $next );
			++$next;
		}
		$xobject = '';
		if ( $this->image ) {
			$objects[ $next ] = sprintf(
				"<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
				$this->image['width'],
				$this->image['height'],
				$this->image['cs'],
				strlen( $this->image['data'] ),
				$this->image['data']
			);
			$xobject = sprintf( ' /XObject << /Im1 %d 0 R >>', $next );
			++$next;
		}

		$objects[3] = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font <<%s >>%s >> /Contents 4 0 R >>', self::WIDTH, self::HEIGHT, $font_refs, $xobject );
		$objects[4] = sprintf( "<< /Length %d >>\nstream\n%s\nendstream", strlen( $stream ), $stream );
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

<?php
/**
 * The certificate: one A4 landscape page with the student's name, the test
 * title, the score and the date, drawn over the certificate background.
 *
 * It is an extra. Nothing here can block or delay a finish: every reason a
 * certificate cannot be made (the setting is off, the pass mark was missed,
 * the background is not on this server, a font is missing, the server has no
 * mbstring) ends in available() being false, and the rest of the plugin
 * carries on without it.
 *
 * A certificate is built on demand from the attempt's stored score and is
 * never written to disk. It holds no answers.
 *
 * Follows docs/prototypes/certificate.html, the "TBT fonts" lettering. Every
 * position and size there is a share of the picture's width or height; the
 * same shares are used here on a 297 × 210 mm page.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Certificate {

	/*
	 * The colours. A PDF cannot read CSS tokens, so the three the certificate
	 * uses are written here once, each copying a token in tbt-tokens.css.
	 * TBTTM_Mail is the only other place in the plugin with colour values.
	 */

	/** --tbt-blue: the name, the title and the score. */
	const BLUE = '#0856C9';

	/** --tbt-ink: the captions. */
	const INK = '#1F2937';

	/** --tbt-muted: the date. */
	const MUTED = '#667085';

	/** The default background picture. */
	const DEFAULT_BACKGROUND = 'https://thebluetree.pl/wp-content/uploads/2026/03/tbt_certificate_background_tree_left_signature.jpg';

	/** A4 landscape, in millimetres. */
	const PAGE_W = 297.0;
	const PAGE_H = 210.0;

	/** The text column: where it starts and how wide it is, as shares of the page width. */
	const COLUMN_LEFT  = 0.22;
	const COLUMN_WIDTH = 0.56;

	/** A long name or title shrinks to this share of its size, and no further. */
	const MIN_SCALE = 0.5;

	/**
	 * The seven lines, top to bottom. `y` is the line's centre as a share of
	 * the page height; `size` the font size as a share of the page width;
	 * `spacing` the letter-spacing in em. Only the name and the title shrink.
	 */
	const LINES = array(
		'certify'   => array( 'y' => 0.218, 'size' => 0.0135, 'font' => 'medium', 'color' => self::INK, 'spacing' => 0.14, 'fit' => false ),
		'name'      => array( 'y' => 0.293, 'size' => 0.031, 'font' => 'slab', 'color' => self::BLUE, 'spacing' => 0.0, 'fit' => true ),
		'completed' => array( 'y' => 0.364, 'size' => 0.0135, 'font' => 'medium', 'color' => self::INK, 'spacing' => 0.14, 'fit' => false ),
		'title'     => array( 'y' => 0.431, 'size' => 0.023, 'font' => 'slab', 'color' => self::BLUE, 'spacing' => 0.0, 'fit' => true ),
		'achieving' => array( 'y' => 0.49, 'size' => 0.0135, 'font' => 'medium', 'color' => self::INK, 'spacing' => 0.14, 'fit' => false ),
		'score'     => array( 'y' => 0.547, 'size' => 0.018, 'font' => 'slab', 'color' => self::BLUE, 'spacing' => 0.06, 'fit' => false ),
		'date'      => array( 'y' => 0.602, 'size' => 0.012, 'font' => 'regular', 'color' => self::MUTED, 'spacing' => 0.0, 'fit' => false ),
	);

	/** The three fonts, vendored as .ttf in assets/fonts/. */
	const FONTS = array(
		'slab'    => 'RobotoSlab-Bold.ttf',
		'medium'  => 'Roboto-Medium.ttf',
		'regular' => 'Roboto-Regular.ttf',
	);

	/* ---------------------------------------------------------------------
	 * Who gets one
	 * ------------------------------------------------------------------- */

	/**
	 * Did this attempt earn a certificate? A finished attempt on a test with
	 * Certificate on, where the test has no pass mark or the attempt reached
	 * it. The pass mark is the test's current one, as everywhere else.
	 *
	 * @param object      $attempt Attempt row.
	 * @param object|null $test    Test row.
	 * @return bool
	 */
	public static function earned( $attempt, $test ) {
		if ( ! $attempt || ! $test || empty( $test->certificate ) ) {
			return false;
		}
		if ( ! isset( $attempt->status ) || 'finished' !== $attempt->status ) {
			return false;
		}
		if ( null === $test->pass_mark || '' === $test->pass_mark ) {
			return true;
		}
		return (int) $attempt->percent >= (int) $test->pass_mark;
	}

	/**
	 * Can this attempt's certificate be made right now? Earned, and
	 * everything the PDF needs is on this server.
	 *
	 * @param object      $attempt Attempt row.
	 * @param object|null $test    Test row.
	 * @return bool
	 */
	public static function available( $attempt, $test ) {
		return self::earned( $attempt, $test ) && '' === self::problem();
	}

	/**
	 * What keeps certificates from being made on this server, or ''. One
	 * word, for the settings page to explain: mbstring, library, font,
	 * background.
	 *
	 * @return string
	 */
	public static function problem() {
		static $problem = null;
		if ( null !== $problem ) {
			return $problem;
		}
		$problem = '';
		// The PDF library converts text with mbstring; WordPress's own
		// stand-ins for a missing extension do not cover what it calls.
		if ( ! function_exists( 'mb_convert_encoding' ) || ! function_exists( 'mb_strlen' ) ) {
			$problem = 'mbstring';
		} elseif ( ! is_readable( self::library_dir() . 'tfpdf.php' ) || ! is_readable( self::library_dir() . 'font/unifont/ttfonts.php' ) ) {
			$problem = 'library';
		} elseif ( defined( '_SYSTEM_TTFONTS' ) && _SYSTEM_TTFONTS !== self::fonts_dir() ) {
			// Another plugin's copy of the library has claimed the font folder.
			$problem = 'font';
		} elseif ( '' === self::background_path() ) {
			$problem = 'background';
		} else {
			foreach ( self::FONTS as $file ) {
				if ( ! is_readable( self::fonts_dir() . $file ) ) {
					$problem = 'font';
					break;
				}
			}
		}
		return $problem;
	}

	private static function library_dir() {
		return TBTTM_DIR . 'includes/lib/tfpdf/';
	}

	private static function fonts_dir() {
		return TBTTM_DIR . 'assets/fonts/';
	}

	/* ---------------------------------------------------------------------
	 * The background
	 * ------------------------------------------------------------------- */

	/**
	 * The background picture's URL: the setting, or the default.
	 *
	 * @return string
	 */
	public static function background_url() {
		$url = trim( (string) get_option( 'tbttm_certificate_bg', '' ) );
		return '' !== $url ? $url : self::DEFAULT_BACKGROUND;
	}

	/**
	 * The background as a file on this server, or '' when it is not one.
	 * The picture is only ever read from the uploads folder: the plugin
	 * never fetches it over HTTP, so the setting cannot make the server call
	 * another address.
	 *
	 * @return string Absolute path, or ''.
	 */
	public static function background_path() {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		return self::local_upload( self::background_url(), (string) $uploads['baseurl'], (string) $uploads['basedir'] );
	}

	/**
	 * Turn an uploads URL into the path of a readable JPEG inside the
	 * uploads folder, or ''.
	 *
	 * The URL must begin with the uploads URL (http and https are the same
	 * place). What follows is joined to the uploads folder, resolved, and
	 * accepted only if the result is still inside that folder: a ".." or a
	 * symlink that leads out is refused. Then it must be a file, readable,
	 * and a JPEG by its content, not by its name.
	 *
	 * @param string $url     The setting.
	 * @param string $baseurl The uploads URL.
	 * @param string $basedir The uploads folder.
	 * @return string
	 */
	public static function local_upload( $url, $baseurl, $basedir ) {
		$strip   = static function ( $u ) {
			return rtrim( (string) preg_replace( '#^https?:#i', '', trim( (string) $u ) ), '/' );
		};
		$base    = $strip( $baseurl );
		$url     = (string) preg_replace( '/[?#].*$/', '', (string) preg_replace( '#^https?:#i', '', trim( (string) $url ) ) );
		$basedir = rtrim( (string) $basedir, '/\\' );
		if ( '' === $base || '' === $basedir || 0 !== strpos( $url, $base . '/' ) ) {
			return '';
		}
		$relative = rawurldecode( substr( $url, strlen( $base ) + 1 ) );
		if ( '' === $relative || false !== strpos( $relative, "\0" ) || preg_match( '#(^|[\\\\/])\.\.([\\\\/]|$)#', $relative ) ) {
			return '';
		}
		$root = realpath( $basedir );
		$path = realpath( $basedir . '/' . $relative );
		if ( false === $root || false === $path || 0 !== strpos( $path, $root . DIRECTORY_SEPARATOR ) ) {
			return '';
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) || IMAGETYPE_JPEG !== ( $info[2] ?? 0 ) ) {
			return '';
		}
		return $path;
	}

	/* ---------------------------------------------------------------------
	 * What it says
	 * ------------------------------------------------------------------- */

	/**
	 * The student's name as the certificate prints it. A guest's name as
	 * typed. A logged-in student's first and last name when both are set,
	 * otherwise their display name.
	 *
	 * @param object $attempt Attempt row.
	 * @return string
	 */
	public static function name( $attempt ) {
		if ( (int) $attempt->user_id <= 0 ) {
			return trim( (string) $attempt->guest_name );
		}
		$user = get_userdata( (int) $attempt->user_id );
		if ( ! $user ) {
			return '';
		}
		$first = trim( (string) $user->first_name );
		$last  = trim( (string) $user->last_name );
		return '' !== $first && '' !== $last ? $first . ' ' . $last : trim( (string) $user->display_name );
	}

	/**
	 * "13 November 2026": the day the attempt finished, in the site's time
	 * zone, always in English.
	 *
	 * @param string $finished_gmt MySQL datetime, UTC.
	 * @return string
	 */
	public static function date( $finished_gmt ) {
		try {
			$when = new DateTimeImmutable( (string) $finished_gmt, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return '';
		}
		$zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		// PHP's own date(), not WordPress's: month names stay English.
		return $when->setTimezone( $zone )->format( 'j F Y' );
	}

	/**
	 * The seven lines of text, by key. Always English, whatever the site
	 * language, so nothing here goes through translation.
	 *
	 * @param object $attempt Finished attempt row.
	 * @param object $test    Test row.
	 * @return array<string, string>
	 */
	public static function lines( $attempt, $test ) {
		return array(
			'certify'   => 'THIS IS TO CERTIFY THAT',
			'name'      => self::name( $attempt ),
			'completed' => 'HAS SUCCESSFULLY COMPLETED',
			'title'     => trim( (string) $test->title ),
			'achieving' => 'ACHIEVING THE SCORE OF',
			'score'     => (int) $attempt->score . ' OUT OF ' . (int) $attempt->total,
			'date'      => self::date( (string) $attempt->finished_at ),
		);
	}

	/**
	 * "certificate-present-simple.pdf": the download's name, from the test
	 * title.
	 *
	 * @param object $test Test row.
	 * @return string
	 */
	public static function filename( $test ) {
		$slug = sanitize_title( (string) $test->title );
		// A long title is cut on a hyphen, so the name stays a sensible length.
		if ( strlen( $slug ) > 80 ) {
			$slug = rtrim( substr( $slug, 0, 80 ), '-' );
		}
		return '' !== $slug ? 'certificate-' . $slug . '.pdf' : 'certificate.pdf';
	}

	/* ---------------------------------------------------------------------
	 * Fitting a long line
	 * ------------------------------------------------------------------- */

	/**
	 * Make a line fit the column. A line wider than the column is scaled
	 * down, to no less than half its size. One still too wide at half size
	 * is cut, a letter at a time, and ends with an ellipsis. Nothing wraps.
	 *
	 * @param string   $text    The line.
	 * @param float    $size    Its font size.
	 * @param float    $max     The column width.
	 * @param callable $measure fn( string $text, float $size ): float width.
	 * @return array{0: string, 1: float} The text and the size to print it at.
	 */
	public static function fit( $text, $size, $max, callable $measure ) {
		$text  = (string) $text;
		$width = $measure( $text, $size );
		if ( $width <= $max || $width <= 0 ) {
			return array( $text, $size );
		}
		// A line's width follows its size, so this scale makes it exactly
		// as wide as the column, unless that would take it below half size.
		$small = $size * max( self::MIN_SCALE, $max / $width );
		// Allow for rounding: a line that fits to a millionth fits.
		$limit = $max * 1.000001;
		if ( $measure( $text, $small ) <= $limit ) {
			return array( $text, $small );
		}
		$letters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$letters = is_array( $letters ) ? $letters : array();
		while ( $letters ) {
			array_pop( $letters );
			$cut = rtrim( implode( '', $letters ) ) . '…';
			if ( $measure( $cut, $small ) <= $limit ) {
				return array( $cut, $small );
			}
		}
		return array( '…', $small );
	}

	/* ---------------------------------------------------------------------
	 * Making it
	 * ------------------------------------------------------------------- */

	/**
	 * Load the vendored PDF library and this plugin's subclass of it. The
	 * library's classes are loaded only when nothing else has declared
	 * them: another plugin may ship the same library.
	 */
	private static function load_library() {
		if ( ! defined( '_SYSTEM_TTFONTS' ) ) {
			// Where the library looks for a font file first.
			define( '_SYSTEM_TTFONTS', self::fonts_dir() );
		}
		if ( ! class_exists( 'TTFontFile', false ) ) {
			require_once self::library_dir() . 'font/unifont/ttfonts.php';
		}
		if ( ! class_exists( 'tFPDF', false ) ) {
			require_once self::library_dir() . 'tfpdf.php';
		}
		if ( ! class_exists( 'TBTTM_PDF', false ) ) {
			require_once TBTTM_DIR . 'includes/class-tbttm-pdf.php';
		}
	}

	/**
	 * The certificate as a PDF, or '' when it cannot be made. Never throws.
	 *
	 * @param object $attempt Finished attempt row.
	 * @param object $test    Test row.
	 * @return string PDF bytes, or ''.
	 */
	public static function pdf( $attempt, $test ) {
		if ( ! self::available( $attempt, $test ) ) {
			return '';
		}
		try {
			self::load_library();
			return self::draw( self::lines( $attempt, $test ), self::background_path(), (string) $test->title );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'TBT Test Maker: the certificate could not be made: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return '';
		}
	}

	/**
	 * Draw the page.
	 *
	 * @param array<string, string> $lines      Text by line key.
	 * @param string                $background Path of the background JPEG.
	 * @param string                $title      The test title, for the PDF's own title.
	 * @return string PDF bytes.
	 */
	public static function draw( array $lines, $background, $title = '' ) {
		$pdf = new TBTTM_PDF( 'L', 'mm', 'A4' );
		$pdf->SetAutoPageBreak( false );
		$pdf->SetMargins( 0, 0, 0 );
		$pdf->SetCompression( function_exists( 'gzcompress' ) );
		$pdf->SetTitle( 'Certificate' . ( '' !== $title ? ': ' . $title : '' ), true );
		$pdf->SetCreator( 'TBT Test Maker', true );
		$pdf->AddPage();
		// The background fills the page, as it fills the prototype's frame.
		$pdf->Image( $background, 0, 0, self::PAGE_W, self::PAGE_H, 'JPG' );

		foreach ( self::FONTS as $key => $file ) {
			$pdf->AddFont( 'tbttm' . $key, '', $file, true );
		}

		$left   = self::PAGE_W * self::COLUMN_LEFT;
		$column = self::PAGE_W * self::COLUMN_WIDTH;

		foreach ( self::LINES as $key => $line ) {
			$text = isset( $lines[ $key ] ) ? (string) $lines[ $key ] : '';
			if ( '' === $text ) {
				continue;
			}
			$family  = 'tbttm' . $line['font'];
			$spacing = (float) $line['spacing'];
			// Sizes are worked in millimetres; the library takes points.
			$size    = self::PAGE_W * $line['size'];
			$measure = static function ( $t, $mm ) use ( $pdf, $family, $spacing ) {
				$pdf->SetFont( $family, '', $mm * 72 / 25.4 );
				return $pdf->spaced_width( $t, $spacing );
			};
			if ( $line['fit'] ) {
				list( $text, $size ) = self::fit( $text, $size, $column, $measure );
			}
			$width = $measure( $text, $size );

			list( $r, $g, $b ) = self::rgb( $line['color'] );
			$pdf->SetTextColor( $r, $g, $b );

			// Centred on the column, and vertically on the line's point: the
			// baseline sits below the centre by half the font's own height
			// above the baseline minus half its depth below it.
			$metrics  = self::vertical_metrics( self::fonts_dir() . self::FONTS[ $line['font'] ] );
			$baseline = self::PAGE_H * $line['y'] + ( $metrics['ascent'] - $metrics['descent'] ) / 2 * $size;
			$pdf->spaced_text( $left + ( $column - $width ) / 2, $baseline, $text, $spacing );
		}

		return (string) $pdf->Output( 'S' );
	}

	/**
	 * A font's ascent and descent, each as a share of the font size, as a
	 * browser lays the line out (the hhea table). This is what puts a line's
	 * centre where the prototype's CSS puts it.
	 *
	 * @param string $file Path of a TrueType font.
	 * @return array{ascent: float, descent: float} Both positive.
	 */
	public static function vertical_metrics( $file ) {
		static $cache = array();
		if ( isset( $cache[ $file ] ) ) {
			return $cache[ $file ];
		}
		// A sensible fallback, close to both families, if the tables cannot be read.
		$out  = array(
			'ascent'  => 0.93,
			'descent' => 0.24,
		);
		$data = is_readable( $file ) ? file_get_contents( $file, false, null, 0, 4096 ) : false;
		if ( is_string( $data ) && strlen( $data ) >= 12 ) {
			$tables = array();
			$count  = unpack( 'n', substr( $data, 4, 2 ) )[1];
			for ( $i = 0; $i < $count && 28 + $i * 16 <= strlen( $data ); $i++ ) {
				$entry                              = substr( $data, 12 + $i * 16, 16 );
				$tables[ substr( $entry, 0, 4 ) ] = unpack( 'N', substr( $entry, 8, 4 ) )[1];
			}
			if ( isset( $tables['head'], $tables['hhea'] ) ) {
				$head = file_get_contents( $file, false, null, $tables['head'] + 18, 2 );
				$hhea = file_get_contents( $file, false, null, $tables['hhea'] + 4, 4 );
				if ( is_string( $head ) && 2 === strlen( $head ) && is_string( $hhea ) && 4 === strlen( $hhea ) ) {
					$units = unpack( 'n', $head )[1];
					$asc   = unpack( 'n', substr( $hhea, 0, 2 ) )[1];
					$desc  = unpack( 'n', substr( $hhea, 2, 2 ) )[1];
					// Both are signed 16-bit numbers; the descent is negative.
					$asc  = $asc >= 32768 ? $asc - 65536 : $asc;
					$desc = $desc >= 32768 ? $desc - 65536 : $desc;
					if ( $units > 0 && $asc > 0 ) {
						$out = array(
							'ascent'  => $asc / $units,
							'descent' => abs( $desc ) / $units,
						);
					}
				}
			}
		}
		$cache[ $file ] = $out;
		return $out;
	}

	/**
	 * "#0856C9" as three numbers.
	 *
	 * @param string $hex Colour constant.
	 * @return int[]
	 */
	public static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}
}

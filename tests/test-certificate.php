<?php
/**
 * Standalone tests for TBTTM_Certificate: who gets a certificate, the name
 * on it, how a long line is made to fit, the date, and the file name. Then
 * one real PDF, when this PHP can draw one.
 *
 * WordPress is not loaded; the few functions the class calls are stubbed.
 *
 * Run: php tests/test-certificate.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'TBTTM_DIR', dirname( __DIR__ ) . '/' );

/* ---------- WordPress stubs ---------- */

$GLOBALS['tbttm_zone']    = 'Europe/Warsaw';
$GLOBALS['tbttm_users']   = array();
$GLOBALS['tbttm_uploads'] = sys_get_temp_dir() . '/tbttm-test-uploads-' . getmypid();
$GLOBALS['tbttm_bg']      = '';

function wp_timezone() {
	return new DateTimeZone( $GLOBALS['tbttm_zone'] );
}
function get_userdata( $id ) {
	return $GLOBALS['tbttm_users'][ $id ] ?? false;
}
function get_option( $key, $default = '' ) {
	return 'tbttm_certificate_bg' === $key ? $GLOBALS['tbttm_bg'] : $default;
}
function wp_get_upload_dir() {
	return array(
		'baseurl' => 'https://example.test/wp-content/uploads',
		'basedir' => $GLOBALS['tbttm_uploads'],
		'error'   => false,
	);
}
function sanitize_title( $title ) {
	$title = strtr( (string) $title, array( 'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'Ż' => 'z', 'Ś' => 's', 'Ł' => 'l' ) );
	return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' );
}

require __DIR__ . '/../includes/class-tbttm-certificate.php';

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: {$label}\n";
	}
}
function same( $actual, $expected, $label ) {
	ok( $actual === $expected, $label . ( $actual === $expected ? '' : "\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) ) );
}
function test_row( array $over = array() ) {
	return (object) array_merge(
		array(
			'id'          => 7,
			'title'       => 'Present Simple',
			'certificate' => 1,
			'pass_mark'   => null,
		),
		$over
	);
}
function attempt_row( array $over = array() ) {
	return (object) array_merge(
		array(
			'user_id'     => 0,
			'guest_name'  => 'Ola Kowalska',
			'status'      => 'finished',
			'score'       => 9,
			'total'       => 10,
			'percent'     => 90,
			'finished_at' => '2026-11-13 10:12:40',
		),
		$over
	);
}

/* ---------- who gets one ---------- */

same( TBTTM_Certificate::earned( attempt_row(), test_row() ), true, 'no pass mark: a finished attempt earns one' );
same( TBTTM_Certificate::earned( attempt_row( array( 'percent' => 0, 'score' => 0 ) ), test_row() ), true, 'no pass mark: even with nothing right' );
same( TBTTM_Certificate::earned( attempt_row( array( 'status' => 'in_progress' ) ), test_row() ), false, 'an attempt in progress earns none' );
same( TBTTM_Certificate::earned( attempt_row(), test_row( array( 'certificate' => 0 ) ) ), false, 'Certificate off: none' );
same( TBTTM_Certificate::earned( attempt_row(), test_row( array( 'certificate' => '0' ) ) ), false, 'Certificate off, as the database returns it: none' );
same( TBTTM_Certificate::earned( attempt_row( array( 'percent' => 100 ) ), test_row( array( 'certificate' => 0, 'pass_mark' => 70 ) ) ), false, 'Certificate off: none, even at 100%' );
same( TBTTM_Certificate::earned( attempt_row( array( 'percent' => 70 ) ), test_row( array( 'pass_mark' => 70 ) ) ), true, 'pass mark 70: at the mark earns one' );
same( TBTTM_Certificate::earned( attempt_row( array( 'percent' => 69 ) ), test_row( array( 'pass_mark' => 70 ) ) ), false, 'pass mark 70: just below earns none' );
same( TBTTM_Certificate::earned( attempt_row( array( 'percent' => 65 ) ), test_row( array( 'pass_mark' => '70' ) ) ), false, 'pass mark as a string from the database: 65 earns none' );
same( TBTTM_Certificate::earned( attempt_row( array( 'percent' => 71 ) ), test_row( array( 'pass_mark' => '70' ) ) ), true, 'pass mark as a string: above earns one' );
same( TBTTM_Certificate::earned( attempt_row( array( 'percent' => 100 ) ), test_row( array( 'pass_mark' => 100 ) ) ), true, 'pass mark 100: a perfect score earns one' );
same( TBTTM_Certificate::earned( attempt_row( array( 'percent' => 99 ) ), test_row( array( 'pass_mark' => 100 ) ) ), false, 'pass mark 100: 99 earns none' );
same( TBTTM_Certificate::earned( attempt_row(), null ), false, 'a test that is gone: none' );
same( TBTTM_Certificate::earned( null, test_row() ), false, 'no attempt: none' );

/* ---------- the name ---------- */

$GLOBALS['tbttm_users'] = array(
	5 => (object) array( 'first_name' => 'Żaneta', 'last_name' => 'Świątek-Wójcik', 'display_name' => 'zaneta_sw' ),
	6 => (object) array( 'first_name' => 'Jan', 'last_name' => '', 'display_name' => 'Jan from B2' ),
	7 => (object) array( 'first_name' => '', 'last_name' => 'Nowak', 'display_name' => 'nowak77' ),
	8 => (object) array( 'first_name' => '  ', 'last_name' => '  ', 'display_name' => ' Spacey ' ),
	9 => (object) array( 'first_name' => ' Anna ', 'last_name' => ' Lis ', 'display_name' => 'x' ),
);
same( TBTTM_Certificate::name( attempt_row( array( 'guest_name' => '  Milena K  ' ) ) ), 'Milena K', 'name: a guest, as typed' );
same( TBTTM_Certificate::name( attempt_row( array( 'user_id' => 5, 'guest_name' => '' ) ) ), 'Żaneta Świątek-Wójcik', 'name: first and last when both are set' );
same( TBTTM_Certificate::name( attempt_row( array( 'user_id' => 6 ) ) ), 'Jan from B2', 'name: display name when the last name is missing' );
same( TBTTM_Certificate::name( attempt_row( array( 'user_id' => 7 ) ) ), 'nowak77', 'name: display name when the first name is missing' );
same( TBTTM_Certificate::name( attempt_row( array( 'user_id' => 8 ) ) ), 'Spacey', 'name: blank names count as missing' );
same( TBTTM_Certificate::name( attempt_row( array( 'user_id' => 9 ) ) ), 'Anna Lis', 'name: trimmed' );
same( TBTTM_Certificate::name( attempt_row( array( 'user_id' => 404 ) ) ), '', 'name: a deleted user has none' );
same( TBTTM_Certificate::name( attempt_row( array( 'user_id' => 5, 'guest_name' => 'Ignored' ) ) ), 'Żaneta Świątek-Wójcik', 'name: a user\'s attempt never uses a guest name' );

/* ---------- the lines ---------- */

$lines = TBTTM_Certificate::lines( attempt_row(), test_row( array( 'title' => ' Expressing Opinions ' ) ) );
same( array_keys( $lines ), array( 'certify', 'name', 'completed', 'title', 'achieving', 'score', 'date' ), 'lines: seven, in order' );
same( $lines['certify'], 'THIS IS TO CERTIFY THAT', 'lines: first caption' );
same( $lines['completed'], 'HAS SUCCESSFULLY COMPLETED', 'lines: second caption' );
same( $lines['achieving'], 'ACHIEVING THE SCORE OF', 'lines: third caption' );
same( $lines['score'], '9 OUT OF 10', 'lines: the score' );
same( $lines['title'], 'Expressing Opinions', 'lines: the title, trimmed' );
same( array_keys( TBTTM_Certificate::LINES ), array_keys( $lines ), 'lines: every line has a place on the page' );
same( false === strpos( implode( ' ', $lines ), 'review' ), true, 'lines: no answers' );

/* ---------- fitting a long line ---------- */

// A stand-in for the PDF's ruler: every letter is 0.6 of the font size wide.
$measure = static function ( $text, $size ) {
	return mb_strlen( $text, 'UTF-8' ) * 0.6 * $size;
};
same( TBTTM_Certificate::fit( 'Short', 10.0, 100.0, $measure ), array( 'Short', 10.0 ), 'fit: a line that fits is left alone' );
same( TBTTM_Certificate::fit( str_repeat( 'a', 16 ), 10.0, 96.0, $measure ), array( str_repeat( 'a', 16 ), 10.0 ), 'fit: exactly the column width fits' );

list( $text, $size ) = TBTTM_Certificate::fit( str_repeat( 'a', 25 ), 10.0, 100.0, $measure );
same( $text, str_repeat( 'a', 25 ), 'fit: a wide line keeps all its letters' );
ok( abs( $size - 100.0 / ( 25 * 0.6 ) ) < 0.0001, 'fit: and shrinks to exactly the column: ' . $size );
ok( $measure( $text, $size ) <= 100.0 * 1.000001, 'fit: the shrunk line is inside the column' );

list( $text, $size ) = TBTTM_Certificate::fit( str_repeat( 'a', 33 ), 10.0, 100.0, $measure );
ok( $size > 5.0 && $text === str_repeat( 'a', 33 ), 'fit: just under twice the width still only shrinks' );

list( $text, $size ) = TBTTM_Certificate::fit( str_repeat( 'a', 34 ), 10.0, 100.0, $measure );
same( $size, 5.0, 'fit: never below half size' );
same( $text, str_repeat( 'a', 32 ) . '…', 'fit: too wide at half size is cut with an ellipsis' );
ok( $measure( $text, $size ) <= 100.0, 'fit: the cut line is inside the column' );

list( $text, $size ) = TBTTM_Certificate::fit( str_repeat( 'Żółć ', 40 ), 10.0, 100.0, $measure );
same( array( $size, mb_substr( $text, -1, 1, 'UTF-8' ), mb_check_encoding( $text, 'UTF-8' ) ), array( 5.0, '…', true ), 'fit: Polish letters are cut whole, never in half' );
ok( $measure( $text, $size ) <= 100.0, 'fit: and the result fits' );
same( mb_substr( $text, -2, 1, 'UTF-8' ) !== ' ', true, 'fit: no space left before the ellipsis' );
same( false === strpos( $text, "\n" ), true, 'fit: nothing wraps' );

list( $text, $size ) = TBTTM_Certificate::fit( 'abc', 10.0, 0.5, $measure );
same( $text, '…', 'fit: a column too narrow for anything prints only the ellipsis' );
same( TBTTM_Certificate::fit( '', 10.0, 100.0, $measure ), array( '', 10.0 ), 'fit: an empty line' );

/* ---------- the date ---------- */

same( TBTTM_Certificate::date( '2026-11-13 10:12:40' ), '13 November 2026', 'date: day, English month, year' );
same( TBTTM_Certificate::date( '2026-11-13 23:30:00' ), '14 November 2026', 'date: late evening UTC is already tomorrow in Warsaw' );
same( TBTTM_Certificate::date( '2026-06-30 22:30:00' ), '1 July 2026', 'date: summer time, across a month end, no leading zero' );
same( TBTTM_Certificate::date( '2026-12-31 23:00:00' ), '1 January 2027', 'date: across a year end' );
$GLOBALS['tbttm_zone'] = 'America/New_York';
same( TBTTM_Certificate::date( '2026-11-13 03:00:00' ), '12 November 2026', 'date: a site west of UTC is still on the day before' );
$GLOBALS['tbttm_zone'] = 'Europe/Warsaw';
$locale                = setlocale( LC_TIME, 0 );
setlocale( LC_TIME, 'pl_PL.UTF-8', 'pl_PL', 'de_DE.UTF-8' );
same( TBTTM_Certificate::date( '2026-11-13 10:00:00' ), '13 November 2026', 'date: English whatever the locale' );
setlocale( LC_TIME, $locale );
same( TBTTM_Certificate::date( 'not a date' ), '', 'date: junk gives nothing, not an error' );

/* ---------- the file name ---------- */

same( TBTTM_Certificate::filename( test_row() ), 'certificate-present-simple.pdf', 'file name: from the title' );
same( TBTTM_Certificate::filename( test_row( array( 'title' => 'Żółć & "quotes" / slashes?' ) ) ), 'certificate-zolc-quotes-slashes.pdf', 'file name: only safe characters' );
same( TBTTM_Certificate::filename( test_row( array( 'title' => '???' ) ) ), 'certificate.pdf', 'file name: a title with nothing usable' );
$long = TBTTM_Certificate::filename( test_row( array( 'title' => str_repeat( 'very long title ', 30 ) ) ) );
ok( strlen( $long ) <= strlen( 'certificate-.pdf' ) + 80 && '-.pdf' !== substr( $long, -5 ), 'file name: a long title is cut, not on a hyphen: ' . $long );
ok( 1 === preg_match( '/^[a-z0-9-]+\.pdf$/', $long ), 'file name: still safe after the cut' );

/* ---------- the background path ---------- */

$up = $GLOBALS['tbttm_uploads'];
@mkdir( $up . '/2026/03', 0777, true );
$outside = dirname( $up ) . '/tbttm-test-outside-' . getmypid() . '.jpg';
$can_draw_picture = function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' );
if ( $can_draw_picture ) {
	$im = imagecreatetruecolor( 198, 140 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
	imagejpeg( $im, $up . '/2026/03/bg.jpg', 80 );
	imagejpeg( $im, $outside, 80 );
	imagepng( $im, $up . '/2026/03/fake.jpg' );
	file_put_contents( $up . '/2026/03/text.jpg', 'not a picture' );
	$base = 'https://example.test/wp-content/uploads';
	$local = static function ( $url ) use ( $base, $up ) {
		return TBTTM_Certificate::local_upload( $url, $base, $up );
	};
	same( $local( $base . '/2026/03/bg.jpg' ), realpath( $up . '/2026/03/bg.jpg' ), 'background: an uploads URL becomes its file' );
	same( $local( 'http://example.test/wp-content/uploads/2026/03/bg.jpg' ), realpath( $up . '/2026/03/bg.jpg' ), 'background: http and https are the same place' );
	same( $local( $base . '/2026/03/bg.jpg?ver=2' ), realpath( $up . '/2026/03/bg.jpg' ), 'background: a query string is ignored' );
	same( $local( 'https://elsewhere.test/wp-content/uploads/2026/03/bg.jpg' ), '', 'background: another site is not on this server' );
	same( $local( 'https://example.test/wp-content/uploads-evil/2026/03/bg.jpg' ), '', 'background: a folder that only starts like uploads is refused' );
	same( $local( $base . '/2026/03/missing.jpg' ), '', 'background: a missing file' );
	same( $local( $base . '/../' . basename( $outside ) ), '', 'background: .. out of uploads is refused' );
	same( $local( $base . '/2026/03/../../../' . basename( $outside ) ), '', 'background: .. deeper in the path is refused' );
	same( $local( $base . '/2026/03/%2e%2e/%2e%2e/%2e%2e/' . basename( $outside ) ), '', 'background: encoded .. is refused' );
	same( $local( $base . '/2026/03/fake.jpg' ), '', 'background: a PNG named .jpg is refused' );
	same( $local( $base . '/2026/03/text.jpg' ), '', 'background: text named .jpg is refused' );
	same( $local( $base . '/2026/03' ), '', 'background: a folder is not a file' );
	same( $local( '' ), '', 'background: empty' );
	if ( function_exists( 'symlink' ) && @symlink( $outside, $up . '/2026/03/link.jpg' ) ) {
		same( $local( $base . '/2026/03/link.jpg' ), '', 'background: a link that leads out of uploads is refused' );
	}
}

/* ---------- one real PDF ---------- */

if ( $can_draw_picture && function_exists( 'mb_convert_encoding' ) ) {
	$GLOBALS['tbttm_bg'] = 'https://example.test/wp-content/uploads/2026/03/bg.jpg';
	same( TBTTM_Certificate::problem(), '', 'pdf: this server can make certificates' );
	$attempt = attempt_row( array( 'guest_name' => 'Żaneta Świątek-Wójcik' ) );
	same( TBTTM_Certificate::available( $attempt, test_row() ), true, 'pdf: available for an earned certificate' );
	same( TBTTM_Certificate::available( $attempt, test_row( array( 'certificate' => 0 ) ) ), false, 'pdf: not available with Certificate off' );
	same( TBTTM_Certificate::pdf( $attempt, test_row( array( 'certificate' => 0 ) ) ), '', 'pdf: nothing is made with Certificate off' );

	$before = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( TBTTM_DIR . 'includes/lib', FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		$before[] = (string) $f;
	}
	$fonts_before = glob( TBTTM_DIR . 'assets/fonts/*' );

	$pdf = TBTTM_Certificate::pdf( $attempt, test_row() );
	same( substr( $pdf, 0, 5 ), '%PDF-', 'pdf: a PDF comes back' );
	same( substr( rtrim( $pdf ), -5 ), '%%EOF', 'pdf: and it is complete' );
	same( 1 === preg_match( '#/MediaBox \[0 0 841\.89 595\.28\]#', $pdf ), true, 'pdf: one A4 landscape page' );
	same( substr_count( $pdf, '/Type /Page' . "\n" ) + substr_count( $pdf, '/Type /Page ' ) <= 1 && 1 === preg_match( '#/Count 1\b#', $pdf ), true, 'pdf: a single page' );
	same( substr_count( $pdf, '/Subtype /Image' ), 1, 'pdf: the background is in it, once' );

	$after = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( TBTTM_DIR . 'includes/lib', FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		$after[] = (string) $f;
	}
	same( $after, $before, 'pdf: the library wrote no cache file' );
	same( glob( TBTTM_DIR . 'assets/fonts/*' ), $fonts_before, 'pdf: nothing was written beside the fonts' );
	same( glob( $up . '/*.pdf' ) + glob( $up . '/*/*/*.pdf' ), array(), 'pdf: nothing was written to uploads' );

	// A long name and a long title still come out as one page, without error.
	$long_pdf = TBTTM_Certificate::pdf(
		attempt_row( array( 'guest_name' => str_repeat( 'Maximiliana Aleksandra ', 8 ) ) ),
		test_row( array( 'title' => str_repeat( 'An Unreasonably Long Title ', 12 ) ) )
	);
	same( substr( $long_pdf, 0, 5 ), '%PDF-', 'pdf: a very long name and title still make a certificate' );

	// The real ruler: the fit rule against the embedded font, on the real column.
	$column = TBTTM_Certificate::PAGE_W * TBTTM_Certificate::COLUMN_WIDTH;
	$ruler  = new TBTTM_PDF( 'L', 'mm', 'A4' );
	$ruler->AddFont( 'slab', '', TBTTM_Certificate::FONTS['slab'], true );
	$real = static function ( $t, $mm ) use ( $ruler ) {
		$ruler->SetFont( 'slab', '', $mm * 72 / 25.4 );
		return $ruler->spaced_width( $t );
	};
	$name_size = TBTTM_Certificate::PAGE_W * TBTTM_Certificate::LINES['name']['size'];
	same( TBTTM_Certificate::fit( 'Żaneta Świątek-Wójcik', $name_size, $column, $real ), array( 'Żaneta Świątek-Wójcik', $name_size ), 'real font: an ordinary Polish name prints at full size' );
	list( $t, $sz ) = TBTTM_Certificate::fit( str_repeat( 'Maximiliana Aleksandra ', 8 ), $name_size, $column, $real );
	ok( abs( $sz - $name_size / 2 ) < 0.0001 && '…' === mb_substr( $t, -1, 1, 'UTF-8' ) && $real( $t, $sz ) <= $column * 1.000001, 'real font: a very long name ends at half size, cut, inside the column' );
	ok( $ruler->spaced_width( 'AB', 0.14 ) > $ruler->spaced_width( 'AB', 0 ) && abs( $ruler->spaced_width( 'A', 0.14 ) - $ruler->spaced_width( 'A', 0 ) ) < 0.0001, 'real font: letter-spacing sits between letters only' );

	// Without the background, certificates are off and nothing breaks.
	$GLOBALS['tbttm_bg'] = 'https://example.test/wp-content/uploads/2026/03/missing.jpg';
	same( TBTTM_Certificate::background_path(), '', 'no background: the path is empty' );
} else {
	echo "note: the PDF checks were skipped (this PHP has no GD or no mbstring)\n";
}

// Tidy up the temporary uploads folder.
foreach ( array( '/2026/03/bg.jpg', '/2026/03/fake.jpg', '/2026/03/text.jpg', '/2026/03/link.jpg' ) as $f ) {
	@unlink( $up . $f );
}
@unlink( $outside );
@rmdir( $up . '/2026/03' );
@rmdir( $up . '/2026' );
@rmdir( $up );

echo "{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );

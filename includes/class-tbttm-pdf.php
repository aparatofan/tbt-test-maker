<?php
/**
 * The PDF writer the certificate is drawn with: the vendored tFPDF, plus the
 * two things it lacks. The library's own files are never edited.
 *
 *   - Letter-spacing. The certificate's captions and score are tracked out;
 *     PDF has an operator for that (Tc) and tFPDF has no method for it.
 *   - No font cache. tFPDF writes cache files beside its fonts when it can.
 *     This plugin writes nothing to disk for a certificate, so the library
 *     is pointed at a folder that does not exist and reads the fonts afresh
 *     each time.
 *
 * Loaded only by TBTTM_Certificate::load_library(), after tFPDF itself.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_PDF extends tFPDF {

	public function __construct( $orientation = 'P', $unit = 'mm', $size = 'A4' ) {
		parent::__construct( $orientation, $unit, $size );
		// tFPDF caches a font's metrics in {fontpath}unifont/ when that
		// folder is writable. This one never exists, so nothing is written.
		$this->fontpath = TBTTM_DIR . 'includes/lib/tfpdf/no-cache/';
	}

	/**
	 * The width of a line in the current font and size, with letter-spacing.
	 * The spacing sits between letters, so a line of n letters has n − 1.
	 *
	 * @param string $text    Text, UTF-8.
	 * @param float  $spacing Letter-spacing in em.
	 * @return float Width in the document's unit.
	 */
	public function spaced_width( $text, $spacing = 0.0 ) {
		$letters = max( 0, mb_strlen( (string) $text, 'UTF-8' ) - 1 );
		return $this->GetStringWidth( $text ) + $letters * $spacing * $this->FontSize;
	}

	/**
	 * Write one line with its left end at x and its baseline at y, tracked
	 * out by a letter-spacing in em.
	 *
	 * @param float  $x       Left edge, in the document's unit.
	 * @param float  $y       Baseline, in the document's unit.
	 * @param string $text    Text, UTF-8.
	 * @param float  $spacing Letter-spacing in em.
	 */
	public function spaced_text( $x, $y, $text, $spacing = 0.0 ) {
		if ( $spacing > 0 ) {
			// Tc is in points and stays set until changed, so it is put back.
			$this->_out( sprintf( '%.3F Tc', $spacing * $this->FontSizePt ) );
			$this->Text( $x, $y, $text );
			$this->_out( '0 Tc' );
			return;
		}
		$this->Text( $x, $y, $text );
	}
}

<?php
/**
 * Several labels, one file.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-pdf-merger.php';

/**
 * Tests for WC_ESM_Pdf_Merger.
 */
class Test_Pdf_Merger extends WC_ESM_Test_Case {

	/**
	 * The merger reaches for WordPress for a temporary file; the tests do
	 * not have WordPress, so PHP's own will do.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Brain\Monkey\Functions\when( 'wp_tempnam' )->alias(
			static function () {
				return tempnam( sys_get_temp_dir(), 'wcesm' );
			}
		);
	}

	/**
	 * A real PDF of a given number of pages, to merge.
	 *
	 * @param int $pages How many pages.
	 *
	 * @return string PDF bytes.
	 */
	protected function pdf( $pages = 1 ) {
		$pdf = new \setasign\Fpdi\Fpdi();

		for ( $i = 1; $i <= $pages; $i++ ) {
			$pdf->AddPage();
			$pdf->SetFont( 'Helvetica', '', 12 );
			$pdf->Cell( 40, 10, 'Label page ' . $i );
		}

		return $pdf->Output( 'S' );
	}

	/**
	 * How many pages a PDF has, counted from the file itself.
	 *
	 * @param string $bytes PDF bytes.
	 *
	 * @return int
	 */
	protected function pages( $bytes ) {
		$file = tempnam( sys_get_temp_dir(), 'wcesm' );
		file_put_contents( $file, $bytes );

		$pdf   = new \setasign\Fpdi\Fpdi();
		$count = $pdf->setSourceFile( $file );

		unlink( $file );

		return $count;
	}

	/**
	 * The library is here, so merging is on. A shop that never ran composer
	 * install is the case the notice exists for.
	 *
	 * @return void
	 */
	public function test_the_library_is_available() {
		$this->assertTrue( WC_ESM_Pdf_Merger::is_available() );
	}

	/**
	 * The notice says what to do, not merely that something is missing.
	 *
	 * @return void
	 */
	public function test_the_notice_says_what_to_do() {
		$this->assertStringContainsString( 'composer install', WC_ESM_Pdf_Merger::missing_notice() );
	}

	/**
	 * Two labels of one page each come out as one file of two pages.
	 *
	 * @return void
	 */
	public function test_two_labels_become_one_file() {
		$merged = WC_ESM_Pdf_Merger::merge( array( $this->pdf( 1 ), $this->pdf( 1 ) ) );

		$this->assertSame( 2, $this->pages( $merged ) );
	}

	/**
	 * Labels of different lengths keep every page: one carrier's two-page
	 * label plus another's single page is three pages, not two.
	 *
	 * @return void
	 */
	public function test_every_page_of_every_label_survives() {
		$merged = WC_ESM_Pdf_Merger::merge( array( $this->pdf( 1 ), $this->pdf( 2 ) ) );

		$this->assertSame( 3, $this->pages( $merged ) );
	}

	/**
	 * One label is handed back as it came: re-encoding a carrier's own PDF
	 * risks losing something for no gain.
	 *
	 * @return void
	 */
	public function test_a_single_label_is_handed_back_untouched() {
		$one = $this->pdf( 1 );

		$this->assertSame( $one, WC_ESM_Pdf_Merger::merge( array( $one ) ) );
	}

	/**
	 * Nothing to merge is an empty string, not a broken PDF.
	 *
	 * @return void
	 */
	public function test_nothing_to_merge_is_empty() {
		$this->assertSame( '', WC_ESM_Pdf_Merger::merge( array() ) );
	}

	/**
	 * Something that is not a PDF is skipped rather than taking the whole
	 * batch down: one carrier answering with an error page must not stop the
	 * other carriers' labels from printing.
	 *
	 * @return void
	 */
	public function test_a_bad_file_is_skipped_not_fatal() {
		$merged = WC_ESM_Pdf_Merger::merge( array( $this->pdf( 1 ), '<html>error</html>', $this->pdf( 1 ) ) );

		$this->assertSame( 2, $this->pages( $merged ) );
	}
}

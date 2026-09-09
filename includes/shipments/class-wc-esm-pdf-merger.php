<?php
/**
 * Several labels, one file.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts a batch of carrier labels into one PDF.
 *
 * A shopkeeper printing a screen of orders should press print once, not once
 * per carrier, so the labels are stitched together before they are streamed.
 *
 * This is the one part of the plugin that needs a library from composer. A
 * shop that installed from the wordpress.org zip and never ran composer
 * install still prints one carrier's labels perfectly well - they come back
 * as a finished PDF - and only the stitching is unavailable. So the absence
 * is reported, not fatal.
 */
class WC_ESM_Pdf_Merger {

	/**
	 * Whether the library is here.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( '\setasign\Fpdi\Fpdi' );
	}

	/**
	 * What to tell a shop that has not got it.
	 *
	 * @return string
	 */
	public static function missing_notice() {
		return __(
			'Estonian Shipping Methods: labels from more than one carrier cannot be merged into a single PDF until the PDF library is installed. Run composer install in the plugin directory. Printing one carrier at a time works either way.',
			'wc-estonian-shipping-methods'
		);
	}

	/**
	 * Merge PDFs into one.
	 *
	 * @param array $pdfs PDF documents, as bytes.
	 *
	 * @return string The merged document, or an empty string when there was
	 *                nothing to merge.
	 */
	public static function merge( $pdfs ) {
		$pdfs = array_values( array_filter( (array) $pdfs ) );

		if ( ! $pdfs ) {
			return '';
		}

		// One label is handed back as it came. Re-encoding a carrier's own
		// PDF risks losing something for no gain, and this is the common
		// case: most orders are one parcel with one carrier.
		if ( 1 === count( $pdfs ) || ! self::is_available() ) {
			return $pdfs[0];
		}

		$merged = new \setasign\Fpdi\Fpdi();
		$added  = 0;

		foreach ( $pdfs as $pdf ) {
			$added += self::append( $merged, $pdf );
		}

		if ( 0 === $added ) {
			return $pdfs[0];
		}

		return $merged->Output( 'S' );
	}

	/**
	 * Append one document's pages to the merged one.
	 *
	 * A file that will not open is skipped rather than taken as fatal: one
	 * carrier answering with an error page must not stop the other carriers'
	 * labels from printing.
	 *
	 * @param \setasign\Fpdi\Fpdi $merged Document being built.
	 * @param string              $pdf    One document's bytes.
	 *
	 * @return int How many pages were added.
	 */
	protected static function append( $merged, $pdf ) {
		$file = wp_tempnam( 'wc-esm-label' );

		if ( ! $file ) {
			return 0;
		}

		$added = 0;

		try {
			file_put_contents( $file, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			$pages = $merged->setSourceFile( $file );

			for ( $page = 1; $page <= $pages; $page++ ) {
				$template = $merged->importPage( $page );
				$size     = $merged->getTemplateSize( $template );

				$merged->AddPage( $size['orientation'], array( $size['width'], $size['height'] ) );
				$merged->useTemplate( $template );

				$added++;
			}
		} catch ( \Throwable $e ) {
			// Not a PDF, or one this library cannot read. Skip it.
			$added = 0;
		}

		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		return $added;
	}
}

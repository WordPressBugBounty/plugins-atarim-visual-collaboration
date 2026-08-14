<?php
/**
 * reSmush.it Image Optimizer detector.
 *
 * reSmush.it is a FREE, anonymous cloud optimizer — there is no API key and
 * nothing to configure, so being active is sufficient (no "configured" check).
 * It optimizes SYNCHRONOUSLY (a curl round-trip per image size, inline); it has
 * no on-demand background queue, so bulk work must be chunked.
 *
 * Our abilities DRIVE the installed plugin's own functions; they never
 * reimplement compression.
 *
 * Verified against reSmush.it Image Optimizer 1.0.6:
 *   - core class   : \reSmushit (global namespace)
 *   - per-attach   : \Resmush\Controller\ProcessController::getInstance()->process_images( $meta, $id )
 *   - status       : \reSmushit::isImageOptimized( $id ) / getStatistics( $id )
 *   - unoptimized  : \reSmushit::getNonOptimizedPictures( true ) (JSON) / getCountNonOptimizedPictures()
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_ReSmushit_Detector {

	/** Whether reSmush.it is active and its API is present. */
	public function avcf_resmushit_is_available() {
		return defined( 'RESMUSH_PLUGIN_VERSION' )
			&& class_exists( '\\reSmushit' )
			&& class_exists( '\\Resmush\\Controller\\ProcessController' );
	}
}

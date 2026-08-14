<?php
/**
 * Smush (WPMU DEV) detector.
 *
 * Smush optimizes via the WPMU DEV API. The FREE tier works with no API key /
 * login (lossless, with a per-file size limit); Smush PRO (a WPMU DEV
 * membership) adds Super-Smush (lossy), CDN and larger limits. Because free
 * works without a key, being active is sufficient — there is no "not
 * configured" refusal; Pro status is reported for information only.
 *
 * Our abilities DRIVE the installed plugin's own classes; they never
 * reimplement compression or read credentials.
 *
 * Verified against Smush 4.3.0 (namespaced Smush\Core\* OOP):
 *   - single (sync)  : \Smush\Core\Optimizer::get_instance()->optimize( $id )
 *   - bulk (async)   : \Smush\Core\Bulk\Background_Bulk_Smush_Controller::get_instance()->start_bulk_smush_direct()
 *   - per-image state: \Smush\Core\Media\Media_Item_Cache + Media_Item_Optimizer::is_optimized()
 *   - pro?           : WP_Smush::is_pro()
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_Smush_Detector {

	/** Whether Smush is active and its core API is present. */
	public function avcf_smush_is_available() {
		return defined( 'WP_SMUSH_VERSION' )
			&& class_exists( 'WP_Smush' )
			&& class_exists( '\\Smush\\Core\\Optimizer' );
	}

	/** Whether Smush Pro (WPMU DEV membership) is active — informational only. */
	public function avcf_smush_is_pro() {
		return class_exists( 'WP_Smush' ) && method_exists( 'WP_Smush', 'is_pro' ) && (bool) WP_Smush::is_pro();
	}
}

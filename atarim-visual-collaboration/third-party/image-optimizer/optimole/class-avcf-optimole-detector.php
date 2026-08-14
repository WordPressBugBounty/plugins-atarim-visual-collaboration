<?php
/**
 * Optimole detector.
 *
 * Optimole is a CDN / offload service, not an in-place library compressor:
 * once the site is CONNECTED to an Optimole account, images are optimized and
 * served automatically through Optimole's CDN. There is no per-image "optimize
 * and save bytes" action; the only library-level operations are offload (push
 * to cloud) and rollback (restore local) — not implemented here (status only).
 *
 * Our abilities read Optimole's own settings; they never read the API key.
 *
 * Verified against Optimole 4.2.10:
 *   - settings  : new \Optml_Settings() → is_connected() / is_enabled() /
 *                 is_offload_enabled() / is_offload_limit_reached() / get( $key )
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_Optimole_Detector {

	/** Whether Optimole is active and its settings API is present. */
	public function avcf_optimole_is_available() {
		return defined( 'OPTML_VERSION' ) && class_exists( 'Optml_Settings' );
	}

	/** Whether the site is connected to an Optimole account. Reads no key value. */
	public function avcf_optimole_is_connected() {
		if ( ! $this->avcf_optimole_is_available() ) {
			return false;
		}
		try {
			$settings = new \Optml_Settings();
			return method_exists( $settings, 'is_connected' ) && (bool) $settings->is_connected();
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}

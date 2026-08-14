<?php
/**
 * EWWW Image Optimizer detector.
 *
 * EWWW is a HYBRID optimizer: it optimizes LOCALLY by default (bundled/local
 * binaries, no API key required) and can optionally route through its cloud
 * API (EWWW IO key) or Easy IO CDN. Because local mode needs no key, "active"
 * is sufficient to optimize — there is deliberately no "not configured" refusal
 * (unlike cloud-only optimizers).
 *
 * Our abilities DRIVE the installed plugin's own functions; they never read the
 * API key and never reimplement compression.
 *
 * Verified against EWWW Image Optimizer 8.7.5 (procedural ewww_image_optimizer_*
 * API + ewwwio() background/scan processes + the {prefix}ewwwio_images table).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_EWWW_Detector {

	/** Whether EWWW Image Optimizer is active and its core API is present. */
	public function avcf_ewww_is_available() {
		return defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' )
			&& function_exists( 'ewwwio' )
			&& function_exists( 'ewww_image_optimizer_resize_from_meta_data' );
	}

	/** Current optimization mode: 'easy_io' | 'cloud' | 'local'. Reads no key value. */
	public function avcf_ewww_mode() {
		if ( function_exists( 'ewww_image_optimizer_easy_active' ) && ewww_image_optimizer_easy_active() ) {
			return 'easy_io';
		}
		if ( $this->avcf_ewww_cloud_key_present() ) {
			return 'cloud';
		}
		return 'local';
	}

	/** Whether a cloud API key is configured — boolean only, never the value. */
	public function avcf_ewww_cloud_key_present() {
		return function_exists( 'ewww_image_optimizer_get_option' )
			&& (bool) ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' );
	}
}

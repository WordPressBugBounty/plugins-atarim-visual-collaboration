<?php
/**
 * ShortPixel Image Optimizer detector.
 *
 * ShortPixel is a CLOUD/async optimizer: compression runs on ShortPixel's
 * servers via a background queue. Our abilities DRIVE the installed plugin
 * (enqueue work, read status); they never hold or read its API key and never
 * reimplement compression.
 *
 * Verified against ShortPixel Image Optimizer 6.5.5 (namespaced controllers +
 * custom queue). Availability is keyed on the plugin's version constant.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_ShortPixel_Detector {

	/** Whether ShortPixel Image Optimizer is active on this site. */
	public function avcf_shortpixel_is_available() {
		return defined( 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' )
			&& class_exists( '\\ShortPixel\\Controller\\QueueController' )
			&& class_exists( '\\ShortPixel\\Controller\\ApiKeyController' );
	}

	/**
	 * Whether ShortPixel is configured with a verified API key. Uses the
	 * plugin's own ApiKeyModel::is_verified() and NEVER reads the key value.
	 * Returns false if unavailable or unconfigured.
	 */
	public function avcf_shortpixel_is_configured() {
		if ( ! $this->avcf_shortpixel_is_available() ) {
			return false;
		}
		try {
			$key_control = \ShortPixel\Controller\ApiKeyController::getInstance();
			if ( ! is_object( $key_control ) || ! method_exists( $key_control, 'getKeyModel' ) ) {
				return false;
			}
			$model = $key_control->getKeyModel();
			return is_object( $model ) && method_exists( $model, 'is_verified' ) && (bool) $model->is_verified();
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}

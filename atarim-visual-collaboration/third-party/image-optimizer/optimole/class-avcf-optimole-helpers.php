<?php
/**
 * Optimole integration helpers.
 *
 * Static, reusable logic shared by the Optimole ability class(es). Reads
 * Optimole's own settings; never reads the API key. guard() supports an
 * optional connected-requirement so future offload/rollback abilities can
 * demand a connected account while status works regardless. Every call is
 * guarded (class/method existence + try/catch).
 *
 * Verified against Optimole 4.2.10.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_Optimole_Helpers {

	public static function read_meta() {
		return [
			'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		];
	}

	/**
	 * Gate. By default only requires Optimole to be active (status is meaningful
	 * even when not connected). Pass $require_connected = true for actions that
	 * need a connected account. Returns a refusal array or null to proceed.
	 */
	public static function guard( $require_connected = false ) {
		$detector = new AVCF_Optimole_Detector();
		if ( ! $detector->avcf_optimole_is_available() ) {
			return [ 'success' => false, 'message' => 'Optimole is not active on this site.' ];
		}
		if ( $require_connected && ! $detector->avcf_optimole_is_connected() ) {
			return [ 'success' => false, 'message' => 'Optimole is installed but not connected. Connect your Optimole account in Optimole settings first. (No key is read or stored by this tool.)' ];
		}
		return null;
	}

	/** Read Optimole's current connection / offload status (no key exposure). */
	public static function status() {
		$out = [
			'connected'              => false,
			'service_enabled'        => null,
			'offload_enabled'        => null,
			'offloading_in_progress' => null,
			'rollback_in_progress'   => null,
			'offload_limit'          => null,
			'offload_limit_reached'  => null,
		];
		if ( ! class_exists( 'Optml_Settings' ) ) {
			return $out;
		}
		try {
			$s = new \Optml_Settings();
			$out['connected']       = method_exists( $s, 'is_connected' ) ? (bool) $s->is_connected() : null;
			$out['service_enabled'] = method_exists( $s, 'is_enabled' ) ? (bool) $s->is_enabled() : null;
			$out['offload_enabled'] = method_exists( $s, 'is_offload_enabled' ) ? (bool) $s->is_offload_enabled() : null;
			if ( method_exists( $s, 'get' ) ) {
				$out['offloading_in_progress'] = ( 'disabled' !== $s->get( 'offloading_status' ) );
				$out['rollback_in_progress']   = ( 'disabled' !== $s->get( 'rollback_status' ) );
				$out['offload_limit']          = (int) $s->get( 'offload_limit' );
			}
			$out['offload_limit_reached'] = method_exists( $s, 'is_offload_limit_reached' ) ? (bool) $s->is_offload_limit_reached() : null;
		} catch ( \Throwable $e ) {
			// leave defaults
		}
		return $out;
	}
}

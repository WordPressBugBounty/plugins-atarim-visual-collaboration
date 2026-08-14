<?php
/**
 * Smush integration helpers.
 *
 * Static, reusable logic shared by the Smush ability class(es). Drives Smush's
 * own namespaced classes; never reimplements compression or reads credentials.
 * Single-image optimization is synchronous; "all" uses Smush's own background
 * process. Every call is guarded (class/method existence + try/catch) so a
 * plugin version change degrades to a clear message rather than a fatal.
 *
 * Verified against Smush 4.3.0.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_Smush_Helpers {

	public static function write_meta() {
		return [
			'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
		];
	}

	public static function read_meta() {
		return [
			'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		];
	}

	/**
	 * Gate: Smush active. No "configured" check — the free tier works without a
	 * key/login. Returns a refusal array or null to proceed.
	 */
	public static function guard() {
		$detector = new AVCF_Smush_Detector();
		if ( ! $detector->avcf_smush_is_available() ) {
			return [ 'success' => false, 'message' => 'Smush is not active on this site.' ];
		}
		return null;
	}

	public static function clean_ids( $ids ) {
		if ( ! is_array( $ids ) ) {
			return [];
		}
		$out = [];
		foreach ( $ids as $one ) {
			$one = (int) $one;
			if ( $one > 0 ) {
				$out[] = $one;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Optimize a list of attachments SYNCHRONOUSLY via Smush's Optimizer.
	 * Returns per-id results with optimization status.
	 */
	public static function optimize_ids( $ids ) {
		if ( ! class_exists( '\\Smush\\Core\\Optimizer' ) ) {
			return [ 'success' => false, 'message' => 'Smush optimizer is unavailable.' ];
		}
		try {
			$optimizer = \Smush\Core\Optimizer::get_instance();
		} catch ( \Throwable $e ) {
			return [ 'success' => false, 'message' => 'Smush optimizer unavailable: ' . $e->getMessage() ];
		}
		$results   = [];
		$optimized = 0;
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
				$results[] = [ 'id' => $id, 'optimized' => false, 'message' => 'Not an image attachment; skipped.' ];
				continue;
			}
			try {
				$ok     = $optimizer->optimize( $id );
				$status = self::attachment_status( $id );
				if ( false === $ok ) {
					$err = method_exists( $optimizer, 'get_errors' ) ? $optimizer->get_errors() : null;
					$msg = ( is_wp_error( $err ) && $err->get_error_message() ) ? $err->get_error_message() : 'Smush reported no optimization.';
					$results[] = array_merge( [ 'id' => $id, 'optimized' => false, 'message' => $msg ], $status );
					continue;
				}
				$optimized++;
				$results[] = array_merge( [ 'id' => $id, 'optimized' => true, 'message' => 'Optimized.' ], $status );
			} catch ( \Throwable $e ) {
				$results[] = [ 'id' => $id, 'optimized' => false, 'message' => 'Failed: ' . $e->getMessage() ];
			}
		}
		return [
			'success'   => true,
			'optimized' => $optimized,
			'results'   => $results,
			'message'   => sprintf( 'Optimized %d of %d image(s) with Smush (synchronous).', $optimized, count( $ids ) ),
		];
	}

	/** Start Smush's own background bulk optimization (scans + optimizes the library). */
	public static function start_bulk_all() {
		if ( ! class_exists( '\\Smush\\Core\\Bulk\\Background_Bulk_Smush_Controller' ) ) {
			return [ 'success' => false, 'message' => 'Smush background bulk is unavailable on this version.' ];
		}
		try {
			$ctrl = \Smush\Core\Bulk\Background_Bulk_Smush_Controller::get_instance();
			if ( ! is_object( $ctrl ) || ! method_exists( $ctrl, 'start_bulk_smush_direct' ) ) {
				return [ 'success' => false, 'message' => 'Smush background bulk controller is unavailable.' ];
			}
			$status = $ctrl->start_bulk_smush_direct();
		} catch ( \Throwable $e ) {
			return [ 'success' => false, 'message' => 'Could not start Smush bulk: ' . $e->getMessage() ];
		}
		if ( false === $status ) {
			return [ 'success' => false, 'message' => 'Smush could not start background optimization (background processing may be unavailable in this environment).' ];
		}
		return [
			'success' => true,
			'started' => true,
			'status'  => ( is_array( $status ) || is_object( $status ) ) ? (array) $status : [],
			'message' => 'Started Smush background bulk optimization; it scans and optimizes the library in the background. Poll smush-status for progress.',
		];
	}

	/** Per-attachment status via Smush's own media-item optimizer. */
	public static function attachment_status( $id ) {
		if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			return [ 'optimized' => false, 'message' => 'Not an image attachment.' ];
		}
		if ( ! class_exists( '\\Smush\\Core\\Media\\Media_Item_Cache' ) || ! class_exists( '\\Smush\\Core\\Media\\Media_Item_Optimizer' ) ) {
			return [ 'optimized' => null ];
		}
		try {
			$item = \Smush\Core\Media\Media_Item_Cache::get_instance()->get( $id );
			if ( ! is_object( $item ) ) {
				return [ 'optimized' => null ];
			}
			$optimizer = new \Smush\Core\Media\Media_Item_Optimizer( $item );
			$optimized = method_exists( $optimizer, 'is_optimized' ) ? (bool) $optimizer->is_optimized() : null;
			$percent   = null;
			if ( method_exists( $optimizer, 'get_stats' ) ) {
				try {
					$p = $optimizer->get_stats( 'percent' );
					if ( is_numeric( $p ) ) {
						$percent = $p;
					}
				} catch ( \Throwable $e ) {
					$percent = null;
				}
			}
			return [ 'optimized' => $optimized, 'percent' => $percent ];
		} catch ( \Throwable $e ) {
			return [ 'optimized' => null, 'message' => 'Status lookup failed: ' . $e->getMessage() ];
		}
	}
}

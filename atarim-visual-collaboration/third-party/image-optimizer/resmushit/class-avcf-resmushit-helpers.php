<?php
/**
 * reSmush.it integration helpers.
 *
 * Static, reusable logic shared by the reSmush.it ability class(es). Drives
 * reSmush.it's own synchronous API; never reimplements compression. reSmush.it
 * optimizes inline (a curl round-trip per image), so callers must keep batches
 * modest — there is no background queue. Every call is guarded (class/method
 * existence + try/catch) so a plugin version change degrades to a clear
 * message rather than a fatal.
 *
 * Verified against reSmush.it Image Optimizer 1.0.6.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_ReSmushit_Helpers {

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
	 * Gate: reSmush.it active. No "configured" check — it is a free, keyless
	 * service. Returns a refusal array or null to proceed.
	 */
	public static function guard() {
		$detector = new AVCF_ReSmushit_Detector();
		if ( ! $detector->avcf_resmushit_is_available() ) {
			return [ 'success' => false, 'message' => 'reSmush.it Image Optimizer is not active on this site.' ];
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
	 * Optimize a list of attachments SYNCHRONOUSLY via reSmush.it's own
	 * ProcessController. Returns per-id results including savings. Because each
	 * image is an inline API call, callers cap the list size.
	 */
	public static function optimize_ids( $ids ) {
		if ( ! class_exists( '\\Resmush\\Controller\\ProcessController' ) ) {
			return [ 'success' => false, 'message' => 'reSmush.it process controller is unavailable.' ];
		}
		try {
			$pc = \Resmush\Controller\ProcessController::getInstance();
		} catch ( \Throwable $e ) {
			return [ 'success' => false, 'message' => 'reSmush.it controller unavailable: ' . $e->getMessage() ];
		}
		$results   = [];
		$optimized = 0;
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
				$results[] = [ 'id' => $id, 'optimized' => false, 'message' => 'Not an image attachment; skipped.' ];
				continue;
			}
			try {
				$meta = wp_get_attachment_metadata( $id );
				if ( empty( $meta ) ) {
					$results[] = [ 'id' => $id, 'optimized' => false, 'message' => 'No attachment metadata; skipped.' ];
					continue;
				}
				$new_meta = $pc->process_images( $meta, $id );
				if ( is_array( $new_meta ) ) {
					wp_update_attachment_metadata( $id, $new_meta );
				}
				$status = self::attachment_status( $id );
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
			'message'   => sprintf( 'Optimized %d of %d image(s) with reSmush.it (synchronous).', $optimized, count( $ids ) ),
		];
	}

	/**
	 * IDs of not-yet-optimized attachments (up to $limit), plus the total count.
	 * Returns [ 'ids' => int[], 'total' => int ].
	 */
	public static function unoptimized_ids( $limit ) {
		$out = [ 'ids' => [], 'total' => 0 ];
		if ( ! class_exists( '\\reSmushit' ) || ! method_exists( '\\reSmushit', 'getNonOptimizedPictures' ) ) {
			return $out;
		}
		try {
			$data = json_decode( \reSmushit::getNonOptimizedPictures( true ) );
		} catch ( \Throwable $e ) {
			return $out;
		}
		if ( ! is_object( $data ) || ! isset( $data->nonoptimized ) || ! is_array( $data->nonoptimized ) ) {
			return $out;
		}
		$out['total'] = count( $data->nonoptimized );
		foreach ( $data->nonoptimized as $item ) {
			if ( isset( $item->ID ) ) {
				$out['ids'][] = (int) $item->ID;
			}
			if ( count( $out['ids'] ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** Total count of not-yet-optimized attachments. */
	public static function total_unoptimized() {
		if ( ! class_exists( '\\reSmushit' ) || ! method_exists( '\\reSmushit', 'getCountNonOptimizedPictures' ) ) {
			return null;
		}
		try {
			$counts = \reSmushit::getCountNonOptimizedPictures();
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( is_array( $counts ) && isset( $counts['nonoptimized'] ) ) {
			return (int) $counts['nonoptimized'];
		}
		return null;
	}

	/** Per-attachment status via reSmush.it's own model. */
	public static function attachment_status( $id ) {
		if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			return [ 'optimized' => false, 'message' => 'Not an image attachment.' ];
		}
		$optimized = class_exists( '\\reSmushit' ) && method_exists( '\\reSmushit', 'isImageOptimized' )
			? (bool) \reSmushit::isImageOptimized( $id )
			: null;
		$reduction = null;
		if ( class_exists( '\\reSmushit' ) && method_exists( '\\reSmushit', 'getStatistics' ) ) {
			try {
				$stats = \reSmushit::getStatistics( $id );
				if ( is_array( $stats ) && isset( $stats['percent_reduction'] ) ) {
					$reduction = $stats['percent_reduction'];
				}
			} catch ( \Throwable $e ) {
				$reduction = null;
			}
		}
		return [
			'optimized'         => $optimized,
			'percent_reduction' => $reduction,
		];
	}
}

<?php
/**
 * EWWW Image Optimizer integration helpers.
 *
 * Static, reusable logic shared by the EWWW ability class(es). Drives EWWW's
 * own procedural API; never reads the API key, never reimplements compression.
 * Every EWWW call is guarded (function/method existence + try/catch) so a
 * plugin version change degrades to a clear message rather than a fatal.
 *
 * Verified against EWWW Image Optimizer 8.7.5:
 *   - single (sync)  : ewww_image_optimizer_resize_from_meta_data( $meta, $id )
 *   - single (async) : ewww_image_optimizer_add_attachment_to_queue( $id, ... )
 *   - sync vs async  : ewww_image_optimizer_test_background_opt()
 *   - bulk (all)     : ewwwio()->async_scan->data([...])->dispatch() + background_media->dispatch()
 *   - per-image state: {prefix}ewwwio_images WHERE attachment_id = %d AND gallery = 'media'
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_EWWW_Helpers {

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
	 * Gate: EWWW active. NOTE: no "configured" check — EWWW optimizes locally
	 * without an API key, so being active is sufficient. Returns a refusal array
	 * or null to proceed.
	 */
	public static function guard() {
		$detector = new AVCF_EWWW_Detector();
		if ( ! $detector->avcf_ewww_is_available() ) {
			return [ 'success' => false, 'message' => 'EWWW Image Optimizer is not active on this site.' ];
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
	 * Optimize a list of attachments. When $force_async is true (bulk), always
	 * enqueue to EWWW's background process. Otherwise respect the site's setting
	 * (ewww_image_optimizer_test_background_opt): async → enqueue, else optimize
	 * synchronously and read back per-image savings.
	 */
	public static function optimize_ids( $ids, $force_async = false ) {
		if ( ! function_exists( 'ewwwio' ) || ! function_exists( 'ewww_image_optimizer_resize_from_meta_data' ) ) {
			return [ 'success' => false, 'message' => 'EWWW optimization functions are unavailable.' ];
		}
		$use_async = $force_async
			|| ( function_exists( 'ewww_image_optimizer_test_background_opt' ) && ewww_image_optimizer_test_background_opt() );

		$results   = [];
		$processed = 0;
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
				$results[] = [ 'id' => $id, 'done' => false, 'message' => 'Not an image attachment; skipped.' ];
				continue;
			}
			try {
				if ( $use_async ) {
					if ( ! function_exists( 'ewww_image_optimizer_add_attachment_to_queue' ) ) {
						$results[] = [ 'id' => $id, 'done' => false, 'message' => 'Background optimization unavailable on this version.' ];
						continue;
					}
					ewww_image_optimizer_add_attachment_to_queue( $id );
					$processed++;
					$results[] = [ 'id' => $id, 'done' => true, 'queued' => true, 'message' => 'Queued for background optimization.' ];
				} else {
					$meta = wp_get_attachment_metadata( $id );
					ewww_image_optimizer_resize_from_meta_data( $meta, $id );
					$status = self::attachment_status( $id );
					$processed++;
					$results[] = array_merge( [ 'id' => $id, 'done' => true, 'queued' => false, 'message' => 'Optimized.' ], $status );
				}
			} catch ( \Throwable $e ) {
				$results[] = [ 'id' => $id, 'done' => false, 'message' => 'Failed: ' . $e->getMessage() ];
			}
		}
		return [
			'success'   => true,
			'mode'      => $use_async ? 'async' : 'sync',
			'processed' => $processed,
			'results'   => $results,
			'message'   => $use_async
				? sprintf( 'Queued %d of %d image(s) for EWWW background optimization — poll ewww-status.', $processed, count( $ids ) )
				: sprintf( 'Optimized %d of %d image(s) synchronously.', $processed, count( $ids ) ),
		];
	}

	/** Kick EWWW's library-wide scan (queues all unoptimized) + start the background process. */
	public static function start_bulk_all() {
		if ( ! function_exists( 'ewwwio' ) ) {
			return [ 'success' => false, 'message' => 'EWWW is unavailable.' ];
		}
		try {
			$ewwwio = ewwwio();
			if ( ! is_object( $ewwwio ) || ! isset( $ewwwio->async_scan ) || ! isset( $ewwwio->background_media ) ) {
				return [ 'success' => false, 'message' => 'EWWW background/scan processes are unavailable on this version.' ];
			}
			$ewwwio->async_scan->data( [ 'ewww_scan' => 'scheduled' ] )->dispatch();
			$ewwwio->background_media->dispatch();
		} catch ( \Throwable $e ) {
			return [ 'success' => false, 'message' => 'Could not start EWWW bulk scan: ' . $e->getMessage() ];
		}
		return [
			'success' => true,
			'started' => true,
			'message' => 'Started an EWWW library scan; unoptimized images are queued and optimized in the background. Poll ewww-status for progress.',
		];
	}

	/** Per-attachment optimization status from EWWW's own ewwwio_images table. */
	public static function attachment_status( $id ) {
		global $wpdb;
		if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			return [ 'optimized' => false, 'message' => 'Not an image attachment.' ];
		}
		$table = $wpdb->prefix . 'ewwwio_images';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT orig_size, image_size FROM {$table} WHERE attachment_id = %d AND gallery = %s", $id, 'media' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( empty( $rows ) ) {
			return [ 'optimized' => false, 'records' => 0 ];
		}
		$orig = 0;
		$now  = 0;
		foreach ( $rows as $r ) {
			$orig += (int) $r['orig_size'];
			$now  += (int) $r['image_size'];
		}
		$saved = max( 0, $orig - $now );
		return [
			'optimized'          => true,
			'records'            => count( $rows ),
			'original_bytes'     => $orig,
			'optimized_bytes'    => $now,
			'saved_bytes'        => $saved,
			'savings_percentage' => $orig > 0 ? round( ( $saved / $orig ) * 100, 1 ) : 0,
		];
	}
}

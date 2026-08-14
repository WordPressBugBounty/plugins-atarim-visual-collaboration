<?php
/**
 * ShortPixel integration helpers.
 *
 * Static, reusable logic shared by the ShortPixel ability classes (kept
 * separate so a future ShortPixel Pro / additional cluster can reuse it).
 * Every ShortPixel call is guarded (class/method existence + try/catch) so a
 * plugin version change degrades to a clear message rather than a fatal.
 *
 * Verified against ShortPixel Image Optimizer 6.5.5.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_ShortPixel_Helpers {

	/** Write-ability meta (enqueue operations). */
	public static function write_meta() {
		return [
			'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
		];
	}

	/** Read-ability meta (status). */
	public static function read_meta() {
		return [
			'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		];
	}

	/**
	 * Gate: ShortPixel present and (optionally) configured. Returns a refusal
	 * array to hand back to the caller, or null when the call may proceed.
	 * Never reads the API key value.
	 */
	public static function guard( $require_configured = true ) {
		$detector = new AVCF_ShortPixel_Detector();
		if ( ! $detector->avcf_shortpixel_is_available() ) {
			return [ 'success' => false, 'message' => 'ShortPixel Image Optimizer is not active on this site.' ];
		}
		if ( $require_configured && ! $detector->avcf_shortpixel_is_configured() ) {
			return [ 'success' => false, 'message' => 'ShortPixel is installed but not configured. Add your API key in ShortPixel → Settings, then retry. (No key is read or stored by this tool.)' ];
		}
		return null;
	}

	/** Sanitize an ID list to unique positive integers. */
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

	/** Enqueue a list of attachment IDs into ShortPixel's queue, per-id results. */
	public static function enqueue_ids( $ids ) {
		try {
			$fs = \ShortPixel\Controller\FileSystemController::getInstance();
			$qc = new \ShortPixel\Controller\QueueController();
		} catch ( \Throwable $e ) {
			return [ 'success' => false, 'message' => 'ShortPixel controllers unavailable: ' . $e->getMessage() ];
		}
		$results = [];
		$queued  = 0;
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
				$results[] = [ 'id' => $id, 'queued' => false, 'message' => 'Not an image attachment; skipped.' ];
				continue;
			}
			try {
				$img = $fs->getMediaImage( $id );
				if ( ! is_object( $img ) ) {
					$results[] = [ 'id' => $id, 'queued' => false, 'message' => 'ShortPixel could not load this image.' ];
					continue;
				}
				if ( method_exists( $img, 'isOptimized' ) && $img->isOptimized() ) {
					$results[] = [ 'id' => $id, 'queued' => false, 'message' => 'Already optimized; skipped.' ];
					continue;
				}
				if ( method_exists( $img, 'isProcessable' ) && ! $img->isProcessable() ) {
					$results[] = [ 'id' => $id, 'queued' => false, 'message' => 'Not processable by ShortPixel; skipped.' ];
					continue;
				}
				$qc->addItemToQueue( $img );
				$queued++;
				$results[] = [ 'id' => $id, 'queued' => true, 'message' => 'Queued for optimization.' ];
			} catch ( \Throwable $e ) {
				$results[] = [ 'id' => $id, 'queued' => false, 'message' => 'Enqueue failed: ' . $e->getMessage() ];
			}
		}
		return [
			'success' => true,
			'queued'  => $queued,
			'results' => $results,
			'message' => sprintf( 'Queued %d of %d image(s) with ShortPixel. Optimization runs in the background — poll shortpixel-status.', $queued, count( $ids ) ),
		];
	}

	/** Per-image status via ShortPixel's own model. */
	public static function image_status( $id ) {
		if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			return [ 'id' => $id, 'message' => 'Not an image attachment.' ];
		}
		try {
			$fs  = \ShortPixel\Controller\FileSystemController::getInstance();
			$img = $fs->getMediaImage( $id );
			if ( ! is_object( $img ) ) {
				return [ 'id' => $id, 'message' => 'ShortPixel could not load this image.' ];
			}
			$optimized   = method_exists( $img, 'isOptimized' ) ? (bool) $img->isOptimized() : null;
			$processable = method_exists( $img, 'isProcessable' ) ? (bool) $img->isProcessable() : null;
			$savings     = null;
			if ( method_exists( $img, 'getImprovements' ) ) {
				$imp = $img->getImprovements();
				if ( is_array( $imp ) && isset( $imp['totalpercentage'] ) ) {
					$savings = $imp['totalpercentage'];
				}
			}
			return [
				'id'                 => $id,
				'optimized'          => $optimized,
				'processable'        => $processable,
				'savings_percentage' => $savings,
			];
		} catch ( \Throwable $e ) {
			return [ 'id' => $id, 'message' => 'Status lookup failed: ' . $e->getMessage() ];
		}
	}
}

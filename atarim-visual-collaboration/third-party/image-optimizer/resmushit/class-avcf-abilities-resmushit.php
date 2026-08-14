<?php
/**
 * reSmush.it Image Optimizer MCP abilities.
 *
 * Drives the installed reSmush.it plugin. reSmush.it is a FREE, keyless, and
 * SYNCHRONOUS optimizer with no background queue, so the ability shape differs
 * from cloud/async optimizers:
 *   - optimize      : a specific set of IDs, synchronously (kept modest).
 *   - optimize-all  : a chunked pass over unoptimized images, returning a
 *                     "remaining" count so the caller loops until done.
 *   - status        : keyless note + per-image state + total unoptimized count.
 *
 * See AVCF_ReSmushit_Helpers for the verified 1.0.6 entry points.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_Abilities_ReSmushit {

	/** Max IDs per synchronous optimize call. */
	const MAX_SPECIFIC = 25;
	/** Images processed per optimize-all call before returning (loop to continue). */
	const ALL_BATCH = 25;

	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$this->register_optimize();
		$this->register_optimize_all();
		$this->register_status();
	}

	/** Optimize a specific set of attachments, synchronously. */
	private function register_optimize() {
		wp_register_ability( 'atarim/resmushit-optimize', [
			'label'        => 'reSmush.it: Optimize Images',
			'description'  => 'Optimize specific media attachments with reSmush.it (max ' . self::MAX_SPECIFIC . ' per call). reSmush.it is a free, keyless service that optimizes SYNCHRONOUSLY — each image is an API round-trip, so this returns per-image savings when done, but keep batches modest to avoid long requests. Already-optimized or unsupported images are reported per-id and skipped.',
			'category'     => 'atarim',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'attachment_ids' => [ 'type' => 'array', 'maxItems' => self::MAX_SPECIFIC, 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'description' => 'Attachment IDs to optimize.' ],
				],
				'required'             => [ 'attachment_ids' ],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success'   => [ 'type' => 'boolean' ],
					'optimized' => [ 'type' => 'integer' ],
					'results'   => [ 'type' => 'array' ],
					'message'   => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) {
				$gate = AVCF_ReSmushit_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				$ids = AVCF_ReSmushit_Helpers::clean_ids( isset( $input['attachment_ids'] ) ? $input['attachment_ids'] : [] );
				if ( empty( $ids ) ) { return [ 'success' => false, 'message' => 'attachment_ids is required.' ]; }
				if ( count( $ids ) > self::MAX_SPECIFIC ) { return [ 'success' => false, 'message' => sprintf( 'Too many IDs (%d); max %d per call because reSmush.it optimizes synchronously. Use resmushit-optimize-all to work through the whole library in chunks.', count( $ids ), self::MAX_SPECIFIC ) ]; }
				return AVCF_ReSmushit_Helpers::optimize_ids( $ids );
			},
			'permission_callback' => function() { return current_user_can( 'upload_files' ); },
			'meta' => AVCF_ReSmushit_Helpers::write_meta(),
		] );
	}

	/** Optimize all unoptimized images, one chunk per call (loop until remaining is 0). */
	private function register_optimize_all() {
		wp_register_ability( 'atarim/resmushit-optimize-all', [
			'label'        => 'reSmush.it: Optimize All Unoptimized (chunked)',
			'description'  => 'Work through the media library\'s not-yet-optimized images with reSmush.it, up to ' . self::ALL_BATCH . ' per call. Because reSmush.it is synchronous with no background queue, this optimizes one chunk and returns a "remaining" count — call it again repeatedly until remaining reaches 0. Returns per-image results for the chunk it processed.',
			'category'     => 'atarim',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success'         => [ 'type' => 'boolean' ],
					'optimized'       => [ 'type' => 'integer' ],
					'remaining'       => [ 'type' => 'integer' ],
					'results'         => [ 'type' => 'array' ],
					'message'         => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) {
				$gate = AVCF_ReSmushit_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				$batch = AVCF_ReSmushit_Helpers::unoptimized_ids( self::ALL_BATCH );
				if ( empty( $batch['ids'] ) ) {
					return [ 'success' => true, 'optimized' => 0, 'remaining' => 0, 'results' => [], 'message' => 'No unoptimized images remain.' ];
				}
				$res = AVCF_ReSmushit_Helpers::optimize_ids( $batch['ids'] );
				if ( empty( $res['success'] ) ) { return $res; }
				$remaining = AVCF_ReSmushit_Helpers::total_unoptimized();
				$res['remaining'] = is_null( $remaining ) ? max( 0, (int) $batch['total'] - (int) $res['optimized'] ) : (int) $remaining;
				$res['message']   = sprintf( 'Optimized %d image(s); ~%d still unoptimized. Call again to continue.', (int) $res['optimized'], (int) $res['remaining'] );
				return $res;
			},
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			'meta' => AVCF_ReSmushit_Helpers::write_meta(),
		] );
	}

	/** Status: keyless note + per-image state + total unoptimized. */
	private function register_status() {
		wp_register_ability( 'atarim/resmushit-status', [
			'label'        => 'reSmush.it: Optimization Status',
			'description'  => 'Report reSmush.it status: it is a free, keyless service (nothing to configure), the number of images still unoptimized, and — if attachment_ids are given — per-image state (optimized? percent reduction).',
			'category'     => 'atarim',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'attachment_ids' => [ 'type' => 'array', 'maxItems' => self::MAX_SPECIFIC, 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'description' => 'Optional: report per-image status for these IDs.' ],
				],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success'            => [ 'type' => 'boolean' ],
					'service'            => [ 'type' => 'string' ],
					'total_unoptimized'  => [ 'type' => 'integer' ],
					'images'             => [ 'type' => 'array' ],
					'message'            => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) {
				$gate = AVCF_ReSmushit_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				$images = [];
				$ids = AVCF_ReSmushit_Helpers::clean_ids( isset( $input['attachment_ids'] ) ? $input['attachment_ids'] : [] );
				foreach ( $ids as $id ) {
					$images[] = array_merge( [ 'id' => $id ], AVCF_ReSmushit_Helpers::attachment_status( $id ) );
				}
				$total = AVCF_ReSmushit_Helpers::total_unoptimized();
				return [
					'success'           => true,
					'service'           => 'reSmush.it (free, keyless)',
					'total_unoptimized' => is_null( $total ) ? -1 : (int) $total,
					'images'            => $images,
					'message'           => empty( $ids ) ? 'reSmush.it status reported. Pass attachment_ids for per-image status.' : sprintf( 'Status for %d image(s).', count( $ids ) ),
				];
			},
			'permission_callback' => function() { return current_user_can( 'upload_files' ); },
			'meta' => AVCF_ReSmushit_Helpers::read_meta(),
		] );
	}
}

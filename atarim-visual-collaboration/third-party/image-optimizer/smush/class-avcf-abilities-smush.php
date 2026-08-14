<?php
/**
 * Smush (WPMU DEV) MCP abilities.
 *
 * Drives the installed Smush plugin. Single-image optimization is synchronous
 * via Smush's Optimizer; "all" uses Smush's own background process (async).
 * A specific ID subset has no async primitive in Smush (its background bulk
 * works off a whole-library scan), so specific optimization is synchronous and
 * capped, and "optimize-all" is the background path — hence the adapted shape:
 *   - optimize      : a specific set of IDs, synchronously (kept modest).
 *   - optimize-all  : Smush's background bulk over the whole library.
 *   - status        : free/pro note + per-image state.
 *
 * The FREE tier works with no key; see AVCF_Smush_Helpers for verified 4.3.0
 * entry points.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_Abilities_Smush {

	/** Max IDs per synchronous optimize call. */
	const MAX_SPECIFIC = 25;

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
		wp_register_ability( 'atarim/smush-optimize', [
			'label'        => 'Smush: Optimize Images',
			'description'  => 'Optimize specific media attachments with Smush (max ' . self::MAX_SPECIFIC . ' per call). The free tier works with no key. Smush optimizes each image SYNCHRONOUSLY (via the Smush API), so this returns per-image status when done — keep batches modest. Already-optimized or unsupported images are reported per-id. For the whole library use smush-optimize-all.',
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
				$gate = AVCF_Smush_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				$ids = AVCF_Smush_Helpers::clean_ids( isset( $input['attachment_ids'] ) ? $input['attachment_ids'] : [] );
				if ( empty( $ids ) ) { return [ 'success' => false, 'message' => 'attachment_ids is required.' ]; }
				if ( count( $ids ) > self::MAX_SPECIFIC ) { return [ 'success' => false, 'message' => sprintf( 'Too many IDs (%d); max %d per call because Smush optimizes synchronously. Use smush-optimize-all for the whole library.', count( $ids ), self::MAX_SPECIFIC ) ]; }
				return AVCF_Smush_Helpers::optimize_ids( $ids );
			},
			'permission_callback' => function() { return current_user_can( 'upload_files' ); },
			'meta' => AVCF_Smush_Helpers::write_meta(),
		] );
	}

	/** All unoptimized: start Smush's own background bulk. */
	private function register_optimize_all() {
		wp_register_ability( 'atarim/smush-optimize-all', [
			'label'        => 'Smush: Optimize All (background)',
			'description'  => 'Start Smush\'s own background bulk optimization — it scans the whole media library and optimizes everything not yet done, in the background. Returns once started (does not block or time out on large libraries); poll smush-status for progress. This is the right tool for "optimize the whole site".',
			'category'     => 'atarim',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success' => [ 'type' => 'boolean' ],
					'started' => [ 'type' => 'boolean' ],
					'status'  => [ 'type' => 'object' ],
					'message' => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) {
				$gate = AVCF_Smush_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				return AVCF_Smush_Helpers::start_bulk_all();
			},
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			'meta' => AVCF_Smush_Helpers::write_meta(),
		] );
	}

	/** Status: free/pro note + optional per-attachment state. */
	private function register_status() {
		wp_register_ability( 'atarim/smush-status', [
			'label'        => 'Smush: Optimization Status',
			'description'  => 'Report Smush status: whether Smush Pro (WPMU DEV membership) is active (the free tier works without it), and — if attachment_ids are given — per-image state (optimized? percent saved) from Smush\'s own records.',
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
					'success' => [ 'type' => 'boolean' ],
					'is_pro'  => [ 'type' => 'boolean' ],
					'images'  => [ 'type' => 'array' ],
					'message' => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) {
				$gate = AVCF_Smush_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				$detector = new AVCF_Smush_Detector();
				$images   = [];
				$ids = AVCF_Smush_Helpers::clean_ids( isset( $input['attachment_ids'] ) ? $input['attachment_ids'] : [] );
				foreach ( $ids as $id ) {
					$images[] = array_merge( [ 'id' => $id ], AVCF_Smush_Helpers::attachment_status( $id ) );
				}
				return [
					'success' => true,
					'is_pro'  => $detector->avcf_smush_is_pro(),
					'images'  => $images,
					'message' => empty( $ids ) ? 'Smush status reported. Pass attachment_ids for per-image status.' : sprintf( 'Status for %d image(s).', count( $ids ) ),
				];
			},
			'permission_callback' => function() { return current_user_can( 'upload_files' ); },
			'meta' => AVCF_Smush_Helpers::read_meta(),
		] );
	}
}

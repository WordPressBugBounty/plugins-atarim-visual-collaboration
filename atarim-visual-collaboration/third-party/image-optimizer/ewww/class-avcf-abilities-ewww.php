<?php
/**
 * EWWW Image Optimizer MCP abilities.
 *
 * Drives the installed EWWW plugin — never holds the API key or reimplements
 * compression. EWWW optimizes LOCALLY by default (no key needed) and can be
 * sync or background depending on the site's setting; these abilities respect
 * that. See AVCF_EWWW_Helpers for the verified 8.7.5 entry points.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_Abilities_EWWW {

	/** Max IDs for the "specific" ability (may run synchronously). */
	const MAX_SPECIFIC = 25;
	/** Max IDs for the "bulk specific" ability (always background). */
	const MAX_BULK_SPECIFIC = 200;

	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$this->register_optimize();
		$this->register_bulk_optimize();
		$this->register_optimize_all();
		$this->register_status();
	}

	/** Specific: optimize a small named set. Respects the site's sync/background setting. */
	private function register_optimize() {
		wp_register_ability( 'atarim/ewww-optimize', [
			'label'        => 'EWWW: Optimize Specific Images',
			'description'  => 'Optimize specific media attachments with EWWW Image Optimizer (max ' . self::MAX_SPECIFIC . ' per call). EWWW optimizes locally by default (no API key required). If the site is set to background optimization, images are QUEUED (poll ewww-status); otherwise they are optimized SYNCHRONOUSLY and per-image savings are returned immediately. Already-optimized images are left as-is.',
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
					'mode'      => [ 'type' => 'string' ],
					'processed' => [ 'type' => 'integer' ],
					'results'   => [ 'type' => 'array' ],
					'message'   => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) {
				$gate = AVCF_EWWW_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				$ids = AVCF_EWWW_Helpers::clean_ids( isset( $input['attachment_ids'] ) ? $input['attachment_ids'] : [] );
				if ( empty( $ids ) ) { return [ 'success' => false, 'message' => 'attachment_ids is required.' ]; }
				if ( count( $ids ) > self::MAX_SPECIFIC ) { return [ 'success' => false, 'message' => sprintf( 'Too many IDs (%d); max %d. Use ewww-bulk-optimize for larger sets.', count( $ids ), self::MAX_SPECIFIC ) ]; }
				return AVCF_EWWW_Helpers::optimize_ids( $ids, false );
			},
			'permission_callback' => function() { return current_user_can( 'upload_files' ); },
			'meta' => AVCF_EWWW_Helpers::write_meta(),
		] );
	}

	/** Bulk specific: a larger listed set — always background-queued. */
	private function register_bulk_optimize() {
		wp_register_ability( 'atarim/ewww-bulk-optimize', [
			'label'        => 'EWWW: Bulk Optimize a Specific Set',
			'description'  => 'Queue a larger, explicitly listed set of media attachments for EWWW background optimization (max ' . self::MAX_BULK_SPECIFIC . ' per call). Always uses the background process (not synchronous), so it returns immediately — poll ewww-status. For "everything not yet optimized" use ewww-optimize-all.',
			'category'     => 'atarim',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'attachment_ids' => [ 'type' => 'array', 'maxItems' => self::MAX_BULK_SPECIFIC, 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'description' => 'Attachment IDs to optimize.' ],
				],
				'required'             => [ 'attachment_ids' ],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success'   => [ 'type' => 'boolean' ],
					'mode'      => [ 'type' => 'string' ],
					'processed' => [ 'type' => 'integer' ],
					'results'   => [ 'type' => 'array' ],
					'message'   => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) {
				$gate = AVCF_EWWW_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				$ids = AVCF_EWWW_Helpers::clean_ids( isset( $input['attachment_ids'] ) ? $input['attachment_ids'] : [] );
				if ( empty( $ids ) ) { return [ 'success' => false, 'message' => 'attachment_ids is required.' ]; }
				if ( count( $ids ) > self::MAX_BULK_SPECIFIC ) { return [ 'success' => false, 'message' => sprintf( 'Too many IDs (%d); max %d. Split into batches or use ewww-optimize-all.', count( $ids ), self::MAX_BULK_SPECIFIC ) ]; }
				return AVCF_EWWW_Helpers::optimize_ids( $ids, true );
			},
			'permission_callback' => function() { return current_user_can( 'upload_files' ); },
			'meta' => AVCF_EWWW_Helpers::write_meta(),
		] );
	}

	/** All unoptimized: kick EWWW's library-wide scan + background process. */
	private function register_optimize_all() {
		wp_register_ability( 'atarim/ewww-optimize-all', [
			'label'        => 'EWWW: Optimize All Unoptimized',
			'description'  => 'Start an EWWW library-wide optimization — scans the whole media library and queues everything not yet optimized, using EWWW\'s own scan + background process. Returns once started (EWWW processes it in the background); poll ewww-status. This is the right tool for "optimize the whole site"; it does not block or time out on large libraries.',
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
					'message' => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) {
				$gate = AVCF_EWWW_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				return AVCF_EWWW_Helpers::start_bulk_all();
			},
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			'meta' => AVCF_EWWW_Helpers::write_meta(),
		] );
	}

	/** Status: current mode + optional per-attachment optimization state. */
	private function register_status() {
		wp_register_ability( 'atarim/ewww-status', [
			'label'        => 'EWWW: Optimization Status',
			'description'  => 'Report EWWW status: current mode (local / cloud / easy_io) and whether a cloud API key is configured (boolean only — the key is never read), plus — if attachment_ids are given — per-image state (optimized? bytes saved? savings percentage) from EWWW\'s own records.',
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
					'success'           => [ 'type' => 'boolean' ],
					'mode'              => [ 'type' => 'string' ],
					'cloud_key_present' => [ 'type' => 'boolean' ],
					'images'            => [ 'type' => 'array' ],
					'message'           => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) {
				$gate = AVCF_EWWW_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				$detector = new AVCF_EWWW_Detector();
				$images   = [];
				$ids = AVCF_EWWW_Helpers::clean_ids( isset( $input['attachment_ids'] ) ? $input['attachment_ids'] : [] );
				foreach ( $ids as $id ) {
					$images[] = array_merge( [ 'id' => $id ], AVCF_EWWW_Helpers::attachment_status( $id ) );
				}
				return [
					'success'           => true,
					'mode'              => $detector->avcf_ewww_mode(),
					'cloud_key_present' => $detector->avcf_ewww_cloud_key_present(),
					'images'            => $images,
					'message'           => empty( $ids ) ? 'EWWW mode reported. Pass attachment_ids for per-image status.' : sprintf( 'Status for %d image(s).', count( $ids ) ),
				];
			},
			'permission_callback' => function() { return current_user_can( 'upload_files' ); },
			'meta' => AVCF_EWWW_Helpers::read_meta(),
		] );
	}
}

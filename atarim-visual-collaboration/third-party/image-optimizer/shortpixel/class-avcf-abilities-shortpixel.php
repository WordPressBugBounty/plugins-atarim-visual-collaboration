<?php
/**
 * ShortPixel Image Optimizer MCP abilities.
 *
 * Drives the installed ShortPixel plugin — it never holds the API key or
 * reimplements compression. ShortPixel is a cloud/async optimizer: these
 * abilities ENQUEUE work into ShortPixel's own background queue; the actual
 * optimization completes asynchronously (ShortPixel's cron). Use
 * shortpixel-status to see progress and results.
 *
 * Verified against ShortPixel Image Optimizer 6.5.5:
 *   - single enqueue : FileSystemController::getMediaImage($id) -> QueueController::addItemToQueue($img)
 *   - library bulk    : BulkController::createNewBulk('media', $args) -> startBulk(['media'])
 *   - configured?     : ApiKeyController::getInstance()->getKeyModel()->is_verified()
 *   - per-image status: ImageModel::isOptimized() / isProcessable() / getImprovements()
 *
 * All ShortPixel calls are guarded (class/method existence + try/catch) so a
 * version change degrades to a clear message rather than a fatal.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_Abilities_ShortPixel {

	/** Max IDs for the "specific" ability. */
	const MAX_SPECIFIC = 25;
	/** Max IDs for the "bulk specific" ability. */
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

	/* --------------------------------------------------------------------- */
	/* Abilities                                                             */
	/* --------------------------------------------------------------------- */

	/** Specific: optimize a small, named set of attachments. */
	private function register_optimize() {
		$self = $this;
		wp_register_ability( 'atarim/shortpixel-optimize', [
			'label'        => 'ShortPixel: Optimize Specific Images',
			'description'  => 'Queue specific media attachments for optimization with ShortPixel (max ' . self::MAX_SPECIFIC . ' per call). ShortPixel optimizes asynchronously on its own servers, so this ENQUEUES the images and returns immediately; use shortpixel-status to see results. Already-optimized or non-processable items are reported per-id and skipped.',
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
					'success'        => [ 'type' => 'boolean' ],
					'queued'         => [ 'type' => 'integer' ],
					'results'        => [ 'type' => 'array' ],
					'message'        => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) use ( $self ) {
				$gate = AVCF_ShortPixel_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				$ids = AVCF_ShortPixel_Helpers::clean_ids( isset( $input['attachment_ids'] ) ? $input['attachment_ids'] : [] );
				if ( empty( $ids ) ) { return [ 'success' => false, 'message' => 'attachment_ids is required.' ]; }
				if ( count( $ids ) > self::MAX_SPECIFIC ) { return [ 'success' => false, 'message' => sprintf( 'Too many IDs (%d); max %d for this ability. Use shortpixel-bulk-optimize for larger sets.', count( $ids ), self::MAX_SPECIFIC ) ]; }
				return AVCF_ShortPixel_Helpers::enqueue_ids( $ids );
			},
			'permission_callback' => function() { return current_user_can( 'upload_files' ); },
			'meta' => AVCF_ShortPixel_Helpers::write_meta(),
		] );
	}

	/** Bulk specific: optimize a larger, explicitly listed set of attachments. */
	private function register_bulk_optimize() {
		$self = $this;
		wp_register_ability( 'atarim/shortpixel-bulk-optimize', [
			'label'        => 'ShortPixel: Bulk Optimize a Specific Set',
			'description'  => 'Queue a larger, explicitly listed set of media attachments for ShortPixel optimization (max ' . self::MAX_BULK_SPECIFIC . ' per call). Same async model as shortpixel-optimize — enqueues and returns; poll shortpixel-status. For "everything not yet optimized" use shortpixel-optimize-all instead.',
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
					'success' => [ 'type' => 'boolean' ],
					'queued'  => [ 'type' => 'integer' ],
					'results' => [ 'type' => 'array' ],
					'message' => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) use ( $self ) {
				$gate = AVCF_ShortPixel_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				$ids = AVCF_ShortPixel_Helpers::clean_ids( isset( $input['attachment_ids'] ) ? $input['attachment_ids'] : [] );
				if ( empty( $ids ) ) { return [ 'success' => false, 'message' => 'attachment_ids is required.' ]; }
				if ( count( $ids ) > self::MAX_BULK_SPECIFIC ) { return [ 'success' => false, 'message' => sprintf( 'Too many IDs (%d); max %d. Split into batches or use shortpixel-optimize-all.', count( $ids ), self::MAX_BULK_SPECIFIC ) ]; }
				return AVCF_ShortPixel_Helpers::enqueue_ids( $ids );
			},
			'permission_callback' => function() { return current_user_can( 'upload_files' ); },
			'meta' => AVCF_ShortPixel_Helpers::write_meta(),
		] );
	}

	/** All unoptimized: kick ShortPixel's library-wide bulk scan. */
	private function register_optimize_all() {
		$self = $this;
		wp_register_ability( 'atarim/shortpixel-optimize-all', [
			'label'        => 'ShortPixel: Optimize All Unoptimized',
			'description'  => 'Start a ShortPixel library-wide bulk optimization — scans the whole media library and queues everything not yet optimized, using ShortPixel\'s own bulk engine. Returns once the bulk is started (ShortPixel processes it in the background); poll shortpixel-status for progress. This is the right tool for "optimize the whole site"; it does not block or time out on large libraries.',
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
					'stats'   => [ 'type' => 'object' ],
					'message' => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) use ( $self ) {
				$gate = AVCF_ShortPixel_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				try {
					$bulk = \ShortPixel\Controller\BulkController::getInstance();
					if ( ! is_object( $bulk ) || ! method_exists( $bulk, 'createNewBulk' ) || ! method_exists( $bulk, 'startBulk' ) ) {
						return [ 'success' => false, 'message' => 'ShortPixel bulk controller is unavailable on this version.' ];
					}
					$stats = $bulk->createNewBulk( 'media', [ 'doMedia' => true, 'doAi' => false ] );
					$bulk->startBulk( [ 'media' ] );
				} catch ( \Throwable $e ) {
					return [ 'success' => false, 'message' => 'Could not start ShortPixel bulk: ' . $e->getMessage() ];
				}
				return [
					'success' => true,
					'started' => true,
					'stats'   => is_array( $stats ) || is_object( $stats ) ? (array) $stats : [],
					'message' => 'Started ShortPixel bulk optimization of unoptimized media. It runs in the background — poll shortpixel-status for progress.',
				];
			},
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			'meta' => AVCF_ShortPixel_Helpers::write_meta(),
		] );
	}

	/** Status: queue progress + optional per-attachment optimization status. */
	private function register_status() {
		$self = $this;
		wp_register_ability( 'atarim/shortpixel-status', [
			'label'        => 'ShortPixel: Optimization Status',
			'description'  => 'Report ShortPixel optimization status: the current queue status, and — if attachment_ids are given — per-image state (optimized? processable? savings percentage). Use this to follow up on shortpixel-optimize / bulk-optimize / optimize-all, which enqueue work that completes asynchronously.',
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
					'success'      => [ 'type' => 'boolean' ],
					'queue_status' => [ 'type' => 'object' ],
					'images'       => [ 'type' => 'array' ],
					'message'      => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) use ( $self ) {
				$gate = AVCF_ShortPixel_Helpers::guard( false ); // status is fine even if unconfigured
				if ( null !== $gate ) { return $gate; }

				$queue_status = [];
				try {
					$qc = new \ShortPixel\Controller\QueueController();
					if ( method_exists( $qc, 'getLastQueueStatus' ) ) {
						$qs = $qc->getLastQueueStatus();
						$queue_status = ( is_array( $qs ) || is_object( $qs ) ) ? (array) $qs : [];
					}
				} catch ( \Throwable $e ) {
					$queue_status = [];
				}

				$images = [];
				$ids = AVCF_ShortPixel_Helpers::clean_ids( isset( $input['attachment_ids'] ) ? $input['attachment_ids'] : [] );
				if ( ! empty( $ids ) ) {
					foreach ( $ids as $id ) {
						$images[] = AVCF_ShortPixel_Helpers::image_status( $id );
					}
				}
				return [
					'success'      => true,
					'queue_status' => $queue_status,
					'images'       => $images,
					'message'      => empty( $ids ) ? 'Queue status returned. Pass attachment_ids for per-image status.' : sprintf( 'Status for %d image(s).', count( $ids ) ),
				];
			},
			'permission_callback' => function() { return current_user_can( 'upload_files' ); },
			'meta' => AVCF_ShortPixel_Helpers::read_meta(),
		] );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers (delegated to AVCF_ShortPixel_Helpers)                        */
	/* --------------------------------------------------------------------- */
}

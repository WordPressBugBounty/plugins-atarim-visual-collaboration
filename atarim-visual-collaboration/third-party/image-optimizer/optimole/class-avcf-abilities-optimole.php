<?php
/**
 * Optimole MCP abilities.
 *
 * Optimole is a CDN/offload service — optimization is automatic once connected,
 * so there is no per-image "optimize" ability. Only a read-only status ability
 * is provided here. (Offload / rollback — pushing the library to Optimole's
 * cloud and restoring it — are significant, semi-destructive operations and are
 * intentionally NOT implemented in this cluster.)
 *
 * See AVCF_Optimole_Helpers for the verified 4.2.10 entry points.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_Abilities_Optimole {

	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$this->register_status();
	}

	/** Read-only Optimole connection / offload status. */
	private function register_status() {
		wp_register_ability( 'atarim/optimole-status', [
			'label'        => 'Optimole: Status',
			'description'  => 'Report Optimole status: whether the site is connected to an Optimole account, whether the service and offload are enabled, whether an offload or rollback is currently in progress, and the offload limit / whether it has been reached. Optimole optimizes images automatically through its CDN once connected — there is no per-image optimize action, so this is read-only status only.',
			'category'     => 'atarim',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success'                => [ 'type' => 'boolean' ],
					'connected'              => [ 'type' => 'boolean' ],
					'service_enabled'        => [ 'type' => 'boolean' ],
					'offload_enabled'        => [ 'type' => 'boolean' ],
					'offloading_in_progress' => [ 'type' => 'boolean' ],
					'rollback_in_progress'   => [ 'type' => 'boolean' ],
					'offload_limit'          => [ 'type' => 'integer' ],
					'offload_limit_reached'  => [ 'type' => 'boolean' ],
					'message'                => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) {
				$gate = AVCF_Optimole_Helpers::guard();
				if ( null !== $gate ) { return $gate; }
				$status = AVCF_Optimole_Helpers::status();
				$msg    = $status['connected']
					? 'Optimole is connected; images are optimized automatically via the CDN.'
					: 'Optimole is active but not connected. Connect an Optimole account to enable optimization.';
				return array_merge( [ 'success' => true ], $status, [ 'message' => $msg ] );
			},
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			'meta' => AVCF_Optimole_Helpers::read_meta(),
		] );
	}
}

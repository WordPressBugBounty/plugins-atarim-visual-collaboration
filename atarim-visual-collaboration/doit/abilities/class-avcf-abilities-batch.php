<?php
/**
 * Atarim MCP abilities — batch.
 *
 * Abilities registered here:
 *   atarim/batch    Run several named abilities in one request.
 *
 * WHY THIS EXISTS
 * Callers iterate. Measured against real traffic, roughly three quarters of
 * this server's calls arrive as chains of the same ability repeated once per
 * round — read the SEO fields of forty posts, update the alt text of a dozen
 * images — because a caller that already holds the id list has no way to say
 * "do this to all of them". Every one of those rounds costs a full round trip
 * to this site.
 *
 * WHY IT IS NOT A GENERIC GATEWAY
 * The adapter's own execute-ability meta-tool is deliberately NOT exposed by
 * avcf_mcp_setup_server(), because a gateway that runs abilities by name
 * sidesteps the named-tool surface and with it the avcf_mcp_blocked_abilities
 * boundary. This ability would have exactly that shape if it resolved names
 * naively, so it re-applies the same gate before running anything: an operation
 * runs only if its ability is registered, MCP-public, of type tool, not blocked
 * on this site, and not this ability itself. Each ability's own
 * permission_callback still runs — WP_Ability::execute() enforces it — so batch
 * grants no capability the caller did not already have one call at a time.
 *
 * Note: registration plus meta.mcp.public is all it takes to be exposed —
 * avcf_mcp_setup_server() collects every public tool ability rather than a
 * hand-listed set. The one edit it does carry for this ability is the pin that
 * keeps atarim/batch in a listing the caller has narrowed by cluster.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Batch extends AVCF_Abilities_Base {

    /**
     * Most operations one request may carry. Chains seen in practice run to
     * tens of items, not hundreds, and the response still has to cross the
     * wire in one piece.
     */
    const MAX_OPERATIONS = 25;

    /**
     * Seconds to keep executing before handing back what is done. Shared hosts
     * cap max_execution_time at 30s and a batch that overruns returns nothing
     * at all, which is worse than the one-at-a-time chain it replaces.
     */
    const DEFAULT_TIME_BUDGET = 45;

    public function register() {
        wp_register_ability( 'atarim/batch', [
            'label'               => 'Batch',
            'description'         => 'Runs several abilities in one request instead of one call each. Use this whenever you are about to repeat the same ability over a list you already have — reading or writing the SEO fields of many posts, updating many media items, fetching many pages. Each operation carries its own ability name and its own full arguments, so the operations do not have to be the same ability and do not have to share arguments. Operations run in the order given and CANNOT reference each other\'s results: gather what you need first, then batch the repetitive part. Every operation is permission-checked exactly as it would be on its own, and an ability blocked on this site stays blocked here. One failure does not abandon the rest unless you set stop_on_error. The response reports every operation separately, in the order sent, so a partial failure is visible rather than silent. If the time budget runs out the remainder come back as not_run for you to send again.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'operations' => [
                        'type'        => 'array',
                        'description' => 'The abilities to run, in order. Each entry is an object with "ability" (a tool name from this server, e.g. atarim/get-content) and "arguments" (that ability\'s own input object).',
                        'minItems'    => 1,
                        'maxItems'    => self::MAX_OPERATIONS,
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'ability'   => [
                                    'type'        => 'string',
                                    'description' => 'Ability name, slash- or dash-separated (atarim/get-content or atarim-get-content).',
                                ],
                                'arguments' => [
                                    'type'        => 'object',
                                    'description' => 'Input for that ability, exactly as you would send it on its own.',
                                ],
                            ],
                            'required'   => [ 'ability' ],
                        ],
                    ],
                    'stop_on_error' => [
                        'type'        => 'boolean',
                        'description' => 'Stop at the first failing operation instead of continuing. Default false. Use it when later operations only make sense if the earlier ones succeeded.',
                    ],
                ],
                'required'             => [ 'operations' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'results' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'index'   => [ 'type' => 'integer' ],
                                'ability' => [ 'type' => 'string' ],
                                'status'  => [ 'type' => 'string', 'enum' => [ 'ok', 'error', 'not_run' ] ],
                                'result'  => [ 'description' => 'The ability\'s own return value, when status is ok.' ],
                                'error'   => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'code'    => [ 'type' => 'string' ],
                                        'message' => [ 'type' => 'string' ],
                                    ],
                                ],
                            ],
                            'required'   => [ 'index', 'ability', 'status' ],
                        ],
                    ],
                    'succeeded' => [ 'type' => 'integer' ],
                    'failed'    => [ 'type' => 'integer' ],
                    'not_run'   => [ 'type' => 'integer' ],
                ],
                'required' => [ 'results', 'succeeded', 'failed', 'not_run' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                return $this->avcf_run_batch( is_array( $input ) ? $input : [] );
            },
            'permission_callback' => function() {
                return is_user_logged_in();
            },
            'meta' => [
                'mcp' => [
                    'public'            => true,
                    'type'              => 'tool',
                    'short_description' => 'Runs several abilities in one request instead of one call each — use it whenever you would otherwise repeat the same ability over a list.',
                ],
                'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );
    }

    /**
     * @param array $input
     * @return array
     */
    private function avcf_run_batch( $input ) {
        $operations = isset( $input['operations'] ) && is_array( $input['operations'] ) ? $input['operations'] : [];
        $stop       = ! empty( $input['stop_on_error'] );
        $operations = array_slice( array_values( $operations ), 0, self::MAX_OPERATIONS );

        $blocked  = $this->avcf_blocked_ability_names();
        $deadline = microtime( true ) + (float) $this->avcf_time_budget();

        $results = [];
        $halted  = false;

        foreach ( $operations as $index => $operation ) {
            $name = $this->avcf_operation_name( $operation );

            // The first operation always runs, however little time is left. A
            // batch that returned nothing at all would be strictly worse than
            // the single call it replaced, and a caller with no progress to
            // show for the round has nothing to do but send the same thing
            // again. This way a batch is never a step backwards.
            if ( $halted || ( $index > 0 && microtime( true ) >= $deadline ) ) {
                $results[] = [ 'index' => $index, 'ability' => $name, 'status' => 'not_run' ];
                continue;
            }

            $results[] = $result = $this->avcf_run_operation( $index, $name, $operation, $blocked );

            if ( $stop && $result['status'] === 'error' ) {
                $halted = true;
            }
        }

        return $this->avcf_summarise( $results );
    }

    /**
     * @param int    $index
     * @param string $name
     * @param mixed  $operation
     * @param array  $blocked
     * @return array
     */
    private function avcf_run_operation( $index, $name, $operation, $blocked ) {
        $ability = $this->avcf_callable_ability( $name, $blocked );

        if ( ! $ability ) {
            return [
                'index'   => $index,
                'ability' => $name,
                'status'  => 'error',
                'error'   => [
                    'code'    => 'ability_not_available',
                    'message' => 'No ability of that name is callable on this site. Use the tool listing to check the name; abilities disabled here, and batch itself, cannot be run this way.',
                ],
            ];
        }

        $arguments = isset( $operation['arguments'] ) && is_array( $operation['arguments'] ) ? $operation['arguments'] : [];
        $outcome   = $ability->execute( $arguments );

        if ( is_wp_error( $outcome ) ) {
            return [
                'index'   => $index,
                'ability' => $name,
                'status'  => 'error',
                'error'   => [
                    'code'    => (string) $outcome->get_error_code(),
                    'message' => (string) $outcome->get_error_message(),
                ],
            ];
        }

        // An ability that ran but reported its own failure counted as a success in
        // the summary, so `failed: 0` could sit above an operation that plainly
        // did not work — and the summary is what a caller reads first.
        if ( is_array( $outcome ) && array_key_exists( 'success', $outcome ) && false === $outcome['success'] ) {
            return [
                'index'   => $index,
                'ability' => $name,
                'status'  => 'error',
                'error'   => [
                    'code'    => 'ability_reported_failure',
                    'message' => isset( $outcome['message'] ) && '' !== $outcome['message']
                        ? (string) $outcome['message']
                        : 'The ability reported success: false.',
                ],
                'result'  => $outcome,
            ];
        }

        return [ 'index' => $index, 'ability' => $name, 'status' => 'ok', 'result' => $outcome ];
    }

    /**
     * The ability behind a name, but only if the named-tool surface would have
     * run it: registered, MCP-public, type tool, not blocked here, not batch.
     *
     * @param string $name
     * @param array  $blocked
     * @return WP_Ability|null
     */
    private function avcf_callable_ability( $name, $blocked ) {
        if ( $name === '' || $name === 'atarim/batch' || in_array( $name, $blocked, true ) ) {
            return null;
        }

        if ( ! function_exists( 'wp_get_ability' ) ) {
            return null;
        }

        $ability = wp_get_ability( $name );

        if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) || ! method_exists( $ability, 'execute' ) ) {
            return null;
        }

        $meta = (array) $ability->get_meta();
        $type = isset( $meta['mcp']['type'] ) ? (string) $meta['mcp']['type'] : 'tool';

        if ( empty( $meta['mcp']['public'] ) || $type !== 'tool' ) {
            return null;
        }

        return $ability;
    }

    /**
     * The site's blocklist, read the same way avcf_mcp_setup_server() reads it
     * so the two surfaces cannot drift apart.
     *
     * @return array
     */
    private function avcf_blocked_ability_names() {
        $functions = new AVCF_Functions();
        $blocked   = (array) $functions->avcf_get_setting_data( 'avcf_mcp_blocked_abilities', [] );

        return array_values( (array) apply_filters( 'avcf_mcp_blocked_abilities', $blocked ) );
    }

    /**
     * @return int
     */
    private function avcf_time_budget() {
        $configured = (int) ini_get( 'max_execution_time' );
        $budget     = $configured > 0 ? max( 5, (int) floor( $configured * 0.7 ) ) : self::DEFAULT_TIME_BUDGET;

        return (int) apply_filters( 'avcf_batch_time_budget', $budget );
    }

    /**
     * Accepts the dash-separated tool name as well as the slash-separated
     * ability name, because the caller sees the folded form in tool listings.
     *
     * @param mixed $operation
     * @return string
     */
    private function avcf_operation_name( $operation ) {
        if ( ! is_array( $operation ) ) {
            return '';
        }

        $name = trim( (string) ( isset( $operation['ability'] ) ? $operation['ability'] : '' ) );

        if ( $name === '' || strpos( $name, '/' ) !== false ) {
            return $name;
        }

        return preg_replace( '#^([a-z0-9\-]+?)-#i', '$1/', $name, 1 );
    }

    /**
     * @param array $results
     * @return array
     */
    private function avcf_summarise( $results ) {
        $counts = [ 'ok' => 0, 'error' => 0, 'not_run' => 0 ];

        foreach ( $results as $result ) {
            $counts[ $result['status'] ]++;
        }

        return [
            'results'   => $results,
            'succeeded' => $counts['ok'],
            'failed'    => $counts['error'],
            'not_run'   => $counts['not_run'],
        ];
    }
}

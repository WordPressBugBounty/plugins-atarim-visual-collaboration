<?php
/**
 * ACF — Abilities orchestrator.
 *
 * Single entry point that registers the read-only check-setup baseline and
 * dispatches the field-group and content-model ability classes. Called from
 * doit/class-avcf-mcp.php inside the ACF-available check; each ability class
 * still sentinel-checks ACF as defence-in-depth.
 *
 * Files dispatched:
 *   class-avcf-acf-field-groups.php    5 abilities
 *   class-avcf-acf-content-models.php  15 abilities (CPT/taxonomy/options ×5)
 *   (this file)                        1 ability  (acf-check-setup)
 *                                      ─────────────
 *                                      21 abilities total
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_ACF extends AVCF_Abilities_Base {

    /**
     * @var AVCF_ACF_Detector
     */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_ACF_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_acf_is_available() ) {
            return;
        }

        $detector = $this->detector;

        // ---- acf-check-setup ----
        wp_register_ability( 'atarim/acf-check-setup', [
            'label'               => 'Check ACF Setup',
            'description'         => 'Call this first before any other ACF ability. Reports whether ACF is active, the edition (free / pro), the version, whether the post-type / taxonomy / options-page management abilities are available (these require ACF Pro 6.5+), and counts of currently registered field groups, ACF post types, ACF taxonomies, and ACF options pages. Zero counts mean you are starting fresh.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'                      => [ 'type' => 'boolean' ],
                    'active'                       => [ 'type' => 'boolean' ],
                    'edition'                      => [ 'type' => 'string' ],
                    'version'                      => [ 'type' => 'string' ],
                    'supports_content_model_mgmt'  => [ 'type' => 'boolean' ],
                    'field_groups_count'           => [ 'type' => 'integer' ],
                    'post_types_count'             => [ 'type' => 'integer' ],
                    'taxonomies_count'             => [ 'type' => 'integer' ],
                    'options_pages_count'          => [ 'type' => 'integer' ],
                    'message'                      => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'active', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) use ( $detector ) {
                $active = $detector->avcf_acf_is_available();
                if ( ! $active ) {
                    return [ 'success' => true, 'active' => false, 'message' => 'ACF is not active on this site.' ];
                }
                $supports = $detector->avcf_acf_supports_internal_post_types();
                $fg_count = function_exists( 'acf_get_field_groups' ) ? count( (array) acf_get_field_groups() ) : 0;
                $cpt = $tax = $opt = 0;
                if ( $supports ) {
                    $cpt = count( (array) acf_get_internal_post_type_posts( 'acf-post-type' ) );
                    $tax = count( (array) acf_get_internal_post_type_posts( 'acf-taxonomy' ) );
                    $opt = count( (array) acf_get_internal_post_type_posts( 'acf-ui-options-page' ) );
                }
                return [
                    'success'                     => true,
                    'active'                      => true,
                    'edition'                     => $detector->avcf_acf_edition(),
                    'version'                     => $detector->avcf_acf_version(),
                    'supports_content_model_mgmt' => $supports,
                    'field_groups_count'          => $fg_count,
                    'post_types_count'            => $cpt,
                    'taxonomies_count'            => $tax,
                    'options_pages_count'         => $opt,
                    'message'                     => 'OK.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // Dispatch the domain clusters.
        ( new AVCF_ACF_Field_Groups() )->register();
        ( new AVCF_ACF_Content_Models() )->register();
    }
}

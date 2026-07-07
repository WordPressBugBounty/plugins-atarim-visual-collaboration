<?php
/**
 * WooCommerce — Product Attributes MCP abilities.
 *
 * Manages GLOBAL product attributes (the ones stored in
 * wp_woocommerce_attribute_taxonomies and exposed as pa_* taxonomies), which
 * the generic taxonomy abilities can't create because they aren't registered
 * via register_taxonomy() at runtime — they need wc_create_attribute().
 *
 * Attribute *terms* (e.g. Red/Blue under a Color attribute) live in the pa_*
 * taxonomy once the attribute exists, so the generic update-term / delete-term
 * abilities already handle editing and deleting them. We add only two thin
 * ergonomic wrappers (list + create) that resolve the attribute to its pa_*
 * taxonomy so the agent doesn't have to know WooCommerce's naming convention.
 *
 * Exposed abilities:
 *   atarim/list-product-attributes        Global attributes (id, name, slug, type).
 *   atarim/create-product-attribute       New global attribute.
 *   atarim/update-product-attribute       Edit a global attribute.
 *   atarim/delete-product-attribute       Delete a global attribute (+ its terms).
 *   atarim/list-product-attribute-terms   Terms under one attribute.
 *   atarim/create-product-attribute-term  Add a term to one attribute.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Attributes extends AVCF_Abilities_Base {

    /** @var AVCF_WC_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_WC_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_wc_is_available() ) {
            return;
        }
        $this->register_attributes();
        $this->register_attribute_terms();
    }

    /* ----------------------------- helpers ----------------------------- */

    private function can() {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_product_terms' ) || current_user_can( 'edit_products' );
    }
    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
    }
    private function write_meta( $destructive = false ) {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $destructive, 'idempotent' => false ] ];
    }

    /**
     * Resolve an attribute identifier (id, slug, or pa_ taxonomy) to its
     * taxonomy name (e.g. "pa_color") and the attribute record.
     *
     * @return array{taxonomy:string,attribute:object}|null
     */
    private function resolve_attribute( $identifier ) {
        $identifier = is_string( $identifier ) ? trim( $identifier ) : $identifier;
        foreach ( (array) wc_get_attribute_taxonomies() as $attr ) {
            $slug     = $attr->attribute_name;
            $taxonomy = wc_attribute_taxonomy_name( $slug );
            if ( (string) $identifier === (string) $attr->attribute_id
                || (string) $identifier === $slug
                || (string) $identifier === $taxonomy
                || strtolower( (string) $identifier ) === strtolower( $attr->attribute_label ) ) {
                return [ 'taxonomy' => $taxonomy, 'attribute' => $attr ];
            }
        }
        return null;
    }

    /* --------------------------- attributes ---------------------------- */

    private function register_attributes() {
        $self = $this;

        wp_register_ability( 'atarim/list-product-attributes', [
            'label'        => 'List Product Attributes',
            'description'  => 'List all global WooCommerce product attributes: id, name (slug), label, type (select/text), order_by, and whether it has archives. These are the reusable attributes you attach to products and use for variations.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'total' => [ 'type' => 'integer' ], 'attributes' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $out = [];
                foreach ( (array) wc_get_attribute_taxonomies() as $attr ) {
                    $out[] = [
                        'id'          => (int) $attr->attribute_id,
                        'name'        => $attr->attribute_name,
                        'label'       => $attr->attribute_label,
                        'taxonomy'    => wc_attribute_taxonomy_name( $attr->attribute_name ),
                        'type'        => $attr->attribute_type,
                        'order_by'    => $attr->attribute_orderby,
                        'has_archives'=> (bool) $attr->attribute_public,
                    ];
                }
                return [ 'success' => true, 'total' => count( $out ), 'attributes' => $out, 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/create-product-attribute', [
            'label'        => 'Create Product Attribute',
            'description'  => 'Create a new global product attribute. name is the human label (e.g. "Color"); the slug is derived if omitted. type is "select" (default) or "text". Returns the new attribute id and its pa_* taxonomy.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'name'         => [ 'type' => 'string', 'description' => 'Attribute label, e.g. "Color".', 'minLength' => 1 ],
                'slug'         => [ 'type' => 'string', 'description' => 'Optional slug (max 28 chars, no "pa_" prefix). Derived from name if omitted.' ],
                'type'         => [ 'type' => 'string', 'enum' => [ 'select', 'text' ], 'default' => 'select' ],
                'order_by'     => [ 'type' => 'string', 'enum' => [ 'menu_order', 'name', 'name_num', 'id' ], 'default' => 'menu_order' ],
                'has_archives' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'id' => [ 'type' => 'integer' ], 'taxonomy' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
                if ( $name === '' ) { return [ 'success' => false, 'message' => 'name is required.' ]; }
                $args = [
                    'name'         => $name,
                    'slug'         => isset( $input['slug'] ) ? wc_sanitize_taxonomy_name( (string) $input['slug'] ) : wc_sanitize_taxonomy_name( $name ),
                    'type'         => isset( $input['type'] ) ? (string) $input['type'] : 'select',
                    'order_by'     => isset( $input['order_by'] ) ? (string) $input['order_by'] : 'menu_order',
                    'has_archives' => ! empty( $input['has_archives'] ),
                ];
                $id = wc_create_attribute( $args );
                if ( is_wp_error( $id ) ) {
                    return [ 'success' => false, 'message' => 'Create failed: ' . $id->get_error_message() ];
                }
                return [ 'success' => true, 'id' => (int) $id, 'taxonomy' => wc_attribute_taxonomy_name( $args['slug'] ), 'message' => sprintf( 'Created attribute "%s".', $name ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/update-product-attribute', [
            'label'        => 'Update Product Attribute',
            'description'  => 'Update a global product attribute by id. Supply only the fields to change (name, type, order_by, has_archives). The slug cannot be changed after creation.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'id'           => [ 'type' => 'integer', 'minimum' => 1 ],
                'name'         => [ 'type' => 'string' ],
                'type'         => [ 'type' => 'string', 'enum' => [ 'select', 'text' ] ],
                'order_by'     => [ 'type' => 'string', 'enum' => [ 'menu_order', 'name', 'name_num', 'id' ] ],
                'has_archives' => [ 'type' => 'boolean' ],
            ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) { return [ 'success' => false, 'message' => 'id is required.' ]; }
                $args = [];
                if ( isset( $input['name'] ) )         { $args['name'] = (string) $input['name']; }
                if ( isset( $input['type'] ) )         { $args['type'] = (string) $input['type']; }
                if ( isset( $input['order_by'] ) )     { $args['order_by'] = (string) $input['order_by']; }
                if ( isset( $input['has_archives'] ) ) { $args['has_archives'] = ! empty( $input['has_archives'] ); }
                if ( empty( $args ) ) { return [ 'success' => true, 'message' => 'Nothing to update.' ]; }
                $result = wc_update_attribute( $id, $args );
                if ( is_wp_error( $result ) ) {
                    return [ 'success' => false, 'message' => 'Update failed: ' . $result->get_error_message() ];
                }
                return [ 'success' => true, 'message' => sprintf( 'Updated attribute %d.', $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );

        wp_register_ability( 'atarim/delete-product-attribute', [
            'label'        => 'Delete Product Attribute',
            'description'  => 'Delete a global product attribute by id. This also removes all of its terms and detaches it from products. Dry run unless confirm:true. Irreversible.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'id'      => [ 'type' => 'integer', 'minimum' => 1 ],
                'confirm' => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'deleted' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
                if ( $id <= 0 ) { return [ 'success' => false, 'message' => 'id is required.' ]; }
                $found = $self->resolve_attribute( (string) $id );
                if ( ! $found ) { return [ 'success' => false, 'message' => sprintf( 'Attribute %d not found.', $id ) ]; }
                if ( empty( $input['confirm'] ) ) {
                    return [ 'success' => true, 'deleted' => false, 'message' => sprintf( 'Dry run: would delete attribute "%s" (%d) and all its terms. Re-call with confirm:true.', $found['attribute']->attribute_label, $id ) ];
                }
                $deleted = wc_delete_attribute( $id );
                return [ 'success' => (bool) $deleted, 'deleted' => (bool) $deleted, 'message' => $deleted ? sprintf( 'Deleted attribute %d.', $id ) : 'WooCommerce did not confirm deletion.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------- attribute terms ------------------------- */

    private function register_attribute_terms() {
        $self = $this;

        wp_register_ability( 'atarim/list-product-attribute-terms', [
            'label'        => 'List Product Attribute Terms',
            'description'  => 'List the terms of one global product attribute (e.g. the Red/Green/Blue under "Color"). Identify the attribute by id, slug, or label. To edit or delete a term, use the generic update-term / delete-term abilities with the taxonomy returned here.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'attribute' => [ 'type' => 'string', 'description' => 'Attribute id, slug, or label.' ] ], 'required' => [ 'attribute' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'taxonomy' => [ 'type' => 'string' ], 'total' => [ 'type' => 'integer' ], 'terms' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $resolved = $self->resolve_attribute( isset( $input['attribute'] ) ? (string) $input['attribute'] : '' );
                if ( ! $resolved ) { return [ 'success' => false, 'message' => 'Attribute not found. Use list-product-attributes to see available ids/slugs.' ]; }
                $terms = get_terms( [ 'taxonomy' => $resolved['taxonomy'], 'hide_empty' => false ] );
                if ( is_wp_error( $terms ) ) {
                    return [ 'success' => false, 'taxonomy' => $resolved['taxonomy'], 'message' => $terms->get_error_message() ];
                }
                $out = [];
                foreach ( (array) $terms as $t ) {
                    $out[] = [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => (int) $t->count ];
                }
                return [ 'success' => true, 'taxonomy' => $resolved['taxonomy'], 'total' => count( $out ), 'terms' => $out, 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->ro_meta(),
        ] );

        wp_register_ability( 'atarim/create-product-attribute-term', [
            'label'        => 'Create Product Attribute Term',
            'description'  => 'Add a term (a value such as "Red") to a global product attribute. Identify the attribute by id, slug, or label. For editing/deleting terms afterwards, use the generic update-term / delete-term abilities with the taxonomy this returns.',
            'category'     => 'atarim',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'attribute' => [ 'type' => 'string', 'description' => 'Attribute id, slug, or label.' ],
                'name'      => [ 'type' => 'string', 'description' => 'Term name, e.g. "Red".', 'minLength' => 1 ],
                'slug'      => [ 'type' => 'string', 'description' => 'Optional term slug.' ],
            ], 'required' => [ 'attribute', 'name' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'term_id' => [ 'type' => 'integer' ], 'taxonomy' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $resolved = $self->resolve_attribute( isset( $input['attribute'] ) ? (string) $input['attribute'] : '' );
                if ( ! $resolved ) { return [ 'success' => false, 'message' => 'Attribute not found. Use list-product-attributes to see available ids/slugs.' ]; }
                $name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
                if ( $name === '' ) { return [ 'success' => false, 'message' => 'name is required.' ]; }
                if ( ! taxonomy_exists( $resolved['taxonomy'] ) ) {
                    // The pa_* taxonomy is registered by WooCommerce on init; register it
                    // transiently so term insertion works within this request.
                    register_taxonomy( $resolved['taxonomy'], [ 'product' ], [ 'hierarchical' => false ] );
                }
                $args = [];
                if ( isset( $input['slug'] ) ) { $args['slug'] = sanitize_title( (string) $input['slug'] ); }
                $term = wp_insert_term( $name, $resolved['taxonomy'], $args );
                if ( is_wp_error( $term ) ) {
                    return [ 'success' => false, 'taxonomy' => $resolved['taxonomy'], 'message' => 'Create failed: ' . $term->get_error_message() ];
                }
                return [ 'success' => true, 'term_id' => (int) $term['term_id'], 'taxonomy' => $resolved['taxonomy'], 'message' => sprintf( 'Added term "%s".', $name ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can(); },
            'meta' => $this->write_meta( false ),
        ] );
    }
}

<?php
/**
 * Shared helpers for the Mosaic ability cluster.
 *
 * IMPORTANT — RISK NOTE: Mosaic stores its data in custom database tables
 * ({prefix}mosaic_nodes / _templates / _themes / _utility_classes / _settings /
 * _collections / _variables). There is no public write API, so these helpers
 * use raw $wpdb against that third-party schema. That means: (1) version-
 * fragile — a Mosaic schema change can break this; (2) node placement depends
 * on Mosaic's own \Mosaic\Common\FractionalIndex ordering (we never reinvent
 * it; writes refuse if it's absent); (3) writes bypass parts of Mosaic's own
 * integrity/cache logic. Reads are plain SELECTs. NONE of this is runtime-
 * tested here — validate against a live Mosaic install before fleet use.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Mosaic_Helpers {

    const BLOB_MAX_DEPTH = 256;

    public static function table( $entity ) {
        global $wpdb;
        return $wpdb->prefix . 'mosaic_' . $entity;
    }

    public static function version() {
        return defined( 'MOSAIC_VERSION' ) ? (string) constant( 'MOSAIC_VERSION' ) : '';
    }
    public static function uuid() { return (string) wp_generate_uuid4(); }
    public static function now_gmt() { return current_time( 'mysql', true ); }
    public static function str( $row, $key ) { return isset( $row[ $key ] ) ? (string) $row[ $key ] : ''; }

    /* ----------------------------- blobs ------------------------------- */

    public static function decode_data( $raw ) {
        if ( is_array( $raw ) ) { return $raw; }
        if ( ! is_string( $raw ) || $raw === '' ) { return []; }
        $decoded = json_decode( $raw, true, self::BLOB_MAX_DEPTH );
        if ( ! is_array( $decoded ) ) { return [ '_decode_failed' => true, '_bytes' => strlen( $raw ) ]; }
        return $decoded;
    }
    public static function encode_data( $data ) {
        return (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }
    public static function array_depth( $value, $current = 1 ) {
        $max = $current;
        if ( $current > self::BLOB_MAX_DEPTH ) { return $current; }
        foreach ( (array) $value as $v ) { if ( is_array( $v ) ) { $d = self::array_depth( $v, $current + 1 ); if ( $d > $max ) { $max = $d; } } }
        return $max;
    }

    /* ---------------------------- select ------------------------------- */

    public static function filter_columns() {
        return [ 'themeID', 'parentType', 'parentID', 'status', 'type', 'documentType', 'documentID', 'masterID' ];
    }

    public static function build_where( $filters ) {
        $allowed = self::filter_columns();
        $where = []; $params = []; $status_explicit = false;
        foreach ( (array) $filters as $col => $val ) {
            if ( ! in_array( $col, $allowed, true ) ) { continue; }
            if ( $col === 'status' ) { $status_explicit = true; if ( (string) $val === '*' ) { continue; } }
            if ( $val === '' ) { continue; }
            $where[] = "`{$col}` = %s"; $params[] = (string) $val;
        }
        if ( ! $status_explicit ) { $where[] = '`status` = %s'; $params[] = 'publish'; }
        return [ $where, $params ];
    }

    public static function select( $entity, $filters = [], $limit = 100, $offset = 0 ) {
        global $wpdb;
        $table = self::table( $entity );
        list( $where, $params ) = self::build_where( $filters );
        $sql = "SELECT * FROM `{$table}`";
        if ( $where !== [] ) { $sql .= ' WHERE ' . implode( ' AND ', $where ); }
        $sql .= sprintf( ' ORDER BY BINARY `ordering` ASC LIMIT %d OFFSET %d', (int) $limit, (int) $offset );
        $prepared = $params === [] ? $sql : $wpdb->prepare( $sql, $params );
        if ( ! is_string( $prepared ) ) { return []; }
        $rows = $wpdb->get_results( $prepared, 'ARRAY_A' );
        return is_array( $rows ) ? $rows : [];
    }

    public static function count( $entity, $filters = [] ) {
        global $wpdb;
        $table = self::table( $entity );
        list( $where, $params ) = self::build_where( $filters );
        $sql = "SELECT COUNT(*) FROM `{$table}`";
        if ( $where !== [] ) { $sql .= ' WHERE ' . implode( ' AND ', $where ); }
        $prepared = $params === [] ? $sql : $wpdb->prepare( $sql, $params );
        return is_string( $prepared ) ? (int) $wpdb->get_var( $prepared ) : 0;
    }

    public static function load( $entity, $id ) {
        global $wpdb;
        $table = self::table( $entity );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `ID` = %s LIMIT 1", $id ), 'ARRAY_A' );
        return is_array( $row ) ? $row : null;
    }

    public static function load_node( $document_type, $document_id, $id ) {
        global $wpdb;
        $table = self::table( 'nodes' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `documentType` = %s AND `documentID` = %s AND `ID` = %s LIMIT 1", $document_type, $document_id, $id ), 'ARRAY_A' );
        return is_array( $row ) ? $row : null;
    }

    /* --------------------------- row mappers --------------------------- */

    public static function map_theme_row( $r ) {
        return [ 'id' => self::str( $r, 'ID' ), 'name' => self::str( $r, 'name' ), 'version' => self::str( $r, 'version' ), 'revision' => self::str( $r, 'revision' ), 'ordering' => self::str( $r, 'ordering' ), 'status' => self::str( $r, 'status' ), 'modified_gmt' => self::str( $r, 'modified_gmt' ) ];
    }
    public static function map_template_row( $r ) {
        return [ 'id' => self::str( $r, 'ID' ), 'name' => self::str( $r, 'name' ), 'theme_id' => self::str( $r, 'themeID' ), 'master_id' => self::str( $r, 'masterID' ), 'assign' => self::str( $r, 'assign' ), 'path' => self::str( $r, 'path' ), 'ordering' => self::str( $r, 'ordering' ), 'status' => self::str( $r, 'status' ), 'modified_gmt' => self::str( $r, 'modified_gmt' ) ];
    }
    public static function map_component_row( $r ) {
        return [ 'id' => self::str( $r, 'ID' ), 'theme_id' => self::str( $r, 'themeID' ), 'name' => self::str( $r, 'name' ), 'path' => self::str( $r, 'path' ), 'parent_id' => self::str( $r, 'parentID' ), 'parent_type' => self::str( $r, 'parentType' ), 'ordering' => self::str( $r, 'ordering' ), 'status' => self::str( $r, 'status' ) ];
    }
    public static function map_collection_row( $r ) {
        $data = self::decode_data( isset( $r['data'] ) ? $r['data'] : null );
        return [ 'id' => self::str( $r, 'ID' ), 'theme_id' => self::str( $r, 'themeID' ), 'parent_id' => self::str( $r, 'parentID' ), 'parent_type' => self::str( $r, 'parentType' ), 'ordering' => self::str( $r, 'ordering' ), 'status' => self::str( $r, 'status' ), 'name' => is_string( $data['name'] ?? null ) ? $data['name'] : '', 'data' => $data ];
    }
    public static function map_variable_row( $r ) {
        $data = self::decode_data( isset( $r['data'] ) ? $r['data'] : null );
        return [ 'id' => self::str( $r, 'ID' ), 'theme_id' => self::str( $r, 'themeID' ), 'collection_id' => self::str( $r, 'parentID' ), 'ordering' => self::str( $r, 'ordering' ), 'status' => self::str( $r, 'status' ), 'name' => is_string( $data['name'] ?? null ) ? $data['name'] : '', 'custom_property' => is_string( $data['customProperty'] ?? null ) ? $data['customProperty'] : '' ];
    }
    public static function map_utility_class_row( $r ) {
        $data = self::decode_data( isset( $r['data'] ) ? $r['data'] : null );
        return [ 'id' => self::str( $r, 'ID' ), 'theme_id' => self::str( $r, 'themeID' ), 'ordering' => self::str( $r, 'ordering' ), 'status' => self::str( $r, 'status' ), 'name' => is_string( $data['name'] ?? null ) ? $data['name'] : '', 'css_class' => is_string( $data['cssClass'] ?? null ) ? $data['cssClass'] : '' ];
    }
    public static function present_template( $row ) {
        return array_merge( self::map_template_row( $row ), [ 'version' => self::str( $row, 'version' ), 'revision' => self::str( $row, 'revision' ), 'conditions' => self::decode_data( isset( $row['conditions'] ) ? $row['conditions'] : null ) ] );
    }
    public static function present_theme( $row ) {
        return array_merge( self::map_theme_row( $row ), [ 'data' => self::decode_data( isset( $row['data'] ) ? $row['data'] : null ) ] );
    }
    public static function present_node( $row ) {
        return [
            'id' => self::str( $row, 'ID' ), 'type' => self::str( $row, 'type' ),
            'theme_id' => self::str( $row, 'themeID' ), 'document_type' => self::str( $row, 'documentType' ), 'document_id' => self::str( $row, 'documentID' ),
            'parent_type' => self::str( $row, 'parentType' ), 'parent_id' => self::str( $row, 'parentID' ),
            'ordering' => self::str( $row, 'ordering' ), 'status' => self::str( $row, 'status' ),
            'data' => self::decode_data( isset( $row['data'] ) ? $row['data'] : null ),
        ];
    }

    /* ---------------------------- ordering ----------------------------- */

    public static function root_parent_type( $document_type ) {
        $supported = [ 'template', 'master', 'styleGuide', 'component' ];
        return in_array( $document_type, $supported, true ) ? $document_type : null;
    }

    /** @return string|null  fractional key, or null = error (FractionalIndex missing / bad bounds). */
    public static function ordering_between( $before, $after ) {
        if ( ! class_exists( '\Mosaic\Common\FractionalIndex' ) ) { return null; }
        try { return (string) call_user_func( [ '\Mosaic\Common\FractionalIndex', 'generateKeyBetween' ], $before, $after ); }
        catch ( \Throwable $e ) { return null; }
    }

    public static function ordering_at_end( $document_type, $document_id, $parent_type, $parent_id ) {
        global $wpdb;
        $table = self::table( 'nodes' );
        $last = $wpdb->get_var( $wpdb->prepare(
            "SELECT `ordering` FROM `{$table}` WHERE `documentType` = %s AND `documentID` = %s AND `parentType` = %s AND `parentID` = %s AND `status` = 'publish' ORDER BY BINARY `ordering` DESC LIMIT 1",
            $document_type, $document_id, $parent_type, $parent_id
        ) );
        $last_str = is_string( $last ) && $last !== '' ? $last : null;
        return self::ordering_between( $last_str, null );
    }

    public static function sibling_ordering( $document_type, $document_id, $parent_type, $parent_id, $direction ) {
        global $wpdb;
        $table = self::table( 'nodes' );
        $dir = $direction === 'ASC' ? 'ASC' : 'DESC';
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT `ordering` FROM `{$table}` WHERE `documentType` = %s AND `documentID` = %s AND `parentType` = %s AND `parentID` = %s AND `status` = 'publish' ORDER BY BINARY `ordering` {$dir} LIMIT 1",
            $document_type, $document_id, $parent_type, $parent_id
        ) );
        return is_string( $val ) && $val !== '' ? $val : null;
    }

    public static function node_ordering( $document_type, $document_id, $node_id ) {
        global $wpdb;
        $table = self::table( 'nodes' );
        $ord = $wpdb->get_var( $wpdb->prepare(
            "SELECT `ordering` FROM `{$table}` WHERE `documentType` = %s AND `documentID` = %s AND `ID` = %s AND `status` = 'publish' LIMIT 1",
            $document_type, $document_id, $node_id
        ) );
        return is_string( $ord ) && $ord !== '' ? $ord : null;
    }

    /** Find the template-internal canvas wrapper node id under a template document, or null. */
    public static function template_internal_id( $document_id ) {
        global $wpdb;
        $table = self::table( 'nodes' );
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT `ID` FROM `{$table}` WHERE `documentType` = 'template' AND `documentID` = %s AND `type` = 'template-internal' AND `status` = 'publish' ORDER BY BINARY `ordering` ASC LIMIT 1",
            $document_id
        ) );
        return is_string( $id ) && $id !== '' ? $id : null;
    }

    /**
     * Resolve a requested parent into [parent_type, parent_id], applying Mosaic's
     * auto-routing: parent_id == document_id on a template nests under the
     * template-internal wrapper (else the content would orphan).
     * @return array|null  [parent_type, parent_id] or null if the parent is invalid.
     */
    public static function resolve_parent( $document_type, $document_id, $parent_id ) {
        if ( $parent_id === $document_id || $parent_id === '' ) {
            if ( $document_type === 'template' ) {
                $internal = self::template_internal_id( $document_id );
                if ( $internal !== null ) { return [ 'node', $internal ]; }
            }
            $rpt = self::root_parent_type( $document_type );
            if ( $rpt === null ) { return null; }
            return [ $rpt, $document_id ];
        }
        // Must be an existing publish node in this document.
        if ( self::node_ordering( $document_type, $document_id, $parent_id ) === null ) { return null; }
        return [ 'node', $parent_id ];
    }

    /* --------------------------- node writes --------------------------- */

    /** Compute ordering for a placement. Returns string or null on error. */
    public static function compute_placement( $document_type, $document_id, $parent_type, $parent_id, $placement, $anchor_id ) {
        if ( $placement === 'at_end' || $placement === '' ) {
            return self::ordering_at_end( $document_type, $document_id, $parent_type, $parent_id );
        }
        if ( $placement === 'at_start' ) {
            $first = self::sibling_ordering( $document_type, $document_id, $parent_type, $parent_id, 'ASC' );
            return self::ordering_between( null, $first );
        }
        // before / after an anchor
        if ( $anchor_id === '' ) { return null; }
        $anchor_ord = self::node_ordering( $document_type, $document_id, $anchor_id );
        if ( $anchor_ord === null ) { return null; }
        if ( $placement === 'before' ) {
            $prev = self::neighbor_ordering( $document_type, $document_id, $parent_type, $parent_id, $anchor_ord, 'before' );
            return self::ordering_between( $prev, $anchor_ord );
        }
        if ( $placement === 'after' ) {
            $next = self::neighbor_ordering( $document_type, $document_id, $parent_type, $parent_id, $anchor_ord, 'after' );
            return self::ordering_between( $anchor_ord, $next );
        }
        return null;
    }

    public static function neighbor_ordering( $document_type, $document_id, $parent_type, $parent_id, $anchor_ord, $side ) {
        global $wpdb;
        $table = self::table( 'nodes' );
        if ( $side === 'before' ) {
            $val = $wpdb->get_var( $wpdb->prepare( "SELECT `ordering` FROM `{$table}` WHERE `documentType`=%s AND `documentID`=%s AND `parentType`=%s AND `parentID`=%s AND `status`='publish' AND BINARY `ordering` < %s ORDER BY BINARY `ordering` DESC LIMIT 1", $document_type, $document_id, $parent_type, $parent_id, $anchor_ord ) );
        } else {
            $val = $wpdb->get_var( $wpdb->prepare( "SELECT `ordering` FROM `{$table}` WHERE `documentType`=%s AND `documentID`=%s AND `parentType`=%s AND `parentID`=%s AND `status`='publish' AND BINARY `ordering` > %s ORDER BY BINARY `ordering` ASC LIMIT 1", $document_type, $document_id, $parent_type, $parent_id, $anchor_ord ) );
        }
        return is_string( $val ) && $val !== '' ? $val : null;
    }

    /** Insert a node row. Returns the new node id or null on failure. */
    public static function insert_node( $ctx ) {
        global $wpdb;
        $node_id = self::uuid();
        $ok = $wpdb->insert( self::table( 'nodes' ), [
            'themeID'      => $ctx['theme_id'],
            'documentType' => $ctx['document_type'],
            'documentID'   => $ctx['document_id'],
            'ID'           => $node_id,
            'parentType'   => $ctx['parent_type'],
            'parentID'     => $ctx['parent_id'],
            'ordering'     => $ctx['ordering'],
            'status'       => 'publish',
            'type'         => $ctx['type'],
            'data'         => self::encode_data( is_array( $ctx['data'] ) ? $ctx['data'] : [] ),
            'revision'     => self::uuid(),
            'version'      => self::version(),
        ], array_fill( 0, 12, '%s' ) );
        return $ok === false ? null : $node_id;
    }

    /** Collect a node id + all publish descendants (within its document) by walking parentID. */
    public static function collect_subtree_ids( $document_type, $document_id, $root_id ) {
        global $wpdb;
        $table = self::table( 'nodes' );
        $ids = [ $root_id ];
        $frontier = [ $root_id ];
        $guard = 0;
        while ( $frontier && $guard < 10000 ) {
            $guard += count( $frontier );
            $placeholders = implode( ',', array_fill( 0, count( $frontier ), '%s' ) );
            $params = array_merge( [ $document_type, $document_id ], $frontier );
            $sql = "SELECT `ID` FROM `{$table}` WHERE `documentType`=%s AND `documentID`=%s AND `parentType`='node' AND `parentID` IN ({$placeholders}) AND `status`='publish'";
            $children = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
            $children = is_array( $children ) ? array_values( array_filter( array_map( 'strval', $children ) ) ) : [];
            $children = array_values( array_diff( $children, $ids ) );
            foreach ( $children as $c ) { $ids[] = $c; }
            $frontier = $children;
        }
        return $ids;
    }
}

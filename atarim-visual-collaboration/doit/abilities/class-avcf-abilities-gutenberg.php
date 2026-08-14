<?php
/**
 * Gutenberg — MCP abilities (native block editing) + shared helpers.
 *
 * Single-file cluster (matches the doit/abilities/ convention). Two classes:
 *   AVCF_Gutenberg_Helpers  — block-tree round-trip (parse_blocks/serialize_blocks)
 *     with stable metadata.avcBlockId identity, content hashing, block-type +
 *     editability classification, and path/id addressing.
 *   AVCF_Abilities_Gutenberg — the abilities (reads now: read-page, read-block,
 *     list-block-types; the apply-operations write engine + markdown bridge land here).
 *
 * Page/post creation and revisions are intentionally NOT here — use the existing
 * Content-cluster abilities (create-content, list-revisions, restore-revision).
 * Built on core WP block functions; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Gutenberg_Helpers {

    /** Curated static blocks whose canonical markup the bridge can regenerate. */
    public static function curated_blocks() {
        return [
            'core/paragraph', 'core/heading', 'core/list', 'core/list-item',
            'core/quote', 'core/pullquote', 'core/code', 'core/preformatted',
            'core/image', 'core/separator', 'core/spacer', 'core/buttons',
            'core/button', 'core/group', 'core/columns', 'core/column', 'core/html',
        ];
    }

    public static function uuid() { return (string) wp_generate_uuid4(); }

    public static function content_hash( $content ) { return sha1( (string) $content ); }

    /* ---------------------------- round-trip ---------------------------
     *
     * Read and write are deliberately ASYMMETRIC. Do not "tidy" this into a
     * symmetric tree<->markup round-trip; that is the bug this replaced.
     *
     *   READ   parse_tree() projects the raw parse_blocks() array into a lean
     *          nested view for the model. It is LOSSY BY DESIGN: it drops the
     *          literal HTML chunks that live between a container's children
     *          (the <ul class="wp-block-list"> around list items, the
     *          <div class="wp-block-group"> around a group's children, ...)
     *          and the whitespace nodes between siblings. It must NEVER be
     *          serialized back into post_content.
     *
     *   WRITE  apply_operations() mutates the raw parse_blocks() array in
     *          place and hands it straight to serialize_blocks(). Nothing is
     *          translated, so nothing is lost. Same shape as do-it.php.
     *
     * Paths are chains of RAW parse_blocks indices, so a path taken off the
     * read view addresses the same block in the raw array. The read view skips
     * whitespace nodes, which means published paths are NOT contiguous
     * (0, 2, 4, ...). Never renumber them.
     *
     * A block's innerContent is the interleave that serialize_block() walks:
     * every string is emitted literally, every null consumes the next entry of
     * innerBlocks. Adding or removing a child WITHOUT keeping the null count in
     * step silently drops or duplicates content -- see splice_children() and
     * unsplice_child(), which are the only two places allowed to touch it.
     */

    public static function parse_raw( $content ) {
        return parse_blocks( (string) $content );
    }

    public static function serialize_raw( $blocks ) {
        return serialize_blocks( (array) $blocks );
    }

    public static function parse_tree( $content ) {
        return self::blocks_to_tree( parse_blocks( (string) $content ) );
    }

    /** Read-only projection. Each node carries its raw index so paths stay truthful. */
    private static function blocks_to_tree( $blocks ) {
        $tree = [];
        foreach ( (array) $blocks as $i => $block ) {
            $name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
            $inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
            $inner_html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
            if ( $name === '' && trim( $inner_html ) === '' ) { continue; } // whitespace between blocks
            $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
            $node = [ 'index' => (int) $i, 'block' => $name ];
            $id = self::extract_id( $attrs );
            if ( $id !== '' ) { $node['id'] = $id; }
            if ( $attrs !== [] ) { $node['attrs'] = $attrs; }
            if ( $inner_blocks ) { $node['children'] = self::blocks_to_tree( $inner_blocks ); }
            elseif ( trim( $inner_html ) !== '' ) { $node['html'] = $inner_html; }
            $tree[] = $node;
        }
        return $tree;
    }

    public static function extract_id( $attrs ) {
        if ( is_array( $attrs ) && isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) && isset( $attrs['metadata']['avcBlockId'] ) && is_string( $attrs['metadata']['avcBlockId'] ) ) {
            return $attrs['metadata']['avcBlockId'];
        }
        return '';
    }
    public static function set_id( $attrs, $id ) {
        if ( ! is_array( $attrs ) ) { $attrs = []; }
        if ( ! isset( $attrs['metadata'] ) || ! is_array( $attrs['metadata'] ) ) { $attrs['metadata'] = []; }
        $attrs['metadata']['avcBlockId'] = (string) $id;
        return $attrs;
    }

    /** Is the post block-based (has block delimiters) vs classic HTML? */
    public static function is_block_based( $content ) {
        return strpos( (string) $content, '<!-- wp:' ) !== false;
    }
    /** Classic content carried as a freeform block with real HTML (edit would be lossy). */
    public static function is_classic( $content ) {
        $c = (string) $content;
        return $c !== '' && ! self::is_block_based( $c );
    }

    /* --------------------------- block types --------------------------- */

    public static function block_type_info( $name ) {
        $info = [ 'name' => (string) $name, 'exists' => false, 'dynamic' => false, 'title' => '', 'category' => '' ];
        if ( ! class_exists( '\WP_Block_Type_Registry' ) ) { return $info; }
        $reg = \WP_Block_Type_Registry::get_instance();
        $type = $reg ? $reg->get_registered( $name ) : null;
        if ( $type === null ) { return $info; }
        $info['exists'] = true;
        $info['dynamic'] = ! empty( $type->render_callback );
        $info['title'] = isset( $type->title ) ? (string) $type->title : '';
        $info['category'] = isset( $type->category ) ? (string) $type->category : '';
        return $info;
    }

    /**
     * Editability class for a block type:
     *   'attr'       dynamic block — attrs editable, no innerHTML validation risk
     *   'bridge'     curated static block — canonical markup regenerable
     *   'structural' other/unknown static — move/delete/duplicate only, or raw markup
     */
    public static function editability( $name ) {
        $info = self::block_type_info( $name );
        if ( $info['dynamic'] ) { return 'attr'; }
        if ( in_array( $name, self::curated_blocks(), true ) ) { return 'bridge'; }
        return 'structural';
    }

    /**
     * Valid attribute keys for a block type: the block's declared attributes plus
     * a conservative allowlist of universal / block-supports attributes WordPress
     * permits broadly (className, style, align, colour/typography supports, ...).
     * Returns null when validation is not meaningful (unknown block, or a block
     * that declares no attributes) so callers SKIP validation rather than warn on
     * everything. Advisory only — never used to block a write.
     *
     * @param string $name Block name, e.g. "core/paragraph".
     * @return array|null
     */
    public static function block_attr_keys( $name ) {
        if ( ! class_exists( '\WP_Block_Type_Registry' ) ) { return null; }
        $reg  = \WP_Block_Type_Registry::get_instance();
        $type = $reg ? $reg->get_registered( $name ) : null;
        if ( null === $type ) { return null; }
        $declared = ( isset( $type->attributes ) && is_array( $type->attributes ) ) ? array_keys( $type->attributes ) : [];
        if ( empty( $declared ) ) { return null; } // nothing to validate against
        $universal = [ 'className', 'anchor', 'lock', 'metadata', 'align', 'style', 'backgroundColor', 'textColor', 'gradient', 'fontSize', 'fontFamily', 'layout', 'borderColor' ];
        return array_values( array_unique( array_merge( $declared, $universal ) ) );
    }

    public static function list_block_types( $search = '' ) {
        $out = [];
        if ( ! class_exists( '\WP_Block_Type_Registry' ) ) { return $out; }
        $reg = \WP_Block_Type_Registry::get_instance();
        if ( ! $reg || ! method_exists( $reg, 'get_all_registered' ) ) { return $out; }
        $search = strtolower( (string) $search );
        foreach ( $reg->get_all_registered() as $name => $type ) {
            $name = (string) $name;
            $title = isset( $type->title ) ? (string) $type->title : '';
            if ( $search !== '' && strpos( strtolower( $name ), $search ) === false && strpos( strtolower( $title ), $search ) === false ) { continue; }
            $out[] = [
                'name' => $name,
                'title' => $title,
                'category' => isset( $type->category ) ? (string) $type->category : '',
                'dynamic' => ! empty( $type->render_callback ),
                'editability' => self::editability( $name ),
            ];
        }
        usort( $out, function( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
        return $out;
    }

    /* ----------------------------- address ----------------------------- */

    public static function address_parts( $address ) {
        $address = trim( (string) $address );
        if ( $address === '' || $address === 'root' ) { return []; }
        $parts = [];
        foreach ( explode( '/', $address ) as $seg ) {
            if ( $seg === '' || ! ctype_digit( $seg ) ) { return null; }
            if ( $seg !== '0' && $seg[0] === '0' ) { return null; }
            $parts[] = (int) $seg;
        }
        return $parts;
    }

    /** Walk a raw parse_blocks() array by index-path. Returns the raw block or null. */
    public static function raw_node_at( $blocks, $address ) {
        $parts = self::address_parts( $address );
        if ( $parts === null ) { return null; }
        $nodes = (array) $blocks; $found = null;
        foreach ( $parts as $i ) {
            if ( ! array_key_exists( $i, $nodes ) ) { return null; }
            $found = $nodes[ $i ];
            $nodes = isset( $found['innerBlocks'] ) && is_array( $found['innerBlocks'] ) ? $found['innerBlocks'] : [];
        }
        return $found;
    }

    /** Find a raw block by its avcBlockId. Returns ['block'=>, 'path'=>] or null. */
    public static function raw_find_by_id( $blocks, $id, $prefix = '' ) {
        $id = (string) $id;
        if ( $id === '' ) { return null; }
        foreach ( (array) $blocks as $i => $block ) {
            $path = $prefix === '' ? (string) $i : $prefix . '/' . $i;
            $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
            if ( self::extract_id( $attrs ) === $id ) { return [ 'block' => $block, 'path' => $path ]; }
            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $hit = self::raw_find_by_id( $block['innerBlocks'], $id, $path );
                if ( $hit !== null ) { return $hit; }
            }
        }
        return null;
    }

    /* ----------------------------- views ------------------------------- */

    /** Lean nested read model. include_attrs adds attrs/html; depth caps recursion. */
    public static function tree_view( $tree, $include_attrs = false, $max_depth = 64, $prefix = '', $depth = 1 ) {
        $out = [];
        foreach ( $tree as $node ) {
            $i = isset( $node['index'] ) ? (int) $node['index'] : 0;
            $path = $prefix === '' ? (string) $i : $prefix . '/' . $i;
            $children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
            $row = [
                'path' => $path,
                'id' => isset( $node['id'] ) ? (string) $node['id'] : null,
                'block' => isset( $node['block'] ) ? (string) $node['block'] : '',
                'child_count' => count( $children ),
            ];
            if ( $include_attrs ) {
                if ( isset( $node['attrs'] ) ) { $row['attrs'] = $node['attrs']; }
                if ( isset( $node['html'] ) ) { $row['html'] = (string) $node['html']; }
            }
            if ( $children ) {
                if ( $depth >= $max_depth ) { $row['children_truncated'] = true; }
                else { $row['children'] = self::tree_view( $children, $include_attrs, $max_depth, $path, $depth + 1 ); }
            }
            $out[] = $row;
        }
        return $out;
    }

    public static function tree_count( $tree ) {
        $n = 0;
        foreach ( (array) $tree as $node ) {
            $n++;
            if ( isset( $node['children'] ) && is_array( $node['children'] ) ) { $n += self::tree_count( $node['children'] ); }
        }
        return $n;
    }

    /** Detail view of one RAW block. `markup` is exact and safe to hand back to update.markup. */
    public static function raw_full_node( $block, $path ) {
        $name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
        $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
        $inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
        $id    = self::extract_id( $attrs );
        return [
            'path' => $path,
            'id' => $id !== '' ? $id : null,
            'block' => $name,
            'attrs' => $attrs,
            'html' => isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '',
            'children' => $inner ? self::tree_view( self::blocks_to_tree( $inner ), false, 64, $path, 1 ) : [],
            'markup' => serialize_block( $block ),
            'editability' => self::editability( $name ),
        ];
    }

    /* --------------------------- mutation ------------------------------ */

    public static function split_address( $address ) {
        $parts = self::address_parts( $address );
        if ( $parts === null || $parts === [] ) { return [ '', null ]; }
        $index = array_pop( $parts );
        return [ implode( '/', $parts ), $index ];
    }

    /*
     * The document root is not a block, so it has no innerContent. Wrapping it in
     * a synthetic parent lets every address -- root included -- go through the same
     * child-splice code, and the synthetic innerContent (all placeholders, no
     * literals) is discarded on unwrap. Only innerBlocks survives.
     */
    private static function wrap_root( $blocks ) {
        $blocks = array_values( (array) $blocks );
        return [ 'blockName' => null, 'attrs' => [], 'innerBlocks' => $blocks, 'innerHTML' => '', 'innerContent' => array_fill( 0, count( $blocks ), null ) ];
    }
    private static function unwrap_root( $root ) {
        return ( is_array( $root ) && isset( $root['innerBlocks'] ) && is_array( $root['innerBlocks'] ) ) ? array_values( $root['innerBlocks'] ) : [];
    }

    /** Run $fn on the block at $parts (relative to $block). $fn( array $block ): array|null. */
    private static function raw_apply_at( $block, $parts, $fn ) {
        if ( $parts === [] ) { return call_user_func( $fn, $block ); }
        $i = array_shift( $parts );
        $inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
        if ( ! array_key_exists( $i, $inner ) ) { return null; }
        $updated = self::raw_apply_at( $inner[ $i ], $parts, $fn );
        if ( $updated === null ) { return null; }
        $inner[ $i ] = $updated;
        $block['innerBlocks'] = $inner;
        return $block;
    }

    /** Offsets in innerContent that stand in for an inner block, in order. */
    private static function placeholder_offsets( $inner_content ) {
        $pos = [];
        foreach ( (array) $inner_content as $i => $chunk ) { if ( ! is_string( $chunk ) ) { $pos[] = $i; } }
        return $pos;
    }

    /**
     * A container with no children yet has no placeholder to insert against, and its
     * wrapper is one opaque literal ("<div class=\"wp-block-group\"></div>"). Split it
     * just before its final closing tag so children land INSIDE the wrapper.
     * Returns a new innerContent with exactly one placeholder, or null if the wrapper
     * can't be opened safely -- in which case the caller refuses rather than guesses.
     */
    private static function open_empty_container( $inner_content ) {
        $joined = '';
        foreach ( (array) $inner_content as $chunk ) { if ( is_string( $chunk ) ) { $joined .= $chunk; } }
        if ( $joined === '' ) { return [ null ]; }
        if ( trim( $joined ) === '' ) { return [ $joined, null ]; }
        if ( ! preg_match( '~</[A-Za-z][A-Za-z0-9:-]*>\s*$~', $joined, $m, PREG_OFFSET_CAPTURE ) ) { return null; }
        $at = (int) $m[0][1];
        return [ substr( $joined, 0, $at ), null, substr( $joined, $at ) ];
    }

    /** Insert raw blocks into $parent's children at $k, keeping innerContent in step. */
    private static function splice_children( $parent, $k, $new ) {
        $new = array_values( (array) $new );
        if ( $new === [] ) { return $parent; }
        $inner = isset( $parent['innerBlocks'] ) && is_array( $parent['innerBlocks'] ) ? $parent['innerBlocks'] : [];
        $ic    = isset( $parent['innerContent'] ) && is_array( $parent['innerContent'] ) ? array_values( $parent['innerContent'] ) : [];
        $k     = max( 0, min( (int) $k, count( $inner ) ) );
        $slots = self::placeholder_offsets( $ic );

        if ( $slots === [] ) {
            $opened = self::open_empty_container( $ic );
            if ( $opened === null ) { return null; }
            $ic  = $opened;
            $at  = self::placeholder_offsets( $ic );
            $at  = $at[0];
            array_splice( $ic, $at, 1 ); // drop the vacant placeholder; real ones go in below
        } else {
            $at = ( $k < count( $slots ) ) ? $slots[ $k ] : ( $slots[ count( $slots ) - 1 ] + 1 );
        }

        array_splice( $inner, $k, 0, $new );
        array_splice( $ic, $at, 0, array_fill( 0, count( $new ), null ) );
        $parent['innerBlocks']  = $inner;
        $parent['innerContent'] = $ic;
        return $parent;
    }

    /** Remove $parent's child at $k, keeping innerContent in step. */
    private static function unsplice_child( $parent, $k ) {
        $inner = isset( $parent['innerBlocks'] ) && is_array( $parent['innerBlocks'] ) ? $parent['innerBlocks'] : [];
        $ic    = isset( $parent['innerContent'] ) && is_array( $parent['innerContent'] ) ? array_values( $parent['innerContent'] ) : [];
        if ( ! array_key_exists( $k, $inner ) ) { return null; }
        $slots = self::placeholder_offsets( $ic );
        array_splice( $inner, $k, 1 );
        if ( isset( $slots[ $k ] ) ) { array_splice( $ic, $slots[ $k ], 1 ); }
        $parent['innerBlocks']  = $inner;
        $parent['innerContent'] = $ic;
        return $parent;
    }

    public static function raw_replace_at( $blocks, $address, $new_block ) {
        list( $parent, $index ) = self::split_address( $address );
        if ( $index === null ) { return null; }
        $parts = self::address_parts( $parent );
        if ( $parts === null ) { return null; }
        $root = self::raw_apply_at( self::wrap_root( $blocks ), $parts, function( $p ) use ( $index, $new_block ) {
            $inner = isset( $p['innerBlocks'] ) && is_array( $p['innerBlocks'] ) ? $p['innerBlocks'] : [];
            if ( ! array_key_exists( $index, $inner ) ) { return null; }
            $inner[ $index ] = $new_block;   // placeholder count unchanged, innerContent untouched
            $p['innerBlocks'] = $inner;
            return $p;
        } );
        return $root === null ? null : self::unwrap_root( $root );
    }

    public static function raw_remove_at( $blocks, $address ) {
        list( $parent, $index ) = self::split_address( $address );
        if ( $index === null ) { return null; }
        $parts = self::address_parts( $parent );
        if ( $parts === null ) { return null; }
        $root = self::raw_apply_at( self::wrap_root( $blocks ), $parts, function( $p ) use ( $index ) {
            return self::unsplice_child( $p, $index );
        } );
        return $root === null ? null : self::unwrap_root( $root );
    }

    /** $new_blocks is a list of raw blocks. position null = append. */
    public static function raw_insert_at( $blocks, $parent_address, $position, $new_blocks ) {
        $parts = self::address_parts( $parent_address );
        if ( $parts === null ) { return null; }
        $root = self::raw_apply_at( self::wrap_root( $blocks ), $parts, function( $p ) use ( $position, $new_blocks ) {
            $n = isset( $p['innerBlocks'] ) && is_array( $p['innerBlocks'] ) ? count( $p['innerBlocks'] ) : 0;
            return self::splice_children( $p, $position === null ? $n : (int) $position, $new_blocks );
        } );
        return $root === null ? null : self::unwrap_root( $root );
    }

    /** Stamp avcBlockId on every named block lacking one (recursive, raw blocks). */
    public static function raw_stamp_ids( $blocks ) {
        $out = [];
        foreach ( (array) $blocks as $b ) {
            if ( ! empty( $b['blockName'] ) ) {
                $attrs = isset( $b['attrs'] ) && is_array( $b['attrs'] ) ? $b['attrs'] : [];
                if ( self::extract_id( $attrs ) === '' ) { $b['attrs'] = self::set_id( $attrs, self::uuid() ); }
            }
            if ( ! empty( $b['innerBlocks'] ) && is_array( $b['innerBlocks'] ) ) { $b['innerBlocks'] = self::raw_stamp_ids( $b['innerBlocks'] ); }
            $out[] = $b;
        }
        return $out;
    }

    /** Deep-copy a raw block, reassigning fresh avcBlockIds where present. */
    public static function raw_clone( $block, $reassign_ids = true ) {
        if ( $reassign_ids && ! empty( $block['blockName'] ) ) {
            $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
            if ( self::extract_id( $attrs ) !== '' ) { $block['attrs'] = self::set_id( $attrs, self::uuid() ); }
        }
        if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
            $kids = [];
            foreach ( $block['innerBlocks'] as $c ) { $kids[] = self::raw_clone( $c, $reassign_ids ); }
            $block['innerBlocks'] = $kids;
        }
        return $block;
    }

    /* ----------------------- markdown / markup bridge ------------------ */

    /** Parsed markup as RAW blocks, with leading/trailing whitespace nodes trimmed. */
    public static function markup_to_blocks( $markup ) { return self::trim_edge_whitespace( parse_blocks( (string) $markup ) ); }
    public static function markdown_to_blocks( $md ) { return self::markup_to_blocks( self::markdown_to_markup( $md ) ); }

    private static function trim_edge_whitespace( $blocks ) {
        $blocks = array_values( (array) $blocks );
        while ( $blocks && self::is_whitespace_block( $blocks[0] ) ) { array_shift( $blocks ); }
        while ( $blocks && self::is_whitespace_block( $blocks[ count( $blocks ) - 1 ] ) ) { array_pop( $blocks ); }
        return $blocks;
    }
    private static function is_whitespace_block( $b ) {
        return empty( $b['blockName'] ) && trim( isset( $b['innerHTML'] ) ? (string) $b['innerHTML'] : '' ) === '';
    }

    /**
     * Build one raw block from the `block` channel ({block, attrs, html}).
     * Deliberately leaf-only: a container's wrapper markup cannot be inferred from a
     * name, and guessing it is what stripped the wrappers in the first place. Callers
     * with children should use the markup channel.
     */
    public static function block_to_raw( $b ) {
        $name = isset( $b['block'] ) ? (string) $b['block'] : '';
        if ( $name === '' ) { return null; }
        $attrs = isset( $b['attrs'] ) && is_array( $b['attrs'] ) ? $b['attrs'] : [];
        $html  = isset( $b['html'] ) ? (string) $b['html'] : '';
        return [ 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => $html !== '' ? [ $html ] : [] ];
    }

    /** Convert a curated subset of Markdown to canonical core-block markup. */
    public static function markdown_to_markup( $md ) {
        $md = str_replace( [ "\r\n", "\r" ], "\n", (string) $md );
        $lines = explode( "\n", $md );
        $n = count( $lines ); $i = 0; $blocks = [];
        while ( $i < $n ) {
            $line = $lines[ $i ]; $t = trim( $line );
            if ( $t === '' ) { $i++; continue; }
            if ( preg_match( '/^```/', $t ) ) {
                $code = []; $i++;
                while ( $i < $n && ! preg_match( '/^```/', trim( $lines[ $i ] ) ) ) { $code[] = $lines[ $i ]; $i++; }
                $i++;
                $blocks[] = self::md_code( implode( "\n", $code ) ); continue;
            }
            if ( preg_match( '/^(#{1,6})\s+(.*)$/', $t, $m ) ) { $blocks[] = self::md_heading( strlen( $m[1] ), self::md_inline( $m[2] ) ); $i++; continue; }
            if ( preg_match( '/^(-{3,}|\*{3,}|_{3,})$/', $t ) ) { $blocks[] = self::md_separator(); $i++; continue; }
            if ( preg_match( '/^>\s?/', $t ) ) {
                $q = [];
                while ( $i < $n && preg_match( '/^>\s?(.*)$/', trim( $lines[ $i ] ), $mm ) ) { $q[] = $mm[1]; $i++; }
                $blocks[] = self::md_quote( $q ); continue;
            }
            if ( preg_match( '/^[-*+]\s+/', $t ) ) {
                $items = [];
                while ( $i < $n && preg_match( '/^[-*+]\s+(.*)$/', trim( $lines[ $i ] ), $mm ) ) { $items[] = self::md_inline( $mm[1] ); $i++; }
                $blocks[] = self::md_list( $items, false ); continue;
            }
            if ( preg_match( '/^\d+\.\s+/', $t ) ) {
                $items = [];
                while ( $i < $n && preg_match( '/^\d+\.\s+(.*)$/', trim( $lines[ $i ] ), $mm ) ) { $items[] = self::md_inline( $mm[1] ); $i++; }
                $blocks[] = self::md_list( $items, true ); continue;
            }
            if ( preg_match( '/^!\[([^\]]*)\]\(([^)\s]+)\)\s*$/', $t, $m ) ) { $blocks[] = self::md_image( $m[2], $m[1] ); $i++; continue; }
            $para = [ $line ]; $i++;
            while ( $i < $n && trim( $lines[ $i ] ) !== '' && ! self::md_is_block_start( trim( $lines[ $i ] ) ) ) { $para[] = $lines[ $i ]; $i++; }
            $blocks[] = self::md_paragraph( self::md_inline( implode( '<br>', array_map( 'trim', $para ) ) ) );
        }
        return implode( "\n\n", $blocks );
    }
    private static function md_is_block_start( $t ) {
        return (bool) ( preg_match( '/^(#{1,6}\s|>\s?|[-*+]\s|\d+\.\s|```|!\[)/', $t ) || preg_match( '/^(-{3,}|\*{3,}|_{3,})$/', $t ) );
    }
    public static function md_inline( $text ) {
        $t = esc_html( (string) $text );
        $t = preg_replace_callback( '/\[([^\]]+)\]\(([^)\s]+)\)/', function( $m ) { return '<a href="' . esc_url( $m[2] ) . '">' . $m[1] . '</a>'; }, $t );
        $t = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t );
        $t = preg_replace( '/__([^_]+)__/', '<strong>$1</strong>', $t );
        $t = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $t );
        $t = preg_replace( '/(?<![\w_])_([^_]+)_(?![\w_])/', '<em>$1</em>', $t );
        $t = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $t );
        return $t;
    }
    private static function md_paragraph( $html ) { return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->"; }
    private static function md_heading( $level, $html ) {
        $level = max( 1, min( 6, (int) $level ) );
        $attr = $level === 2 ? '' : ' {"level":' . $level . '}';
        return "<!-- wp:heading{$attr} -->\n<h{$level}>{$html}</h{$level}>\n<!-- /wp:heading -->";
    }
    private static function md_separator() { return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->"; }
    private static function md_image( $url, $alt ) {
        $u = esc_url( $url ); $a = esc_attr( $alt );
        return "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"{$u}\" alt=\"{$a}\"/></figure>\n<!-- /wp:image -->";
    }
    private static function md_code( $code ) {
        $c = esc_html( $code );
        return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>{$c}</code></pre>\n<!-- /wp:code -->";
    }
    private static function md_quote( $lines ) {
        $inner = []; $buf = [];
        foreach ( $lines as $l ) {
            if ( trim( $l ) === '' ) { if ( $buf ) { $inner[] = self::md_paragraph( self::md_inline( implode( '<br>', $buf ) ) ); $buf = []; } }
            else { $buf[] = trim( $l ); }
        }
        if ( $buf ) { $inner[] = self::md_paragraph( self::md_inline( implode( '<br>', $buf ) ) ); }
        $body = implode( "\n", $inner );
        return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">{$body}</blockquote>\n<!-- /wp:quote -->";
    }
    private static function md_list( $items, $ordered ) {
        $tag = $ordered ? 'ol' : 'ul';
        $attr = $ordered ? ' {"ordered":true}' : '';
        $lis = [];
        foreach ( $items as $it ) { $lis[] = "<!-- wp:list-item -->\n<li>{$it}</li>\n<!-- /wp:list-item -->"; }
        $body = implode( "\n", $lis );
        return "<!-- wp:list{$attr} -->\n<{$tag} class=\"wp-block-list\">{$body}</{$tag}>\n<!-- /wp:list -->";
    }

}

class AVCF_Abilities_Gutenberg extends AVCF_Abilities_Base {

    public function register() {
        $this->register_read_page();
        $this->register_read_block();
        $this->register_list_block_types();
        $this->register_apply_operations();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ];
    }
    private function guard( $input, $need_edit = false ) {
        $pid = isset( $input['post_id'] ) ? (int) $input['post_id'] : ( isset( $input['post'] ) ? (int) $input['post'] : 0 );
        if ( $pid <= 0 || ! get_post( $pid ) ) { return [ 'err' => [ 'success' => false, 'message' => 'A valid post_id is required.' ] ]; }
        if ( $need_edit && ! ( current_user_can( 'edit_post', $pid ) || current_user_can( 'edit_posts' ) ) ) {
            return [ 'err' => [ 'success' => false, 'message' => 'No permission to edit this post.' ] ];
        }
        return [ 'post_id' => $pid ];
    }

    /* ----------------------------- read-page --------------------------- */

    private function register_read_page() {
        $self = $this;
        wp_register_ability( 'atarim/gutenberg-read-page', [
            'label' => 'Read Gutenberg Page', 'category' => 'atarim',
            'description' => 'Parse a post/page\'s Gutenberg blocks into a lean nested tree. Each node: path (index-path "0/1/2"), id (the stable avcBlockId, or null if not yet stamped), block (block name), child_count, and children. IMPORTANT: path is each block\'s real position in the post, not its position in this list. The list omits the whitespace between blocks, so paths are usually NOT contiguous — a page of three blocks typically reads 0, 2, 4. Copy paths verbatim into apply-operations; never renumber, count, or infer them. Pass include_attrs:true for each block\'s attrs + inner html. Returns content_hash — pass it to apply-operations as the stale-edit guard. block_based=false / classic=true means the post is classic HTML (no blocks) and edits would be lossy.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'       => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Post/page id (also accepts "post").' ],
                'include_attrs' => [ 'type' => 'boolean', 'default' => false ],
                'max_depth'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 64 ],
            ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'post_id' => [ 'type' => 'integer' ], 'post_type' => [ 'type' => 'string' ], 'block_based' => [ 'type' => 'boolean' ], 'classic' => [ 'type' => 'boolean' ], 'content_hash' => [ 'type' => 'string' ], 'total_blocks' => [ 'type' => 'integer' ], 'tree' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $post = get_post( $g['post_id'] );
                $content = $post->post_content;
                $tree = AVCF_Gutenberg_Helpers::parse_tree( $content );
                $include = ! empty( $input['include_attrs'] );
                $max_depth = isset( $input['max_depth'] ) ? max( 1, (int) $input['max_depth'] ) : 64;
                return [
                    'success' => true,
                    'post_id' => $g['post_id'],
                    'post_type' => $post->post_type,
                    'block_based' => AVCF_Gutenberg_Helpers::is_block_based( $content ),
                    'classic' => AVCF_Gutenberg_Helpers::is_classic( $content ),
                    'content_hash' => AVCF_Gutenberg_Helpers::content_hash( $content ),
                    'total_blocks' => AVCF_Gutenberg_Helpers::tree_count( $tree ),
                    'tree' => AVCF_Gutenberg_Helpers::tree_view( $tree, $include, $max_depth ),
                    'message' => 'OK.',
                ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- read-block --------------------------- */

    private function register_read_block() {
        $self = $this;
        wp_register_ability( 'atarim/gutenberg-read-block', [
            'label' => 'Read Gutenberg Block', 'category' => 'atarim',
            'description' => 'Return one block in full — block name, attrs, its own inner html (for a container this is its wrapper markup, e.g. the <ul class="wp-block-list"> around list items), child summary, markup (the block\'s exact serialized markup, safe to hand straight back to update.markup), its path, its avcBlockId, and its editability class (attr = dynamic/attrs-safe, bridge = curated static/markdown-regenerable, structural = move/delete only or raw markup). Target by path OR block_id (avcBlockId).',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                'path'     => [ 'type' => 'string', 'description' => 'Index-path "0/1/2".' ],
                'block_id' => [ 'type' => 'string', 'description' => 'The stable avcBlockId.' ],
            ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'block' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $post = get_post( $g['post_id'] );
                $blocks = AVCF_Gutenberg_Helpers::parse_raw( $post->post_content );
                $has_path = isset( $input['path'] ) && $input['path'] !== '';
                $has_id = isset( $input['block_id'] ) && $input['block_id'] !== '';
                if ( ! $has_path && ! $has_id ) { return [ 'success' => false, 'message' => 'Provide path or block_id.' ]; }
                if ( $has_id ) {
                    $hit = AVCF_Gutenberg_Helpers::raw_find_by_id( $blocks, (string) $input['block_id'] );
                    if ( $hit === null ) { return [ 'success' => false, 'message' => 'No block with that avcBlockId.' ]; }
                    return [ 'success' => true, 'block' => AVCF_Gutenberg_Helpers::raw_full_node( $hit['block'], $hit['path'] ), 'message' => 'OK.' ];
                }
                $block = AVCF_Gutenberg_Helpers::raw_node_at( $blocks, (string) $input['path'] );
                if ( $block === null ) { return [ 'success' => false, 'message' => sprintf( 'No block at path "%s".', $input['path'] ) ]; }
                return [ 'success' => true, 'block' => AVCF_Gutenberg_Helpers::raw_full_node( $block, (string) $input['path'] ), 'message' => 'OK.' ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ------------------------- list-block-types ------------------------ */

    private function register_list_block_types() {
        wp_register_ability( 'atarim/gutenberg-list-block-types', [
            'label' => 'List Gutenberg Block Types', 'category' => 'atarim',
            'description' => 'List registered block types: { name, title, category, dynamic, editability }. editability tells you how each can be written: attr (dynamic — set attrs freely), bridge (curated static — author via markdown/canonical markup), structural (other static — move/delete/duplicate, or supply raw block markup for content). Optionally filter by search, and/or by editability.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'search'      => [ 'type' => 'string' ],
                'editability' => [ 'type' => 'string', 'enum' => [ 'attr', 'bridge', 'structural' ] ],
            ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'types' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $types = AVCF_Gutenberg_Helpers::list_block_types( isset( $input['search'] ) ? (string) $input['search'] : '' );
                if ( isset( $input['editability'] ) && $input['editability'] !== '' ) {
                    $f = (string) $input['editability'];
                    $types = array_values( array_filter( $types, function( $t ) use ( $f ) { return $t['editability'] === $f; } ) );
                }
                return [ 'success' => true, 'types' => $types, 'total' => count( $types ), 'message' => sprintf( '%d block type(s).', count( $types ) ) ];
            },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* -------------------------- write engine --------------------------- */

    private function write_meta( $destructive = false ) {
        return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $destructive, 'idempotent' => false ] ];
    }

    private function register_apply_operations() {
        $self = $this;
        wp_register_ability( 'atarim/gutenberg-apply-operations', [
            'label' => 'Apply Gutenberg Operations', 'category' => 'atarim',
            'description' => 'Apply a batch of block edits to a post atomically (all-or-nothing). REQUIRES content_hash from read-page as a stale-edit guard — if the post changed since you read it, nothing is written. operations run in order; target blocks by their avcBlockId (preferred — stable across the batch) or index-path. Op types: '
                . 'insert (channels: markdown | markup | block; into parent [id/path/root] at index), '
                . 'update (markdown/markup regen a single block, or merge/replace attrs, or set html), '
                . 'move (target -> parent [prefer id] + index; cannot move into its own subtree), '
                . 'swap (exchange two non-nested blocks a/b), delete (target), duplicate (target -> clone after it with fresh ids). '
                . 'Paths come from read-page and are real block positions, so they are usually not contiguous — pass them through unchanged and never renumber them. Any index-path is valid only against the snapshot you read; once an op inserts or deletes, later paths in the same batch shift, so prefer avcBlockId for anything after the first op. '
                . 'To create a container (group, columns, list, quote, buttons) use the markup channel with its full markup including the wrapper element; the block channel is leaf-only, because a wrapper cannot be inferred from a block name. '
                . 'Set stamp_ids:true to assign avcBlockIds to all blocks afterward. Static-block edits should use the markdown/markup channels (regenerates valid markup); raw attr edits on non-dynamic blocks can desync innerHTML and trip Gutenberg block validation. Classic (non-block) posts are refused unless force:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
                'content_hash' => [ 'type' => 'string', 'description' => 'From read-page. Required stale-edit guard.' ],
                'operations'   => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'minItems' => 1, 'description' => 'Each: { op: insert|update|move|swap|delete|duplicate, ... }. insert: parent?, index?, markdown|markup|block. update: target, markdown|markup|attrs(+replace_attrs)|html. move: target, parent?, index?. swap: a, b. delete: target. duplicate: target.' ],
                'stamp_ids'    => [ 'type' => 'boolean', 'default' => false ],
                'force'        => [ 'type' => 'boolean', 'default' => false ],
            ], 'required' => [ 'post_id', 'content_hash', 'operations' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'applied' => [ 'type' => 'integer' ], 'content_hash' => [ 'type' => 'string' ], 'new_ids' => [ 'type' => 'array' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) { return $self->apply_operations( $input ); },
            'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            'meta' => $self->write_meta_public( true ),
        ] );
    }

    /** public wrapper so the registration closure can read write meta */
    public function write_meta_public( $destructive = false ) { return $this->write_meta( $destructive ); }

    public function apply_operations( $input ) {
        $g = $this->guard( $input, true );
        if ( isset( $g['err'] ) ) { return $g['err']; }
        $post_id = $g['post_id'];
        $post = get_post( $post_id );
        $content = $post->post_content;

        if ( AVCF_Gutenberg_Helpers::is_classic( $content ) && empty( $input['force'] ) ) {
            return [ 'success' => false, 'message' => 'This post is classic HTML (no blocks); editing it as blocks would be lossy. Pass force:true to proceed.' ];
        }
        $expected = isset( $input['content_hash'] ) ? (string) $input['content_hash'] : '';
        if ( $expected === '' ) { return [ 'success' => false, 'message' => 'content_hash is required (get it from read-page).' ]; }
        if ( $expected !== AVCF_Gutenberg_Helpers::content_hash( $content ) ) {
            return [ 'success' => false, 'message' => 'Stale edit: the page changed since you read it. Re-read with read-page and retry.' ];
        }
        $ops = isset( $input['operations'] ) && is_array( $input['operations'] ) ? $input['operations'] : [];
        if ( $ops === [] ) { return [ 'success' => false, 'message' => 'No operations provided.' ]; }

        $blocks = AVCF_Gutenberg_Helpers::parse_raw( $content );
        $applied = 0; $new_ids = []; $warnings = [];
        foreach ( $ops as $idx => $op ) {
            $res = $this->apply_one( $blocks, is_array( $op ) ? $op : [] );
            if ( isset( $res['err'] ) ) {
                return [ 'success' => false, 'message' => sprintf( 'Operation %d (%s) failed: %s — nothing was written.', (int) $idx, ( is_array( $op ) && isset( $op['op'] ) ) ? (string) $op['op'] : '?', $res['err'] ) ];
            }
            $blocks = $res['blocks'];
            if ( isset( $res['new_id'] ) ) { $new_ids[] = $res['new_id']; }
            if ( isset( $res['warn'] ) ) { $warnings[] = array_merge( [ 'op_index' => (int) $idx ], $res['warn'] ); }
            $applied++;
        }
        if ( ! empty( $input['stamp_ids'] ) ) { $blocks = AVCF_Gutenberg_Helpers::raw_stamp_ids( $blocks ); }

        $markup = AVCF_Gutenberg_Helpers::serialize_raw( $blocks );
        $r = wp_update_post( [ 'ID' => $post_id, 'post_content' => $markup ], true );
        if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
        $result = [ 'success' => true, 'applied' => $applied, 'content_hash' => AVCF_Gutenberg_Helpers::content_hash( $markup ), 'new_ids' => $new_ids ];
        $message = sprintf( '%d operation(s) applied.', $applied );
        if ( ! empty( $warnings ) ) {
            $result['warnings'] = $warnings;
            $bits = [];
            foreach ( $warnings as $w ) { $bits[] = sprintf( 'op %d (%s): %s', $w['op_index'], $w['block'], implode( ', ', $w['unknown_attrs'] ) ); }
            $message .= ' WARNING: some attributes are not defined by their block type and are likely ignored — ' . implode( '; ', $bits ) . '. Check names with gutenberg-list-block-types / the block\'s registered attributes.';
        }
        $result['message'] = $message;
        return $result;
    }

    private function apply_one( $blocks, $op ) {
        $type = isset( $op['op'] ) ? (string) $op['op'] : '';
        switch ( $type ) {
            case 'insert':    return $this->op_insert( $blocks, $op );
            case 'update':    return $this->op_update( $blocks, $op );
            case 'move':      return $this->op_move( $blocks, $op );
            case 'swap':      return $this->op_swap( $blocks, $op );
            case 'delete':    return $this->op_delete( $blocks, $op );
            case 'duplicate': return $this->op_duplicate( $blocks, $op );
            default:          return [ 'err' => 'unknown op "' . $type . '"' ];
        }
    }

    /** '' / 'root' -> '' (root). id resolved first, then path. null = not found. */
    private function resolve_to_path( $blocks, $ref ) {
        $ref = (string) $ref;
        if ( $ref === '' || $ref === 'root' ) { return ''; }
        $hit = AVCF_Gutenberg_Helpers::raw_find_by_id( $blocks, $ref );
        if ( $hit !== null ) { return $hit['path']; }
        if ( AVCF_Gutenberg_Helpers::raw_node_at( $blocks, $ref ) !== null ) { return $ref; }
        return null;
    }

    private function blocks_from_op( $op ) {
        if ( isset( $op['markdown'] ) && $op['markdown'] !== '' ) { return [ 'blocks' => AVCF_Gutenberg_Helpers::markdown_to_blocks( (string) $op['markdown'] ) ]; }
        if ( isset( $op['markup'] ) && $op['markup'] !== '' ) { return [ 'blocks' => AVCF_Gutenberg_Helpers::markup_to_blocks( (string) $op['markup'] ) ]; }
        if ( isset( $op['block'] ) && is_array( $op['block'] ) ) {
            if ( isset( $op['block']['children'] ) && $op['block']['children'] !== [] ) {
                return [ 'err' => 'block.children is not supported — a container\'s wrapper markup cannot be inferred from its name. Use the markup channel with the full block markup (e.g. <!-- wp:group --><div class="wp-block-group">…</div><!-- /wp:group -->), or markdown.' ];
            }
            $raw = AVCF_Gutenberg_Helpers::block_to_raw( $op['block'] );
            if ( $raw === null ) { return [ 'err' => 'block.block (name) is required' ]; }
            return [ 'blocks' => [ $raw ] ];
        }
        return [ 'err' => 'insert needs markdown, markup, or block' ];
    }

    private function op_insert( $blocks, $op ) {
        $src = $this->blocks_from_op( $op );
        if ( isset( $src['err'] ) ) { return $src; }
        if ( $src['blocks'] === [] ) { return [ 'err' => 'nothing to insert (empty content)' ]; }
        $parent_path = $this->resolve_to_path( $blocks, isset( $op['parent'] ) ? (string) $op['parent'] : '' );
        if ( $parent_path === null ) { return [ 'err' => 'parent not found: ' . ( isset( $op['parent'] ) ? $op['parent'] : '' ) ]; }
        $index = isset( $op['index'] ) ? (int) $op['index'] : null;
        $new = AVCF_Gutenberg_Helpers::raw_insert_at( $blocks, $parent_path, $index, $src['blocks'] );
        if ( $new === null ) { return [ 'err' => 'insert failed — the parent has no children yet and its wrapper markup could not be opened safely. Replace the whole container with update.markup instead.' ]; }
        return [ 'blocks' => $new ];
    }

    private function op_update( $blocks, $op ) {
        $path = $this->resolve_to_path( $blocks, isset( $op['target'] ) ? (string) $op['target'] : '' );
        if ( $path === null || $path === '' ) { return [ 'err' => 'target not found' ]; }
        $block = AVCF_Gutenberg_Helpers::raw_node_at( $blocks, $path );
        if ( $block === null ) { return [ 'err' => 'target not found' ]; }
        $cur_attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
        $cur_id    = AVCF_Gutenberg_Helpers::extract_id( $cur_attrs );

        if ( isset( $op['markup'] ) && $op['markup'] !== '' ) {
            $parsed = AVCF_Gutenberg_Helpers::markup_to_blocks( (string) $op['markup'] );
            if ( count( $parsed ) !== 1 ) { return [ 'err' => 'update markup must produce exactly one block' ]; }
            $new = AVCF_Gutenberg_Helpers::raw_replace_at( $blocks, $path, $parsed[0] );
            return $new === null ? [ 'err' => 'update failed' ] : [ 'blocks' => $new ];
        }
        if ( isset( $op['markdown'] ) && $op['markdown'] !== '' ) {
            $parsed = AVCF_Gutenberg_Helpers::markdown_to_blocks( (string) $op['markdown'] );
            if ( count( $parsed ) !== 1 ) { return [ 'err' => 'update markdown must produce exactly one block' ]; }
            $repl = $parsed[0];
            if ( $cur_id !== '' ) {
                $repl['attrs'] = AVCF_Gutenberg_Helpers::set_id( isset( $repl['attrs'] ) && is_array( $repl['attrs'] ) ? $repl['attrs'] : [], $cur_id );
            }
            $new = AVCF_Gutenberg_Helpers::raw_replace_at( $blocks, $path, $repl );
            return $new === null ? [ 'err' => 'update failed' ] : [ 'blocks' => $new ];
        }
        if ( isset( $op['attrs'] ) && is_array( $op['attrs'] ) ) {
            $block['attrs'] = ! empty( $op['replace_attrs'] ) ? $op['attrs'] : array_merge( $cur_attrs, $op['attrs'] );
            $new = AVCF_Gutenberg_Helpers::raw_replace_at( $blocks, $path, $block );
            if ( $new === null ) { return [ 'err' => 'update failed' ]; }
            $res   = [ 'blocks' => $new ];
            // Tier-2 validation (non-blocking): flag attrs the block type does not
            // define so a silently-ignored attribute is visible in the receipt.
            $bname = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
            $valid = AVCF_Gutenberg_Helpers::block_attr_keys( $bname );
            if ( is_array( $valid ) ) {
                $unknown = array_values( array_filter(
                    array_keys( $op['attrs'] ),
                    function( $k ) use ( $valid ) { return ! in_array( $k, $valid, true ); }
                ) );
                if ( ! empty( $unknown ) ) {
                    $res['warn'] = [ 'block' => $bname, 'unknown_attrs' => $unknown ];
                }
            }
            return $res;
        }
        if ( isset( $op['html'] ) ) {
            $kids = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? count( $block['innerBlocks'] ) : 0;
            if ( $kids > 0 ) {
                return [ 'err' => sprintf( 'target has %d inner block(s); setting html would drop them and their wrapper markup. Use markup to replace the whole block, or edit the children individually.', $kids ) ];
            }
            $html = (string) $op['html'];
            $block['innerHTML']    = $html;
            $block['innerContent'] = $html !== '' ? [ $html ] : [];
            $new = AVCF_Gutenberg_Helpers::raw_replace_at( $blocks, $path, $block );
            return $new === null ? [ 'err' => 'update failed' ] : [ 'blocks' => $new ];
        }
        return [ 'err' => 'update needs markdown, markup, attrs, or html' ];
    }

    private function op_move( $blocks, $op ) {
        $from = $this->resolve_to_path( $blocks, isset( $op['target'] ) ? (string) $op['target'] : '' );
        if ( $from === null || $from === '' ) { return [ 'err' => 'target not found' ]; }
        $block = AVCF_Gutenberg_Helpers::raw_node_at( $blocks, $from );
        if ( $block === null ) { return [ 'err' => 'target not found' ]; }
        $parent_ref = isset( $op['parent'] ) ? (string) $op['parent'] : '';
        $to = $this->resolve_to_path( $blocks, $parent_ref );
        if ( $to === null ) { return [ 'err' => 'parent not found: ' . $parent_ref ]; }
        if ( $to === $from || strpos( $to . '/', $from . '/' ) === 0 ) { return [ 'err' => 'cannot move a block into itself or its descendant' ]; }
        $removed = AVCF_Gutenberg_Helpers::raw_remove_at( $blocks, $from );
        if ( $removed === null ) { return [ 'err' => 'move: removal failed' ]; }
        $to2 = ( $parent_ref === '' || $parent_ref === 'root' ) ? '' : $this->resolve_to_path( $removed, $parent_ref );
        if ( $to2 === null ) { return [ 'err' => 'move: parent shifted after removal — target it by avcBlockId' ]; }
        $index = isset( $op['index'] ) ? (int) $op['index'] : null;
        $new = AVCF_Gutenberg_Helpers::raw_insert_at( $removed, $to2, $index, [ $block ] );
        return $new === null ? [ 'err' => 'move: insert failed' ] : [ 'blocks' => $new ];
    }

    private function op_swap( $blocks, $op ) {
        $pa = $this->resolve_to_path( $blocks, isset( $op['a'] ) ? (string) $op['a'] : '' );
        $pb = $this->resolve_to_path( $blocks, isset( $op['b'] ) ? (string) $op['b'] : '' );
        if ( $pa === null || $pa === '' || $pb === null || $pb === '' ) { return [ 'err' => 'swap needs valid a and b' ]; }
        if ( $pa === $pb ) { return [ 'err' => 'swap a and b are the same block' ]; }
        if ( strpos( $pa . '/', $pb . '/' ) === 0 || strpos( $pb . '/', $pa . '/' ) === 0 ) { return [ 'err' => 'cannot swap nested blocks' ]; }
        $ba = AVCF_Gutenberg_Helpers::raw_node_at( $blocks, $pa );
        $bb = AVCF_Gutenberg_Helpers::raw_node_at( $blocks, $pb );
        if ( $ba === null || $bb === null ) { return [ 'err' => 'swap target not found' ]; }
        $t = AVCF_Gutenberg_Helpers::raw_replace_at( $blocks, $pa, $bb );
        if ( $t === null ) { return [ 'err' => 'swap failed' ]; }
        $t = AVCF_Gutenberg_Helpers::raw_replace_at( $t, $pb, $ba );
        return $t === null ? [ 'err' => 'swap failed' ] : [ 'blocks' => $t ];
    }

    private function op_delete( $blocks, $op ) {
        $path = $this->resolve_to_path( $blocks, isset( $op['target'] ) ? (string) $op['target'] : '' );
        if ( $path === null || $path === '' ) { return [ 'err' => 'target not found' ]; }
        $new = AVCF_Gutenberg_Helpers::raw_remove_at( $blocks, $path );
        return $new === null ? [ 'err' => 'delete failed' ] : [ 'blocks' => $new ];
    }

    private function op_duplicate( $blocks, $op ) {
        $path = $this->resolve_to_path( $blocks, isset( $op['target'] ) ? (string) $op['target'] : '' );
        if ( $path === null || $path === '' ) { return [ 'err' => 'target not found' ]; }
        $block = AVCF_Gutenberg_Helpers::raw_node_at( $blocks, $path );
        if ( $block === null ) { return [ 'err' => 'target not found' ]; }
        $clone = AVCF_Gutenberg_Helpers::raw_clone( $block, true );
        $new_id = '';
        if ( ! empty( $clone['blockName'] ) ) {
            $cattrs = isset( $clone['attrs'] ) && is_array( $clone['attrs'] ) ? $clone['attrs'] : [];
            $new_id = AVCF_Gutenberg_Helpers::extract_id( $cattrs );
            if ( $new_id === '' ) {
                $new_id = AVCF_Gutenberg_Helpers::uuid();
                $clone['attrs'] = AVCF_Gutenberg_Helpers::set_id( $cattrs, $new_id );
            }
        }
        list( $parent, $index ) = AVCF_Gutenberg_Helpers::split_address( $path );
        $pos = $index === null ? null : ( (int) $index + 1 );
        $new = AVCF_Gutenberg_Helpers::raw_insert_at( $blocks, $parent, $pos, [ $clone ] );
        if ( $new === null ) { return [ 'err' => 'duplicate failed' ]; }
        return $new_id !== '' ? [ 'blocks' => $new, 'new_id' => $new_id ] : [ 'blocks' => $new ];
    }

}
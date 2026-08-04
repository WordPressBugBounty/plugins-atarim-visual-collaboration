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

    /* ---------------------------- round-trip --------------------------- */

    public static function parse_tree( $content ) {
        return self::blocks_to_tree( parse_blocks( (string) $content ) );
    }

    private static function blocks_to_tree( $blocks ) {
        $tree = [];
        foreach ( (array) $blocks as $block ) {
            $name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
            $inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
            $inner_html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
            if ( $name === '' && trim( $inner_html ) === '' ) { continue; } // whitespace between blocks
            $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
            $node = [ 'block' => $name ];
            $id = self::extract_id( $attrs );
            if ( $id !== '' ) { $node['id'] = $id; }
            if ( $attrs !== [] ) { $node['attrs'] = $attrs; }
            if ( $inner_blocks ) { $node['children'] = self::blocks_to_tree( $inner_blocks ); }
            elseif ( trim( $inner_html ) !== '' ) { $node['html'] = $inner_html; }
            $tree[] = $node;
        }
        return $tree;
    }

    public static function serialize_tree( $tree ) {
        return serialize_blocks( self::tree_to_blocks( $tree ) );
    }

    private static function tree_to_blocks( $tree ) {
        $blocks = [];
        foreach ( (array) $tree as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $name = isset( $node['block'] ) ? (string) $node['block'] : '';
            $attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [];
            $children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
            $html = isset( $node['html'] ) ? (string) $node['html'] : '';
            if ( $name === '' ) {
                $blocks[] = [ 'blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => [ $html ] ];
                continue;
            }
            if ( $children ) {
                $inner = self::tree_to_blocks( $children );
                $blocks[] = [ 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $inner, 'innerHTML' => '', 'innerContent' => $inner ? array_fill( 0, count( $inner ), null ) : [] ];
            } else {
                $blocks[] = [ 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => $html !== '' ? [ $html ] : [] ];
            }
        }
        return $blocks;
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

    public static function node_at( $tree, $address ) {
        $parts = self::address_parts( $address );
        if ( $parts === null ) { return null; }
        $nodes = $tree; $found = null;
        foreach ( $parts as $i ) {
            if ( ! array_key_exists( $i, $nodes ) ) { return null; }
            $found = $nodes[ $i ];
            $nodes = isset( $found['children'] ) && is_array( $found['children'] ) ? $found['children'] : [];
        }
        return $found;
    }

    /** Find a node by its avcBlockId. Returns ['node'=>, 'path'=>] or null. */
    public static function find_by_id( $tree, $id, $prefix = '' ) {
        foreach ( $tree as $i => $node ) {
            $path = $prefix === '' ? (string) $i : $prefix . '/' . $i;
            if ( isset( $node['id'] ) && (string) $node['id'] === (string) $id ) { return [ 'node' => $node, 'path' => $path ]; }
            if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
                $hit = self::find_by_id( $node['children'], $id, $path );
                if ( $hit !== null ) { return $hit; }
            }
        }
        return null;
    }

    /* ----------------------------- views ------------------------------- */

    /** Lean nested read model. include_attrs adds attrs/html; depth caps recursion. */
    public static function tree_view( $tree, $include_attrs = false, $max_depth = 64, $prefix = '', $depth = 1 ) {
        $out = [];
        foreach ( $tree as $i => $node ) {
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

    public static function full_node( $node, $path ) {
        return [
            'path' => $path,
            'id' => isset( $node['id'] ) ? (string) $node['id'] : null,
            'block' => isset( $node['block'] ) ? (string) $node['block'] : '',
            'attrs' => isset( $node['attrs'] ) ? $node['attrs'] : [],
            'html' => isset( $node['html'] ) ? (string) $node['html'] : '',
            'children' => isset( $node['children'] ) && is_array( $node['children'] ) ? self::tree_view( $node['children'], false, 64, $path, 1 ) : [],
            'editability' => self::editability( isset( $node['block'] ) ? (string) $node['block'] : '' ),
        ];
    }

    /* --------------------------- mutation ------------------------------ */

    public static function split_address( $address ) {
        $parts = self::address_parts( $address );
        if ( $parts === null || $parts === [] ) { return [ '', null ]; }
        $index = array_pop( $parts );
        return [ implode( '/', $parts ), $index ];
    }

    public static function update_children( $tree, $parent_address, $op ) {
        $parts = self::address_parts( $parent_address );
        if ( $parts === null ) { return null; }
        if ( $parts === [] ) { return call_user_func( $op, $tree ); }
        return self::descend( $tree, $parts, $op );
    }
    private static function descend( $nodes, $parts, $op ) {
        $i = array_shift( $parts );
        if ( ! array_key_exists( $i, $nodes ) ) { return null; }
        $children = isset( $nodes[ $i ]['children'] ) && is_array( $nodes[ $i ]['children'] ) ? $nodes[ $i ]['children'] : [];
        $updated = empty( $parts ) ? call_user_func( $op, $children ) : self::descend( $children, $parts, $op );
        if ( $updated === null ) { return null; }
        $nodes[ $i ]['children'] = $updated;
        return $nodes;
    }

    public static function replace_at( $tree, $address, $node ) {
        list( $parent, $index ) = self::split_address( $address );
        if ( $index === null ) { return null; }
        return self::update_children( $tree, $parent, function( $children ) use ( $index, $node ) {
            if ( ! array_key_exists( $index, $children ) ) { return null; }
            $children[ $index ] = $node;
            return $children;
        } );
    }
    public static function remove_at( $tree, $address ) {
        list( $parent, $index ) = self::split_address( $address );
        if ( $index === null ) { return null; }
        return self::update_children( $tree, $parent, function( $children ) use ( $index ) {
            if ( ! array_key_exists( $index, $children ) ) { return null; }
            array_splice( $children, $index, 1 );
            return $children;
        } );
    }
    /** $nodes is a list of nodes. position null = append. */
    public static function insert_at( $tree, $parent_address, $position, $nodes ) {
        return self::update_children( $tree, $parent_address, function( $children ) use ( $position, $nodes ) {
            $pos = $position === null ? count( $children ) : max( 0, min( (int) $position, count( $children ) ) );
            array_splice( $children, $pos, 0, $nodes );
            return $children;
        } );
    }

    /** Stamp avcBlockId on every named block lacking one (recursive). */
    public static function stamp_ids( $tree ) {
        $out = [];
        foreach ( $tree as $node ) {
            if ( isset( $node['block'] ) && $node['block'] !== '' ) {
                $attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [];
                if ( self::extract_id( $attrs ) === '' ) {
                    $id = self::uuid();
                    $node['attrs'] = self::set_id( $attrs, $id );
                    $node['id'] = $id;
                }
            }
            if ( isset( $node['children'] ) && is_array( $node['children'] ) ) { $node['children'] = self::stamp_ids( $node['children'] ); }
            $out[] = $node;
        }
        return $out;
    }

    /** Deep-clone a node, reassigning fresh avcBlockIds where present. */
    public static function clone_node( $node, $reassign_ids = true ) {
        if ( $reassign_ids && isset( $node['block'] ) && $node['block'] !== '' ) {
            $attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [];
            if ( self::extract_id( $attrs ) !== '' ) {
                $id = self::uuid();
                $node['attrs'] = self::set_id( $attrs, $id );
                $node['id'] = $id;
            }
        }
        if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
            $kids = [];
            foreach ( $node['children'] as $c ) { $kids[] = self::clone_node( $c, $reassign_ids ); }
            $node['children'] = $kids;
        }
        return $node;
    }

    /* ----------------------- markdown / markup bridge ------------------ */

    public static function markup_to_nodes( $markup ) { return self::parse_tree( (string) $markup ); }
    public static function markdown_to_nodes( $md ) { return self::parse_tree( self::markdown_to_markup( $md ) ); }

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
            'description' => 'Parse a post/page\'s Gutenberg blocks into a lean nested tree. Each node: path (index-path "0/1/2"), id (the stable avcBlockId, or null if not yet stamped), block (block name), child_count, and children. Pass include_attrs:true for each block\'s attrs + inner html. Returns content_hash — pass it to apply-operations as the stale-edit guard. block_based=false / classic=true means the post is classic HTML (no blocks) and edits would be lossy.',
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
            'description' => 'Return one block in full — block name, attrs, inner html, child summary, its path, its avcBlockId, and its editability class (attr = dynamic/attrs-safe, bridge = curated static/markdown-regenerable, structural = move/delete only or raw markup). Target by path OR block_id (avcBlockId).',
            'input_schema' => [ 'type' => 'object', 'properties' => [
                'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                'path'     => [ 'type' => 'string', 'description' => 'Index-path "0/1/2".' ],
                'block_id' => [ 'type' => 'string', 'description' => 'The stable avcBlockId.' ],
            ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'block' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $g = $self->guard( $input ); if ( isset( $g['err'] ) ) { return $g['err']; }
                $post = get_post( $g['post_id'] );
                $tree = AVCF_Gutenberg_Helpers::parse_tree( $post->post_content );
                $has_path = isset( $input['path'] ) && $input['path'] !== '';
                $has_id = isset( $input['block_id'] ) && $input['block_id'] !== '';
                if ( ! $has_path && ! $has_id ) { return [ 'success' => false, 'message' => 'Provide path or block_id.' ]; }
                if ( $has_id ) {
                    $hit = AVCF_Gutenberg_Helpers::find_by_id( $tree, (string) $input['block_id'] );
                    if ( $hit === null ) { return [ 'success' => false, 'message' => 'No block with that avcBlockId.' ]; }
                    return [ 'success' => true, 'block' => AVCF_Gutenberg_Helpers::full_node( $hit['node'], $hit['path'] ), 'message' => 'OK.' ];
                }
                $node = AVCF_Gutenberg_Helpers::node_at( $tree, (string) $input['path'] );
                if ( $node === null ) { return [ 'success' => false, 'message' => sprintf( 'No block at path "%s".', $input['path'] ) ]; }
                return [ 'success' => true, 'block' => AVCF_Gutenberg_Helpers::full_node( $node, (string) $input['path'] ), 'message' => 'OK.' ];
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

        $tree = AVCF_Gutenberg_Helpers::parse_tree( $content );
        $applied = 0; $new_ids = [];
        foreach ( $ops as $idx => $op ) {
            $res = $this->apply_one( $tree, is_array( $op ) ? $op : [] );
            if ( isset( $res['err'] ) ) {
                return [ 'success' => false, 'message' => sprintf( 'Operation %d (%s) failed: %s — nothing was written.', (int) $idx, ( is_array( $op ) && isset( $op['op'] ) ) ? (string) $op['op'] : '?', $res['err'] ) ];
            }
            $tree = $res['tree'];
            if ( isset( $res['new_id'] ) ) { $new_ids[] = $res['new_id']; }
            $applied++;
        }
        if ( ! empty( $input['stamp_ids'] ) ) { $tree = AVCF_Gutenberg_Helpers::stamp_ids( $tree ); }

        $markup = AVCF_Gutenberg_Helpers::serialize_tree( $tree );
        $r = wp_update_post( [ 'ID' => $post_id, 'post_content' => $markup ], true );
        if ( is_wp_error( $r ) ) { return [ 'success' => false, 'message' => $r->get_error_message() ]; }
        return [ 'success' => true, 'applied' => $applied, 'content_hash' => AVCF_Gutenberg_Helpers::content_hash( $markup ), 'new_ids' => $new_ids, 'message' => sprintf( '%d operation(s) applied.', $applied ) ];
    }

    private function apply_one( $tree, $op ) {
        $type = isset( $op['op'] ) ? (string) $op['op'] : '';
        switch ( $type ) {
            case 'insert':    return $this->op_insert( $tree, $op );
            case 'update':    return $this->op_update( $tree, $op );
            case 'move':      return $this->op_move( $tree, $op );
            case 'swap':      return $this->op_swap( $tree, $op );
            case 'delete':    return $this->op_delete( $tree, $op );
            case 'duplicate': return $this->op_duplicate( $tree, $op );
            default:          return [ 'err' => 'unknown op "' . $type . '"' ];
        }
    }

    /** '' / 'root' -> '' (root). id resolved first, then path. null = not found. */
    private function resolve_to_path( $tree, $ref ) {
        $ref = (string) $ref;
        if ( $ref === '' || $ref === 'root' ) { return ''; }
        $hit = AVCF_Gutenberg_Helpers::find_by_id( $tree, $ref );
        if ( $hit !== null ) { return $hit['path']; }
        if ( AVCF_Gutenberg_Helpers::node_at( $tree, $ref ) !== null ) { return $ref; }
        return null;
    }

    private function nodes_from_op( $op ) {
        if ( isset( $op['markdown'] ) && $op['markdown'] !== '' ) { return [ 'nodes' => AVCF_Gutenberg_Helpers::markdown_to_nodes( (string) $op['markdown'] ) ]; }
        if ( isset( $op['markup'] ) && $op['markup'] !== '' ) { return [ 'nodes' => AVCF_Gutenberg_Helpers::markup_to_nodes( (string) $op['markup'] ) ]; }
        if ( isset( $op['block'] ) && is_array( $op['block'] ) ) {
            $b = $op['block'];
            $name = isset( $b['block'] ) ? (string) $b['block'] : '';
            if ( $name === '' ) { return [ 'err' => 'block.block (name) is required' ]; }
            $node = [ 'block' => $name ];
            if ( isset( $b['attrs'] ) && is_array( $b['attrs'] ) ) { $node['attrs'] = $b['attrs']; }
            if ( isset( $b['html'] ) ) { $node['html'] = (string) $b['html']; }
            if ( isset( $b['children'] ) && is_array( $b['children'] ) ) { $node['children'] = $b['children']; }
            return [ 'nodes' => [ $node ] ];
        }
        return [ 'err' => 'insert needs markdown, markup, or block' ];
    }

    private function op_insert( $tree, $op ) {
        $nodes = $this->nodes_from_op( $op );
        if ( isset( $nodes['err'] ) ) { return $nodes; }
        if ( $nodes['nodes'] === [] ) { return [ 'err' => 'nothing to insert (empty content)' ]; }
        $parent_path = $this->resolve_to_path( $tree, isset( $op['parent'] ) ? (string) $op['parent'] : '' );
        if ( $parent_path === null ) { return [ 'err' => 'parent not found: ' . ( isset( $op['parent'] ) ? $op['parent'] : '' ) ]; }
        $index = isset( $op['index'] ) ? (int) $op['index'] : null;
        $new = AVCF_Gutenberg_Helpers::insert_at( $tree, $parent_path, $index, $nodes['nodes'] );
        return $new === null ? [ 'err' => 'insert failed' ] : [ 'tree' => $new ];
    }

    private function op_update( $tree, $op ) {
        $path = $this->resolve_to_path( $tree, isset( $op['target'] ) ? (string) $op['target'] : '' );
        if ( $path === null || $path === '' ) { return [ 'err' => 'target not found' ]; }
        $node = AVCF_Gutenberg_Helpers::node_at( $tree, $path );
        if ( $node === null ) { return [ 'err' => 'target not found' ]; }

        if ( isset( $op['markup'] ) && $op['markup'] !== '' ) {
            $nodes = AVCF_Gutenberg_Helpers::markup_to_nodes( (string) $op['markup'] );
            if ( count( $nodes ) !== 1 ) { return [ 'err' => 'update markup must produce exactly one block' ]; }
            $new = AVCF_Gutenberg_Helpers::replace_at( $tree, $path, $nodes[0] );
            return $new === null ? [ 'err' => 'update failed' ] : [ 'tree' => $new ];
        }
        if ( isset( $op['markdown'] ) && $op['markdown'] !== '' ) {
            $nodes = AVCF_Gutenberg_Helpers::markdown_to_nodes( (string) $op['markdown'] );
            if ( count( $nodes ) !== 1 ) { return [ 'err' => 'update markdown must produce exactly one block' ]; }
            $repl = $nodes[0];
            if ( isset( $node['id'] ) ) {
                $repl['attrs'] = AVCF_Gutenberg_Helpers::set_id( isset( $repl['attrs'] ) ? $repl['attrs'] : [], $node['id'] );
                $repl['id'] = $node['id'];
            }
            $new = AVCF_Gutenberg_Helpers::replace_at( $tree, $path, $repl );
            return $new === null ? [ 'err' => 'update failed' ] : [ 'tree' => $new ];
        }
        if ( isset( $op['attrs'] ) && is_array( $op['attrs'] ) ) {
            $cur = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : [];
            $node['attrs'] = ! empty( $op['replace_attrs'] ) ? $op['attrs'] : array_merge( $cur, $op['attrs'] );
            $new = AVCF_Gutenberg_Helpers::replace_at( $tree, $path, $node );
            return $new === null ? [ 'err' => 'update failed' ] : [ 'tree' => $new ];
        }
        if ( isset( $op['html'] ) ) {
            $node['html'] = (string) $op['html'];
            unset( $node['children'] );
            $new = AVCF_Gutenberg_Helpers::replace_at( $tree, $path, $node );
            return $new === null ? [ 'err' => 'update failed' ] : [ 'tree' => $new ];
        }
        return [ 'err' => 'update needs markdown, markup, attrs, or html' ];
    }

    private function op_move( $tree, $op ) {
        $from = $this->resolve_to_path( $tree, isset( $op['target'] ) ? (string) $op['target'] : '' );
        if ( $from === null || $from === '' ) { return [ 'err' => 'target not found' ]; }
        $node = AVCF_Gutenberg_Helpers::node_at( $tree, $from );
        if ( $node === null ) { return [ 'err' => 'target not found' ]; }
        $parent_ref = isset( $op['parent'] ) ? (string) $op['parent'] : '';
        $to = $this->resolve_to_path( $tree, $parent_ref );
        if ( $to === null ) { return [ 'err' => 'parent not found: ' . $parent_ref ]; }
        if ( $to === $from || strpos( $to . '/', $from . '/' ) === 0 ) { return [ 'err' => 'cannot move a block into itself or its descendant' ]; }
        $removed = AVCF_Gutenberg_Helpers::remove_at( $tree, $from );
        if ( $removed === null ) { return [ 'err' => 'move: removal failed' ]; }
        $to2 = ( $parent_ref === '' || $parent_ref === 'root' ) ? '' : $this->resolve_to_path( $removed, $parent_ref );
        if ( $to2 === null ) { return [ 'err' => 'move: parent shifted after removal — target it by avcBlockId' ]; }
        $index = isset( $op['index'] ) ? (int) $op['index'] : null;
        $new = AVCF_Gutenberg_Helpers::insert_at( $removed, $to2, $index, [ $node ] );
        return $new === null ? [ 'err' => 'move: insert failed' ] : [ 'tree' => $new ];
    }

    private function op_swap( $tree, $op ) {
        $pa = $this->resolve_to_path( $tree, isset( $op['a'] ) ? (string) $op['a'] : '' );
        $pb = $this->resolve_to_path( $tree, isset( $op['b'] ) ? (string) $op['b'] : '' );
        if ( $pa === null || $pa === '' || $pb === null || $pb === '' ) { return [ 'err' => 'swap needs valid a and b' ]; }
        if ( $pa === $pb ) { return [ 'err' => 'swap a and b are the same block' ]; }
        if ( strpos( $pa . '/', $pb . '/' ) === 0 || strpos( $pb . '/', $pa . '/' ) === 0 ) { return [ 'err' => 'cannot swap nested blocks' ]; }
        $na = AVCF_Gutenberg_Helpers::node_at( $tree, $pa );
        $nb = AVCF_Gutenberg_Helpers::node_at( $tree, $pb );
        if ( $na === null || $nb === null ) { return [ 'err' => 'swap target not found' ]; }
        $t = AVCF_Gutenberg_Helpers::replace_at( $tree, $pa, $nb );
        if ( $t === null ) { return [ 'err' => 'swap failed' ]; }
        $t = AVCF_Gutenberg_Helpers::replace_at( $t, $pb, $na );
        return $t === null ? [ 'err' => 'swap failed' ] : [ 'tree' => $t ];
    }

    private function op_delete( $tree, $op ) {
        $path = $this->resolve_to_path( $tree, isset( $op['target'] ) ? (string) $op['target'] : '' );
        if ( $path === null || $path === '' ) { return [ 'err' => 'target not found' ]; }
        $new = AVCF_Gutenberg_Helpers::remove_at( $tree, $path );
        return $new === null ? [ 'err' => 'delete failed' ] : [ 'tree' => $new ];
    }

    private function op_duplicate( $tree, $op ) {
        $path = $this->resolve_to_path( $tree, isset( $op['target'] ) ? (string) $op['target'] : '' );
        if ( $path === null || $path === '' ) { return [ 'err' => 'target not found' ]; }
        $node = AVCF_Gutenberg_Helpers::node_at( $tree, $path );
        if ( $node === null ) { return [ 'err' => 'target not found' ]; }
        $clone = AVCF_Gutenberg_Helpers::clone_node( $node, true );
        list( $parent, $index ) = AVCF_Gutenberg_Helpers::split_address( $path );
        $pos = $index === null ? null : ( (int) $index + 1 );
        $new = AVCF_Gutenberg_Helpers::insert_at( $tree, $parent, $pos, [ $clone ] );
        if ( $new === null ) { return [ 'err' => 'duplicate failed' ]; }
        return isset( $clone['id'] ) ? [ 'tree' => $new, 'new_id' => $clone['id'] ] : [ 'tree' => $new ];
    }

}

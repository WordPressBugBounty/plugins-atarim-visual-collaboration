<?php
/**
 * Plugin-update notice rendering for the Plugins screen.
 *
 * Parses the readme "Upgrade Notice" body into one or more title/body blocks
 * and renders them as a zebra-striped list under the plugin row.
 *
 * Authoring convention (readme "Upgrade Notice" section):
 *   - A line fully wrapped in ** ** is a block title and starts a new notice.
 *   - Following lines are that block body, until the next title line.
 *   - Inline bold in a body uses <b> or <strong> tags only.
 *   - Links use markdown [label](https://...) or bare https:// URLs.
 *
 * @package Atarim
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_Upgrade_Notice {

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_filter( 'site_transient_update_plugins', array( $this, 'hide_notice_on_updates_screen' ) );
        add_action( 'in_plugin_update_message-' . AVCF_PLUGIN_BASE, array( $this, 'render_update_message' ), 10, 2 );
    }

    /**
     * Hide the raw upgrade_notice on the Dashboard -> Updates screen only.
     *
     * @param object $transient Update plugins transient.
     * @return object
     */
    public function hide_notice_on_updates_screen( $transient ) {

        if ( ! is_admin() ) {
            return $transient;
        }

        global $pagenow;

        if ( ! isset( $transient->response[ AVCF_PLUGIN_BASE ] ) ) {
            return $transient;
        }

        if ( $pagenow === 'update-core.php'
            && isset( $transient->response[ AVCF_PLUGIN_BASE ]->upgrade_notice ) ) {
            unset( $transient->response[ AVCF_PLUGIN_BASE ]->upgrade_notice );
        }

        return $transient;
    }

    /**
     * Render the upgrade-notice block list on the Plugins screen.
     *
     * @param array  $plugin_data Plugin data from the plugins list table.
     * @param object $response    Update offer object (provides ->upgrade_notice).
     * @return void
     */
    public function render_update_message( $plugin_data, $response ) {

        if ( empty( $response->upgrade_notice ) ) {
            return;
        }

        // Decode entities, convert <br> to newlines, keep only inline bold tags.
        $raw = html_entity_decode( $response->upgrade_notice, ENT_QUOTES, get_bloginfo( 'charset' ) );
        $raw = preg_replace( '#<br\s*/?>#i', "\n", $raw );
        $raw = strip_tags( $raw, '<b><strong>' );
        $raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );
        $raw = trim( $raw );

        if ( $raw === '' ) {
            return;
        }

        $blocks = $this->parse_blocks( $raw );

        if ( empty( $blocks ) ) {
            return;
        }

        $wrap_style  = 'display:block;margin-top:8px;border:1px solid #f0e0b8;';
        $wrap_style .= 'border-left:4px solid #d63638;border-radius:6px;overflow:hidden;';
        $wrap_style .= 'line-height:1.5;box-sizing:border-box;';

        echo '<span class="atarim-upgrade-notices" style="' . esc_attr( $wrap_style ) . '">';

        $allowed_html = array(
            'a'      => array(
                'href'   => array(),
                'target' => array(),
                'rel'    => array(),
            ),
            'b'      => array(),
            'strong' => array(),
        );

        $count = count( $blocks );

        foreach ( $blocks as $i => $block ) {

            // Row 1 = odd (no fill), row 2 = even (light fill), alternating.
            $bg        = ( $i % 2 === 1 ) ? '#fff8e5' : '#ffffff';
            $separator = ( $i < $count - 1 ) ? 'border-bottom:1px solid #f3ead0;' : '';
            $row_style = 'display:block;padding:10px 14px;background:' . $bg . ';' . $separator;

            echo '<span style="' . esc_attr( $row_style ) . '">';

            $title = trim( preg_replace( "/\s+/", " ", $block['title'] ) );
            if ( $title !== '' ) {
                echo '<strong style="display:block;margin-bottom:2px;">' . esc_html( $title ) . '</strong>';
            }

            $body = trim( preg_replace( "/\s+/", " ", $block['body'] ) );
            if ( $body !== '' ) {
                echo wp_kses( $this->format_body( $body ), $allowed_html );
            }

            echo '</span>';
        }

        echo '</span>';
    }

    /**
     * Split a multi-block upgrade notice into title/body pairs.
     *
     * A line fully wrapped in ** ** is a title and starts a new block.
     * Every other non-empty line is appended to the current block body,
     * with its <b>/<strong> markup preserved for later sanitisation.
     *
     * @param string $raw Normalised notice text.
     * @return array List of array( 'title' => string, 'body' => string ).
     */
    private function parse_blocks( $raw ) {

        $lines   = explode( "\n", $raw );
        $blocks  = array();
        $current = null;

        foreach ( $lines as $line ) {

            $trimmed = trim( $line );
            if ( $trimmed === '' ) {
                continue;
            }

            $is_title = ( preg_match( '/^\*\*.+\*\*$/', $trimmed ) === 1 );

            if ( $is_title ) {
                if ( $current !== null ) {
                    $blocks[] = $current;
                }
                $current = array(
                    'title' => trim( strip_tags( str_replace( '**', '', $trimmed ) ) ),
                    'body'  => '',
                );
            } else {
                if ( $current === null ) {
                    $current = array(
                        'title' => '',
                        'body'  => '',
                    );
                }
                $current['body'] .= ( $current['body'] === '' ? '' : ' ' ) . $trimmed;
            }
        }

        if ( $current !== null ) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Build sanitised body HTML.
     *
     * Converts markdown [label](url) links and bare URLs into anchors and
     * leaves inline <b>/<strong> markup in place. The returned string is
     * passed through wp_kses by the caller, which strips disallowed tags
     * and any attributes.
     *
     * @param string $text Body text (may contain <b>/<strong> and links).
     * @return string HTML fragment for wp_kses.
     */
    private function format_body( $text ) {

        $pattern = '~\[([^\]]+)\]\((https?://[^\s)]+)\)|(https?://[^\s<)]+)~i';
        $out     = '';
        $offset  = 0;

        if ( preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[0] as $i => $match ) {

                $full  = $match[0];
                $start = $match[1];

                // Plain text before this match (may contain inline bold tags).
                $out .= substr( $text, $offset, $start - $offset );

                if ( $matches[1][ $i ][1] !== -1 ) {
                    // [label](url)
                    $label = $matches[1][ $i ][0];
                    $url   = $matches[2][ $i ][0];
                } else {
                    // Bare URL - trim trailing sentence punctuation.
                    $url   = rtrim( $matches[3][ $i ][0], '.,);:' );
                    $label = $url;
                }

                $out .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">'
                    . $label . '</a>';

                $offset = $start + strlen( $full );
            }
        }

        $out .= substr( $text, $offset );

        return $out;
    }
}

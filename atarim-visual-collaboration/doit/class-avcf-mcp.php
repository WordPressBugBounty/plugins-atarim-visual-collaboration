<?php
/**
 * Atarim MCP server orchestrator.
 *
 * Bootstraps the Atarim MCP server, wires up authentication for incoming
 * MCP requests, and dispatches ability registration to the category-specific
 * classes under doit/abilities/ and third-party/{plugin}/.
 *
 * Each ability category is implemented in its own class that extends
 * AVCF_Abilities_Base. To add a new category:
 *   1. Create doit/abilities/class-avcf-abilities-{name}.php
 *   2. require_once it in the main plugin file
 *   3. Instantiate + register() it in avcf_mcp_register_abilities() below
 *   4. Add the ability names to the $tools array in avcf_mcp_setup_server()
 *
 * Third-party plugin integrations follow the same pattern but live under
 * third-party/{plugin}/ alongside their detector and data-layer classes.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

use WP\MCP\Core\McpAdapter;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;

class AVCF_MCP {

    /** JSON-RPC code the MCP adapter returns for an unknown tool name. */
    const TOOL_NOT_FOUND = -32003;

    private $function;
    private $auth;

    public function __construct() {
        $this->function = new AVCF_Functions();
        $this->auth     = new AVCF_MCP_Auth();

        $this->init_hooks();
    }

    private function init_hooks() {
        // Security: suppress the MCP Adapter's auto-registered "default server"
        // (/wp-json/mcp/mcp-adapter-default-server). It exposes every public
        // ability through execute-ability with NO transport permission callback,
        // so HttpTransport::check_permission falls back to current_user_can('read')
        // — i.e. any logged-in user, bypassing the Atarim token gate entirely.
        // Atarim registers its own token-authenticated server, so the default
        // server is pure attack surface. Registered here (constructed before
        // McpAdapter::instance() in the cluster loader) so it applies in time.
        add_filter( 'mcp_adapter_create_default_server', '__return_false' );

        // Authenticate MCP requests by mapping Atarim token to a WordPress user.
        add_filter( 'determine_current_user', [ $this, 'avcf_mcp_authenticate_request' ], 20 );

        // Gate the whole MCP endpoint behind the "Enable Do It" setting.
        add_filter( 'rest_pre_dispatch', [ $this, 'avcf_mcp_gate_when_disabled' ], 5, 3 );

        // Point unknown-tool errors at tools/list instead of leaving a dead end.
        add_filter( 'rest_post_dispatch', [ $this, 'avcf_mcp_redirect_unknown_tool' ], 10, 3 );

        // Setup Atarim MCP server during adapter init.
        add_action( 'mcp_adapter_init', [ $this, 'avcf_mcp_setup_server' ] );

        // Fetch MCP token when plugin is updated.
        add_action( 'upgrader_process_complete', [ $this, 'avcf_mcp_on_plugin_update' ], 10, 2 );
    }

    /**
     * Dispatch ability registration to each category class.
     *
     * Called on wp_abilities_api_init (hook registered in the main plugin file).
     * Each category class is responsible for registering its own abilities with
     * the WordPress Abilities API. Third-party integrations are guarded by a
     * detector / function-exists check so we never instantiate an integration
     * whose host plugin isn't active.
     */
    public function avcf_mcp_register_abilities() {
        // Core WordPress abilities — always available.
        ( new AVCF_Abilities_Content() )->register();
        ( new AVCF_Abilities_Gutenberg() )->register();
        ( new AVCF_Abilities_Plugins() )->register();
        ( new AVCF_Abilities_Themes() )->register();
        ( new AVCF_Abilities_Theme_Files() )->register();
        ( new AVCF_Abilities_Core() )->register();
        ( new AVCF_Abilities_Taxonomies() )->register();
        ( new AVCF_Abilities_Users() )->register();
        ( new AVCF_Abilities_Settings() )->register();
        ( new AVCF_Abilities_Media() )->register();
        ( new AVCF_Abilities_Metadata() )->register();
        ( new AVCF_Abilities_Navigation() )->register();
        ( new AVCF_Abilities_Templates() )->register();
        ( new AVCF_Abilities_Global_Styles() )->register();
        ( new AVCF_Abilities_Patterns() )->register();
        ( new AVCF_Abilities_Block_Navigation() )->register();
        ( new AVCF_Abilities_Cache() )->register();
        ( new AVCF_Abilities_ReadOnly() )->register();
        ( new AVCF_Abilities_ExecutePHP() )->register();

        // Forms: Gravity Forms (standalone cluster).
        $gravity_detector = new AVCF_Gravity_Detector();
        if ( $gravity_detector->avcf_gravity_is_available() ) {
            ( new AVCF_Abilities_Gravity() )->register();
            ( new AVCF_Abilities_Gravity_Pro() )->register();
        }

        // Forms: WPForms (standalone cluster).
        $wpforms_detector = new AVCF_WPForms_Detector();
        if ( $wpforms_detector->avcf_wpforms_is_available() ) {
            ( new AVCF_Abilities_WPForms() )->register();
            ( new AVCF_Abilities_WPForms_Pro() )->register();
        }

        // Forms: Fluent Forms (standalone cluster).
        $fluent_detector = new AVCF_Fluent_Detector();
        if ( $fluent_detector->avcf_fluent_is_available() ) {
            ( new AVCF_Abilities_Fluent() )->register();
            ( new AVCF_Abilities_Fluent_Pro() )->register();
        }

        // Forms: Formidable Forms (standalone cluster).
        $formidable_detector = new AVCF_Formidable_Detector();
        if ( $formidable_detector->avcf_formidable_is_available() ) {
            ( new AVCF_Abilities_Formidable() )->register();
            ( new AVCF_Abilities_Formidable_Pro() )->register();
        }

        // Forms: Forminator (standalone cluster).
        $forminator_detector = new AVCF_Forminator_Detector();
        if ( $forminator_detector->avcf_forminator_is_available() ) {
            ( new AVCF_Abilities_Forminator() )->register();
            ( new AVCF_Abilities_Forminator_Pro() )->register();
        }

        // Forms: Ninja Forms (standalone cluster).
        $ninja_detector = new AVCF_Ninja_Detector();
        if ( $ninja_detector->avcf_ninja_is_available() ) {
            ( new AVCF_Abilities_Ninja() )->register();
            ( new AVCF_Abilities_Ninja_Pro() )->register();
        }

        // Forms: Contact Form 7 (standalone cluster, config-only).
        $cf7_detector = new AVCF_CF7_Detector();
        if ( $cf7_detector->avcf_cf7_is_available() ) {
            ( new AVCF_Abilities_CF7() )->register();
            ( new AVCF_Abilities_CF7_Pro() )->register();
        }

        // Flamingo (standalone top-level cluster — CF7's companion entry store).
        $flamingo_detector = new AVCF_Flamingo_Detector();
        if ( $flamingo_detector->avcf_flamingo_is_available() ) {
            ( new AVCF_Abilities_Flamingo() )->register();
            ( new AVCF_Abilities_Flamingo_Pro() )->register();
        }

        // Third-party: WooCommerce.
        $wc_detector = new AVCF_WC_Detector();
        if ( $wc_detector->avcf_wc_is_available() ) {
            ( new AVCF_Abilities_WooCommerce() )->register();
        }

        // Third-party: WP Activity Log.
        $wpal_detector = new AVCF_WPAL_Detector();
        if ( $wpal_detector->avcf_wpal_is_available() ) {
            ( new AVCF_Abilities_WPAL() )->register();
        }

        // Third-party: Advanced Custom Fields.
        $acf_detector = new AVCF_ACF_Detector();
        if ( $acf_detector->avcf_acf_is_available() ) {
            ( new AVCF_Abilities_ACF() )->register();
        }

        // Third-party: Yoast SEO.
        $yoast_detector = new AVCF_Yoast_Detector();
        if ( $yoast_detector->avcf_yoast_is_available() ) {
            ( new AVCF_Abilities_Yoast() )->register();
        }

        // Third-party: Rank Math.
        $rankmath_detector = new AVCF_RankMath_Detector();
        if ( $rankmath_detector->avcf_rankmath_is_available() ) {
            ( new AVCF_Abilities_RankMath() )->register();
        }

        // Third-party: All in One SEO.
        $aioseo_detector = new AVCF_AIOSEO_Detector();
        if ( $aioseo_detector->avcf_aioseo_is_available() ) {
            ( new AVCF_Abilities_AIOSEO() )->register();
        }

        // Third-party: Elementor.
        $elementor_detector = new AVCF_Elementor_Detector();
        if ( $elementor_detector->avcf_elementor_is_available() ) {
            ( new AVCF_Abilities_Elementor() )->register();
            ( new AVCF_Abilities_Elementor_Pro() )->register();
        }

        $shortpixel_detector = new AVCF_ShortPixel_Detector();
        if ( $shortpixel_detector->avcf_shortpixel_is_available() ) {
            ( new AVCF_Abilities_ShortPixel() )->register();
        }

        $ewww_detector = new AVCF_EWWW_Detector();
        if ( $ewww_detector->avcf_ewww_is_available() ) {
            ( new AVCF_Abilities_EWWW() )->register();
        }

        $resmushit_detector = new AVCF_ReSmushit_Detector();
        if ( $resmushit_detector->avcf_resmushit_is_available() ) {
            ( new AVCF_Abilities_ReSmushit() )->register();
        }

        $smush_detector = new AVCF_Smush_Detector();
        if ( $smush_detector->avcf_smush_is_available() ) {
            ( new AVCF_Abilities_Smush() )->register();
        }

        $optimole_detector = new AVCF_Optimole_Detector();
        if ( $optimole_detector->avcf_optimole_is_available() ) {
            ( new AVCF_Abilities_Optimole() )->register();
        }

        // Third-party: Meta Box.
        $metabox_detector = new AVCF_MetaBox_Detector();
        if ( $metabox_detector->avcf_mb_is_available() ) {
            ( new AVCF_Abilities_MetaBox() )->register();
        }

        // Third-party: JetEngine.
        $jetengine_detector = new AVCF_JetEngine_Detector();
        if ( $jetengine_detector->avcf_je_is_available() ) {
            ( new AVCF_Abilities_JetEngine() )->register();
        }

        // Third-party: Pods.
        $pods_detector = new AVCF_Pods_Detector();
        if ( $pods_detector->avcf_pods_is_available() ) {
            ( new AVCF_Abilities_Pods() )->register();
        }

        // Third-party: ACPT.
        $acpt_detector = new AVCF_ACPT_Detector();
        if ( $acpt_detector->avcf_acpt_is_available() ) {
            ( new AVCF_Abilities_ACPT() )->register();
        }

        // Third-party: ASE.
        $ase_detector = new AVCF_ASE_Detector();
        if ( $ase_detector->avcf_ase_is_available() ) {
            ( new AVCF_Abilities_ASE() )->register();
        }

        // Third-party: Bricks (Wave 4 builder).
        $bricks_detector = new AVCF_Bricks_Detector();
        if ( $bricks_detector->avcf_bricks_is_available() ) {
            ( new AVCF_Abilities_Bricks() )->register();
            ( new AVCF_Abilities_Bricks_Pro() )->register();
        }

        // Third-party: Divi (Wave 4 builder).
        $divi_detector = new AVCF_Divi_Detector();
        if ( $divi_detector->avcf_divi_is_available() ) {
            ( new AVCF_Abilities_Divi() )->register();
            ( new AVCF_Abilities_Divi_Pro() )->register();
        }

        // Third-party: WPBakery (Wave 4 builder).
        $wpbakery_detector = new AVCF_WPBakery_Detector();
        if ( $wpbakery_detector->avcf_wpbakery_is_available() ) {
            ( new AVCF_Abilities_WPBakery() )->register();
            ( new AVCF_Abilities_WPBakery_Pro() )->register();
        }

        // Third-party: Breakdance (Wave 4 builder).
        $breakdance_detector = new AVCF_Breakdance_Detector();
        if ( $breakdance_detector->avcf_breakdance_is_available() ) {
            ( new AVCF_Abilities_Breakdance() )->register();
            ( new AVCF_Abilities_Breakdance_Pro() )->register();
        }

        // Third-party: Etch (Wave 4 builder).
        $etch_detector = new AVCF_Etch_Detector();
        if ( $etch_detector->avcf_etch_is_available() ) {
            ( new AVCF_Abilities_Etch() )->register();
            ( new AVCF_Abilities_Etch_Pro() )->register();
        }

        // Third-party: Mosaic (Wave 4 builder).
        $mosaic_detector = new AVCF_Mosaic_Detector();
        if ( $mosaic_detector->avcf_mosaic_is_available() ) {
            ( new AVCF_Abilities_Mosaic() )->register();
            ( new AVCF_Abilities_Mosaic_Pro() )->register();
        }

        // Third-party: JetBackup (backup cluster). Native jetbackup/* abilities
        // are blocklisted (see AVCF_JetBackup_Detector::filter_blocklist) so only
        // our unified atarim/jetbackup-* surface is exposed.
        $jetbackup_detector = new AVCF_JetBackup_Detector();
        if ( $jetbackup_detector->avcf_jetbackup_is_available() ) {
            ( new AVCF_Abilities_JetBackup_Backups() )->register();
            ( new AVCF_Abilities_JetBackup_Restore() )->register();
            ( new AVCF_Abilities_JetBackup_Jobs() )->register();
            ( new AVCF_Abilities_JetBackup_Schedules() )->register();
            ( new AVCF_Abilities_JetBackup_Destinations() )->register();
            ( new AVCF_Abilities_JetBackup_Queue() )->register();
            ( new AVCF_Abilities_JetBackup_Settings() )->register();
            ( new AVCF_Abilities_JetBackup_System() )->register();
        }
    }

    /**
     * Authenticate MCP requests by mapping Atarim token to a WordPress user.
     * This satisfies the MCP Adapter's is_user_logged_in() requirement.
     */
    /**
     * Whether a REST route/URI targets one of the MCP endpoints we protect.
     *
     * Covers the Atarim server (/atarim/mcp) and the MCP Adapter's default server
     * (/mcp/mcp-adapter-default-server). The default server is disabled in
     * init_hooks(); matching it here as well means the DoIt gate and token mapping
     * still apply if it is ever re-enabled (e.g. by another consumer of the bundled
     * adapter), rather than silently reopening an ungated surface.
     *
     * @param string $route_or_uri REST route or request URI.
     * @return bool
     */
    private function avcf_is_protected_mcp_route( $route_or_uri ) {
        $route_or_uri = (string) $route_or_uri;
        return ( false !== strpos( $route_or_uri, '/atarim/mcp' ) )
            || ( false !== strpos( $route_or_uri, '/mcp/mcp-adapter-default-server' ) );
    }

    /**
     * Short-circuit the Atarim MCP endpoint when "Do It" is disabled.
     *
     * Returns a clear notice for every request to /atarim/mcp (list and call)
     * unless the avc_enable_doit setting is on. The option is unset on sites that
     * updated into this feature (treated as disabled) and set to '1' on fresh
     * installs via the activation hook.
     *
     * @param mixed            $result  Dispatch result (WP_Error short-circuits).
     * @param WP_REST_Server   $server  REST server instance.
     * @param WP_REST_Request  $request Current request.
     * @return mixed
     */
    public function avcf_mcp_gate_when_disabled( $result, $server, $request ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
        if ( ! $this->avcf_is_protected_mcp_route( $route ) ) {
            return $result;
        }

        $enabled = $this->function->avcf_get_setting_data( 'avc_enable_doit', false );
        if ( empty( $enabled ) ) {
            return new WP_Error(
                'avc_doit_disabled',
                __( 'Do It via Atarim AI is disabled for this site. Enable it from the Atarim plugin settings to allow execution.', 'atarim-visual-collaboration' ),
                [ 'status' => 403 ]
            );
        }

        return $result;
    }

    public function avcf_mcp_authenticate_request( $user_id ) {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return $user_id;
        }

        $request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        if ( ! $this->avcf_is_protected_mcp_route( $request_uri ) ) {
            return $user_id;
        }

        if ( ! $this->auth->avcf_mcp_validate_request() ) {
            return $user_id;
        }

        $webmaster_email = $this->function->avcf_get_setting_data( 'avc_website_developer' );
        if ( empty( $webmaster_email ) ) {
            $admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
            if ( ! empty( $admins ) ) {
                return $admins[0]->ID;
            }
            return $user_id;
        }

        $user = get_user_by( 'email', $webmaster_email );
        if ( $user && ! is_wp_error( $user ) ) {
            return $user->ID;
        }

        return $user_id;
    }

    /**
     * Setup the Atarim MCP server.
     *
     * Exposes the adapter meta-tools plus every registered MCP-public tool
     * ability. Exposure follows registration automatically — there is no
     * per-ability whitelist to maintain. The site owner selectively hides
     * abilities (yours or third-party) via the avcf_mcp_blocked_abilities
     * setting, without touching ability code.
     */
    public function avcf_mcp_setup_server( $adapter ) {
        // Expose adapter meta-tools plus EVERY registered MCP-public tool
        // ability (mcp.public === true, type 'tool'), mirroring the adapter's
        // own discover-abilities logic. Abilities are exposed by registration
        // alone now — building a new cluster needs no edit here. Hide specific
        // abilities (yours or third-party) via the avcf_mcp_blocked_abilities
        // setting; that blocklist is applied just below.
        $tools = array(
            // Meta-tools intentionally NOT exposed. discover-abilities /
            // get-ability-info / execute-ability are a generic gateway over the
            // whole abilities registry that bypasses the named-tool surface (and
            // therefore the avcf_mcp_blocked_abilities blocklist applied below).
            // With them disabled, a named tools/call is the only execution door,
            // so the blocklist is a real boundary. Re-enable only if the agent
            // must call abilities generically by name via execute-ability.
            // 'mcp-adapter/discover-abilities',
            // 'mcp-adapter/get-ability-info',
            // 'mcp-adapter/execute-ability',
        );

        if ( function_exists( 'wp_get_abilities' ) ) {
            foreach ( wp_get_abilities() as $ability ) {
                if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) || ! method_exists( $ability, 'get_name' ) ) {
                    continue;
                }
                $meta = (array) $ability->get_meta();
                $is_public = isset( $meta['mcp']['public'] ) ? (bool) $meta['mcp']['public'] : false;
                if ( ! $is_public ) {
                    continue;
                }
                $mcp_type = isset( $meta['mcp']['type'] ) ? (string) $meta['mcp']['type'] : 'tool';
                if ( $mcp_type !== 'tool' ) {
                    continue;
                }
                $tools[] = $ability->get_name();
            }
        }

        $tools = array_values( array_unique( $tools ) );

        // Allow site owner to block specific abilities via Atarim dashboard
        $blocked = (array) $this->function->avcf_get_setting_data( 'avcf_mcp_blocked_abilities', [] );
        // Allow integrations (e.g. the JetBackup cluster) to contribute blocked
        // names conditionally, without persisting them to the stored setting.
        $blocked = (array) apply_filters( 'avcf_mcp_blocked_abilities', $blocked );

        $allowed = array_values( array_filter(
            $tools,
            function( $name ) use ( $blocked ) {
                return ! in_array( $name, $blocked, true );
            }
        ) );

        $adapter->create_server(
            'atarim-mcp-server',
            'atarim',
            'mcp',
            'Atarim MCP Server',
            'Atarim AI action layer',
            'v1.0.0',
            [ HttpTransport::class ],
            ErrorLogMcpErrorHandler::class,
            NullMcpObservabilityHandler::class,
            $allowed,
            [],
            [],
            function() {
                $incoming_token = isset( $_SERVER['HTTP_X_ATARIM_TOKEN'] )
                    ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_ATARIM_TOKEN'] ) )
                    : '';

                $stored_token = $this->auth->avcf_mcp_get_token();

                if ( empty( $incoming_token ) || empty( $stored_token ) ) {
                    return false;
                }

                return hash_equals( $stored_token, $incoming_token );
            }
        );
    }

    /**
     * Replace the adapter's bare "Tool not found: X" with an instruction to
     * re-read the tool list.
     *
     * The adapter answers an unknown tool name with a JSON-RPC -32003 and
     * nothing else, so a caller that guessed a name has no route back and
     * commonly guesses again, or reports the invented name upstream as though
     * it were real.
     *
     * We deliberately do NOT suggest alternatives. tools/list is the
     * authoritative set and already reflects which plugins are active on this
     * site; anything we computed here would be an approximation of it, and a
     * wrong suggestion is worse than none because it invites a call to an
     * unrelated tool.
     *
     * @param mixed            $response Dispatch result.
     * @param WP_REST_Server   $server   REST server instance.
     * @param WP_REST_Request  $request  Current request.
     * @return mixed
     */
    public function avcf_mcp_redirect_unknown_tool( $response, $server, $request ) {
        if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
            return $response;
        }
        if ( strpos( (string) $request->get_route(), '/atarim/mcp' ) === false ) {
            return $response;
        }
        if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
            return $response;
        }

        $data = $response->get_data();
        if ( ! is_array( $data ) || $data === [] ) {
            return $response;
        }

        // A JSON-RPC batch comes back as a list of responses.
        if ( isset( $data[0] ) && is_array( $data[0] ) ) {
            $changed = false;
            foreach ( $data as $i => $entry ) {
                $new = $this->avcf_mcp_rewrite_not_found( $entry );
                if ( null !== $new ) { $data[ $i ] = $new; $changed = true; }
            }
            if ( $changed ) { $response->set_data( $data ); }
            return $response;
        }

        $new = $this->avcf_mcp_rewrite_not_found( $data );
        if ( null !== $new ) { $response->set_data( $new ); }
        return $response;
    }

    /** Returns the rewritten entry, or null if it isn't a tool-not-found error. */
    private function avcf_mcp_rewrite_not_found( $entry ) {
        if ( ! is_array( $entry ) || ! isset( $entry['error']['code'] ) ) {
            return null;
        }
        if ( (int) $entry['error']['code'] !== self::TOOL_NOT_FOUND ) {
            return null;
        }

        $entry['error']['message'] = __(
            'No tool or ability with that name exists on this site. Call tools/list to get the current list, and use only a name that appears in it exactly. Which tools exist depends on which plugins are active on this site, so a tool that exists elsewhere may not exist here. If nothing in the list does what you need, that capability is unavailable here: report that and stop, rather than trying another name.',
            'atarim-visual-collaboration'
        );

        return $entry;
    }

    /**
     * Fires when any plugin is updated.
     * Fetches MCP token from Atarim backend if site is connected but token not yet saved.
     */
    public function avcf_mcp_on_plugin_update( $upgrader, $options ) {
        if (
            $options['type'] !== 'plugin' ||
            $options['action'] !== 'update'
        ) {
            return;
        }

        if (
            ! isset( $options['plugins'] ) ||
            ! in_array( AVCF_PLUGIN_BASE, $options['plugins'], true )
        ) {
            return;
        }

        $site_id        = $this->function->avcf_get_setting_data( 'avc_site_id' );
        $is_connected   = $this->function->avcf_get_setting_data( 'avc_initial_setup_complete' );
        $existing_token = $this->auth->avcf_mcp_get_token();

        if ( empty( $site_id ) || $is_connected !== 'yes' || ! empty( $existing_token ) ) {
            return;
        }

        $this->avcf_mcp_fetch_token( $site_id );
    }

    /**
     * Fetch MCP token from Atarim backend and save it.
     */
    public function avcf_mcp_fetch_token( $site_id ) {
        $response = $this->function->avcf_make_api_call(
            AVCF_CRM_API . 'wp-api/mcp/token',
            [ 'site_id' => $site_id ],
            '',
            '',
            'POST'
        );

        if (
            isset( $response['status_code'] ) &&
            $response['status_code'] === 200 &&
            ! empty( $response['data']['mcp_token'] )
        ) {
            $this->function->avcf_update_settings(
                'avc_atarim_secret_token',
                trim( $response['data']['mcp_token'] )
            );
        }
    }
}
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
 *   3. Register it in avcf_mcp_register_abilities() below via
 *      avcf_register_cluster( 'cluster-slug', new AVCF_Abilities_{Name}() ),
 *      reusing an existing slug when the category belongs with one
 *
 * Exposure follows registration, and the cluster slug is what a caller names in
 * the X-Atarim-MCP-Clusters header to list that category on its own.
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

    /**
     * Request header naming the tool clusters the caller wants listed.
     *
     * Comma-separated cluster slugs, e.g. "content,elementor". Absent or
     * unrecognised, the whole tool surface is exposed as before. See
     * avcf_mcp_requested_clusters().
     */
    const CLUSTER_HEADER = 'HTTP_X_ATARIM_MCP_CLUSTERS';

    /**
     * Request header choosing between short and full tool descriptions.
     *
     * "full" restores the untruncated description in tools/list; anything else
     * (including absence) gets the first sentence. See avcf_mcp_shape_tool().
     */
    const DETAIL_HEADER = 'HTTP_X_ATARIM_MCP_DETAIL';

    /** Longest short description we emit before falling back to a hard cut. */
    const SHORT_DESCRIPTION_CAP = 200;

    private $function;
    private $auth;

    /**
     * Ability name => cluster slug, filled in as each category registers.
     *
     * Built by avcf_register_cluster() rather than declared per ability: the
     * cluster a tool belongs to is already expressed by which class registers
     * it, so recording it at that moment keeps ~770 registrations free of a
     * field that would have to be kept in sync by hand.
     *
     * @var array<string,string>
     */
    private $cluster_map = [];

    /**
     * Ability name => first-sentence description, built during server setup.
     *
     * @var array<string,string>
     */
    private $short_descriptions = [];

    /**
     * Cluster slug => exposed tool count for the whole surface, for the
     * listing metadata.
     *
     * @var array<string,int>
     */
    private $cluster_counts = [];

    /**
     * Cluster slugs the current request asked for, echoed back in the listing
     * metadata so a caller can see whether its filter was understood.
     *
     * @var string[]
     */
    private $requested_clusters = [];

    /**
     * Lazily built lookup of the maps above, keyed by MCP tool name.
     *
     * @var array|null
     */
    private $folded_index = null;

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

        // Trim the tools/list payload before it goes out.
        add_filter( 'rest_post_dispatch', [ $this, 'avcf_mcp_shape_tools_list' ], 10, 3 );

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
        $this->avcf_register_cluster( 'content', new AVCF_Abilities_Content() );
        $this->avcf_register_cluster( 'content', new AVCF_Abilities_Gutenberg() );
        $this->avcf_register_cluster( 'plugins', new AVCF_Abilities_Plugins() );
        $this->avcf_register_cluster( 'themes', new AVCF_Abilities_Themes() );
        $this->avcf_register_cluster( 'themes', new AVCF_Abilities_Theme_Files() );
        $this->avcf_register_cluster( 'site', new AVCF_Abilities_Core() );
        $this->avcf_register_cluster( 'taxonomies', new AVCF_Abilities_Taxonomies() );
        $this->avcf_register_cluster( 'users', new AVCF_Abilities_Users() );
        $this->avcf_register_cluster( 'site', new AVCF_Abilities_Settings() );
        $this->avcf_register_cluster( 'media', new AVCF_Abilities_Media() );
        $this->avcf_register_cluster( 'metadata', new AVCF_Abilities_Metadata() );
        $this->avcf_register_cluster( 'design', new AVCF_Abilities_Navigation() );
        $this->avcf_register_cluster( 'design', new AVCF_Abilities_Templates() );
        $this->avcf_register_cluster( 'design', new AVCF_Abilities_Global_Styles() );
        $this->avcf_register_cluster( 'content', new AVCF_Abilities_Patterns() );
        $this->avcf_register_cluster( 'design', new AVCF_Abilities_Block_Navigation() );
        $this->avcf_register_cluster( 'site', new AVCF_Abilities_Cache() );
        $this->avcf_register_cluster( 'diagnostics', new AVCF_Abilities_ReadOnly() );
        $this->avcf_register_cluster( 'advanced', new AVCF_Abilities_Batch() );
        $this->avcf_register_cluster( 'advanced', new AVCF_Abilities_ExecutePHP() );
        $this->avcf_register_cluster( 'advanced', new AVCF_Abilities_WP_CLI() );

        // Forms: Gravity Forms (standalone cluster).
        $gravity_detector = new AVCF_Gravity_Detector();
        if ( $gravity_detector->avcf_gravity_is_available() ) {
            $this->avcf_register_cluster( 'gravity-forms', new AVCF_Abilities_Gravity() );
            $this->avcf_register_cluster( 'gravity-forms', new AVCF_Abilities_Gravity_Pro() );
        }

        // Forms: WPForms (standalone cluster).
        $wpforms_detector = new AVCF_WPForms_Detector();
        if ( $wpforms_detector->avcf_wpforms_is_available() ) {
            $this->avcf_register_cluster( 'wpforms', new AVCF_Abilities_WPForms() );
            $this->avcf_register_cluster( 'wpforms', new AVCF_Abilities_WPForms_Pro() );
        }

        // Forms: Fluent Forms (standalone cluster).
        $fluent_detector = new AVCF_Fluent_Detector();
        if ( $fluent_detector->avcf_fluent_is_available() ) {
            $this->avcf_register_cluster( 'fluent-forms', new AVCF_Abilities_Fluent() );
            $this->avcf_register_cluster( 'fluent-forms', new AVCF_Abilities_Fluent_Pro() );
        }

        // Forms: Formidable Forms (standalone cluster).
        $formidable_detector = new AVCF_Formidable_Detector();
        if ( $formidable_detector->avcf_formidable_is_available() ) {
            $this->avcf_register_cluster( 'formidable', new AVCF_Abilities_Formidable() );
            $this->avcf_register_cluster( 'formidable', new AVCF_Abilities_Formidable_Pro() );
        }

        // Forms: Forminator (standalone cluster).
        $forminator_detector = new AVCF_Forminator_Detector();
        if ( $forminator_detector->avcf_forminator_is_available() ) {
            $this->avcf_register_cluster( 'forminator', new AVCF_Abilities_Forminator() );
            $this->avcf_register_cluster( 'forminator', new AVCF_Abilities_Forminator_Pro() );
        }

        // Forms: Ninja Forms (standalone cluster).
        $ninja_detector = new AVCF_Ninja_Detector();
        if ( $ninja_detector->avcf_ninja_is_available() ) {
            $this->avcf_register_cluster( 'ninja-forms', new AVCF_Abilities_Ninja() );
            $this->avcf_register_cluster( 'ninja-forms', new AVCF_Abilities_Ninja_Pro() );
        }

        // Forms: Contact Form 7 (standalone cluster, config-only).
        $cf7_detector = new AVCF_CF7_Detector();
        if ( $cf7_detector->avcf_cf7_is_available() ) {
            $this->avcf_register_cluster( 'contact-form-7', new AVCF_Abilities_CF7() );
            $this->avcf_register_cluster( 'contact-form-7', new AVCF_Abilities_CF7_Pro() );
        }

        // Flamingo (standalone top-level cluster — CF7's companion entry store).
        $flamingo_detector = new AVCF_Flamingo_Detector();
        if ( $flamingo_detector->avcf_flamingo_is_available() ) {
            $this->avcf_register_cluster( 'flamingo', new AVCF_Abilities_Flamingo() );
            $this->avcf_register_cluster( 'flamingo', new AVCF_Abilities_Flamingo_Pro() );
        }

        // Third-party: WooCommerce.
        $wc_detector = new AVCF_WC_Detector();
        if ( $wc_detector->avcf_wc_is_available() ) {
            $this->avcf_register_cluster( 'woocommerce', new AVCF_Abilities_WooCommerce() );
        }

        // Third-party: WP Activity Log.
        $wpal_detector = new AVCF_WPAL_Detector();
        if ( $wpal_detector->avcf_wpal_is_available() ) {
            $this->avcf_register_cluster( 'activity-log', new AVCF_Abilities_WPAL() );
        }

        // Third-party: Advanced Custom Fields.
        $acf_detector = new AVCF_ACF_Detector();
        if ( $acf_detector->avcf_acf_is_available() ) {
            $this->avcf_register_cluster( 'acf', new AVCF_Abilities_ACF() );
        }

        // Third-party: Yoast SEO.
        $yoast_detector = new AVCF_Yoast_Detector();
        if ( $yoast_detector->avcf_yoast_is_available() ) {
            $this->avcf_register_cluster( 'yoast', new AVCF_Abilities_Yoast() );
        }

        // Third-party: Rank Math.
        $rankmath_detector = new AVCF_RankMath_Detector();
        if ( $rankmath_detector->avcf_rankmath_is_available() ) {
            $this->avcf_register_cluster( 'rank-math', new AVCF_Abilities_RankMath() );
        }

        // Third-party: All in One SEO.
        $aioseo_detector = new AVCF_AIOSEO_Detector();
        if ( $aioseo_detector->avcf_aioseo_is_available() ) {
            $this->avcf_register_cluster( 'aioseo', new AVCF_Abilities_AIOSEO() );
        }

        // Third-party: Elementor.
        $elementor_detector = new AVCF_Elementor_Detector();
        if ( $elementor_detector->avcf_elementor_is_available() ) {
            $this->avcf_register_cluster( 'elementor', new AVCF_Abilities_Elementor() );
            $this->avcf_register_cluster( 'elementor', new AVCF_Abilities_Elementor_Pro() );
        }

        $shortpixel_detector = new AVCF_ShortPixel_Detector();
        if ( $shortpixel_detector->avcf_shortpixel_is_available() ) {
            $this->avcf_register_cluster( 'shortpixel', new AVCF_Abilities_ShortPixel() );
        }

        $ewww_detector = new AVCF_EWWW_Detector();
        if ( $ewww_detector->avcf_ewww_is_available() ) {
            $this->avcf_register_cluster( 'ewww', new AVCF_Abilities_EWWW() );
        }

        $resmushit_detector = new AVCF_ReSmushit_Detector();
        if ( $resmushit_detector->avcf_resmushit_is_available() ) {
            $this->avcf_register_cluster( 'resmushit', new AVCF_Abilities_ReSmushit() );
        }

        $smush_detector = new AVCF_Smush_Detector();
        if ( $smush_detector->avcf_smush_is_available() ) {
            $this->avcf_register_cluster( 'smush', new AVCF_Abilities_Smush() );
        }

        $optimole_detector = new AVCF_Optimole_Detector();
        if ( $optimole_detector->avcf_optimole_is_available() ) {
            $this->avcf_register_cluster( 'optimole', new AVCF_Abilities_Optimole() );
        }

        // Third-party: Meta Box.
        $metabox_detector = new AVCF_MetaBox_Detector();
        if ( $metabox_detector->avcf_mb_is_available() ) {
            $this->avcf_register_cluster( 'meta-box', new AVCF_Abilities_MetaBox() );
        }

        // Third-party: JetEngine.
        $jetengine_detector = new AVCF_JetEngine_Detector();
        if ( $jetengine_detector->avcf_je_is_available() ) {
            $this->avcf_register_cluster( 'jetengine', new AVCF_Abilities_JetEngine() );
        }

        // Third-party: Pods.
        $pods_detector = new AVCF_Pods_Detector();
        if ( $pods_detector->avcf_pods_is_available() ) {
            $this->avcf_register_cluster( 'pods', new AVCF_Abilities_Pods() );
        }

        // Third-party: ACPT.
        $acpt_detector = new AVCF_ACPT_Detector();
        if ( $acpt_detector->avcf_acpt_is_available() ) {
            $this->avcf_register_cluster( 'acpt', new AVCF_Abilities_ACPT() );
        }

        // Third-party: ASE.
        $ase_detector = new AVCF_ASE_Detector();
        if ( $ase_detector->avcf_ase_is_available() ) {
            $this->avcf_register_cluster( 'ase', new AVCF_Abilities_ASE() );
        }

        // Third-party: Bricks (Wave 4 builder).
        $bricks_detector = new AVCF_Bricks_Detector();
        if ( $bricks_detector->avcf_bricks_is_available() ) {
            $this->avcf_register_cluster( 'bricks', new AVCF_Abilities_Bricks() );
            $this->avcf_register_cluster( 'bricks', new AVCF_Abilities_Bricks_Pro() );
        }

        // Third-party: Divi (Wave 4 builder).
        $divi_detector = new AVCF_Divi_Detector();
        if ( $divi_detector->avcf_divi_is_available() ) {
            $this->avcf_register_cluster( 'divi', new AVCF_Abilities_Divi() );
            $this->avcf_register_cluster( 'divi', new AVCF_Abilities_Divi_Pro() );
        }

        // Third-party: WPBakery (Wave 4 builder).
        $wpbakery_detector = new AVCF_WPBakery_Detector();
        if ( $wpbakery_detector->avcf_wpbakery_is_available() ) {
            $this->avcf_register_cluster( 'wpbakery', new AVCF_Abilities_WPBakery() );
            $this->avcf_register_cluster( 'wpbakery', new AVCF_Abilities_WPBakery_Pro() );
        }

        // Third-party: Breakdance (Wave 4 builder).
        $breakdance_detector = new AVCF_Breakdance_Detector();
        if ( $breakdance_detector->avcf_breakdance_is_available() ) {
            $this->avcf_register_cluster( 'breakdance', new AVCF_Abilities_Breakdance() );
            $this->avcf_register_cluster( 'breakdance', new AVCF_Abilities_Breakdance_Pro() );
        }

        // Third-party: Etch (Wave 4 builder).
        $etch_detector = new AVCF_Etch_Detector();
        if ( $etch_detector->avcf_etch_is_available() ) {
            $this->avcf_register_cluster( 'etch', new AVCF_Abilities_Etch() );
            $this->avcf_register_cluster( 'etch', new AVCF_Abilities_Etch_Pro() );
        }

        // Third-party: Mosaic (Wave 4 builder).
        $mosaic_detector = new AVCF_Mosaic_Detector();
        if ( $mosaic_detector->avcf_mosaic_is_available() ) {
            $this->avcf_register_cluster( 'mosaic', new AVCF_Abilities_Mosaic() );
            $this->avcf_register_cluster( 'mosaic', new AVCF_Abilities_Mosaic_Pro() );
        }

        // Third-party: JetBackup (backup cluster). Native jetbackup/* abilities
        // are blocklisted (see AVCF_JetBackup_Detector::filter_blocklist) so only
        // our unified atarim/jetbackup-* surface is exposed.
        $jetbackup_detector = new AVCF_JetBackup_Detector();
        if ( $jetbackup_detector->avcf_jetbackup_is_available() ) {
            $this->avcf_register_cluster( 'jetbackup', new AVCF_Abilities_JetBackup_Backups() );
            $this->avcf_register_cluster( 'jetbackup', new AVCF_Abilities_JetBackup_Restore() );
            $this->avcf_register_cluster( 'jetbackup', new AVCF_Abilities_JetBackup_Jobs() );
            $this->avcf_register_cluster( 'jetbackup', new AVCF_Abilities_JetBackup_Schedules() );
            $this->avcf_register_cluster( 'jetbackup', new AVCF_Abilities_JetBackup_Destinations() );
            $this->avcf_register_cluster( 'jetbackup', new AVCF_Abilities_JetBackup_Queue() );
            $this->avcf_register_cluster( 'jetbackup', new AVCF_Abilities_JetBackup_Settings() );
            $this->avcf_register_cluster( 'jetbackup', new AVCF_Abilities_JetBackup_System() );
            $this->avcf_register_cluster( 'jetbackup', new AVCF_Abilities_JetBackup_Restore_Point() );
        }
    }

    /**
     * Register one ability category, recording which cluster its abilities join.
     *
     * The Abilities API has no "which class registered this" hook, so cluster
     * membership is taken from the difference register() makes to the registry.
     * Reading the registry mid-init is safe: WP_Abilities_Registry assigns its
     * singleton before firing wp_abilities_api_init, so wp_get_abilities() here
     * returns what has been registered so far instead of re-entering the hook.
     *
     * @param string             $cluster   Cluster slug the caller can ask for.
     * @param AVCF_Abilities_Base $abilities Category to register.
     */
    private function avcf_register_cluster( $cluster, $abilities ) {
        $before = $this->avcf_registered_ability_names();

        $abilities->register();

        foreach ( array_diff( $this->avcf_registered_ability_names(), $before ) as $name ) {
            $this->cluster_map[ $name ] = $cluster;
        }
    }

    /** Every ability name registered so far, in registration order. */
    private function avcf_registered_ability_names() {
        if ( ! function_exists( 'wp_get_abilities' ) ) {
            return [];
        }

        $names = [];
        foreach ( wp_get_abilities() as $key => $ability ) {
            if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) ) {
                $names[] = $ability->get_name();
            } elseif ( is_string( $key ) ) {
                $names[] = $key;
            }
        }

        return $names;
    }

    /**
     * MCP tool names are the ability name with "/" folded to "-", so anything
     * keyed by ability name has to be looked up through the same fold.
     *
     * @param string $ability_name
     * @return string
     */
    private function avcf_fold_tool_name( $ability_name ) {
        return str_replace( '/', '-', trim( (string) $ability_name ) );
    }

    /**
     * Cluster slugs the caller asked for, from the X-Atarim-MCP-Clusters header.
     *
     * @return string[] Lower-cased slugs; empty when the caller wants everything.
     */
    private function avcf_mcp_requested_clusters() {
        if ( empty( $_SERVER[ self::CLUSTER_HEADER ] ) ) {
            return [];
        }

        $raw   = sanitize_text_field( wp_unslash( $_SERVER[ self::CLUSTER_HEADER ] ) );
        $slugs = array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) );

        return array_values( array_unique( $slugs ) );
    }

    /** Whether the caller asked for untruncated descriptions in tools/list. */
    private function avcf_mcp_wants_full_descriptions() {
        if ( empty( $_SERVER[ self::DETAIL_HEADER ] ) ) {
            return false;
        }

        return 'full' === strtolower( sanitize_text_field( wp_unslash( $_SERVER[ self::DETAIL_HEADER ] ) ) );
    }

    /**
     * First sentence of a description, for the listing.
     *
     * Descriptions are written to be read at call time — they carry parameter
     * notes, provider caveats and "use X instead" pointers that a caller
     * choosing between tools does not need. The opening sentence is what says
     * which tool this is; the rest is available with X-Atarim-MCP-Detail: full.
     *
     * An ability can override the derived text with a short_description in its
     * mcp meta when the first sentence is a poor summary.
     *
     * @param string $description
     * @return string
     */
    private function avcf_mcp_short_description( $description ) {
        $description = trim( (string) $description );
        if ( '' === $description ) {
            return '';
        }

        // Shortest prefix ending in sentence punctuation that is followed by
        // whitespace, skipping abbreviations that end in a period and are followed by
        // one. "1.5 " never matched, since a digit is not whitespace, but "e.g. "
        // did, leaving summaries like "Browse files/folders (e.g." on the wire.
        //
        // Two rules rather than one blacklist. The first is by shape: a dot
        // preceded by a single letter that is itself preceded by a dot, which
        // is the tail of "e.g.", "i.e." or "U.S." — a run of letter-dot pairs.
        // It does NOT cover a lone initial ("J. Smith"), because a single
        // letter before a dot is also how many ordinary sentences end.
        //
        // The second is a blacklist, and every entry is \b-anchored: unanchored
        // `(?<!eg)` suppressed the split after ANY word ending in those two
        // letters, so "Deletes a movie. Second sentence" produced no split at
        // all. `no`/`No` were dropped from it — unlike the others they are
        // ordinary English words, and "Set public to yes or no. Second
        // sentence" is far more likely in ability copy than "No." as an
        // abbreviation.
        $abbreviations = '(?<!\b[A-Za-z]\.[A-Za-z])'
            . '(?<!\betc)(?<!\bvs)(?<!\bcf)(?<!\beg)(?<!\bie)(?<!\bal)'
            . '(?<!\bDr)(?<!\bMr)(?<!\bMs)(?<!\bMrs)(?<!\bFig)(?<!\bapprox)';
        if ( preg_match( '/^.*?' . $abbreviations . '[.!?](?=\s)/su', $description, $match ) ) {
            $first = trim( $match[0] );
            if ( mb_strlen( $first ) <= self::SHORT_DESCRIPTION_CAP ) {
                return $first;
            }
        }

        if ( mb_strlen( $description ) <= self::SHORT_DESCRIPTION_CAP ) {
            return $description;
        }

        $cut   = mb_substr( $description, 0, self::SHORT_DESCRIPTION_CAP );
        $space = mb_strrpos( $cut, ' ' );
        if ( false !== $space ) {
            $cut = mb_substr( $cut, 0, $space );
        }

        return rtrim( $cut, " ,;:" ) . '…';
    }

    /**
     * One line naming the clusters on this site, appended to the server
     * description — which the adapter returns as `instructions` from
     * initialize, a call every client already makes before it lists tools.
     * Discovering the clusters therefore costs no extra round trip.
     *
     * @param string[] $tool_names Ability names exposed before cluster filtering.
     * @return string
     */
    private function avcf_mcp_cluster_catalogue( array $tool_names ) {
        $counts = $this->avcf_mcp_cluster_counts( $tool_names );
        if ( empty( $counts ) ) {
            return '';
        }

        $parts = [];
        foreach ( $counts as $slug => $count ) {
            $parts[] = $slug . ' (' . $count . ')';
        }

        return sprintf(
            ' Tools are grouped into clusters. Send the header "X-Atarim-MCP-Clusters: slug,slug" with tools/list to list only those clusters, and "X-Atarim-MCP-Detail: full" for untruncated tool descriptions. Clusters on this site: %s.',
            implode( ', ', $parts )
        );
    }

    /**
     * Cluster slug => number of exposed tools, ordered by size.
     *
     * @param string[] $tool_names Ability names.
     * @return array<string,int>
     */
    private function avcf_mcp_cluster_counts( array $tool_names ) {
        $counts = [];
        foreach ( $tool_names as $name ) {
            if ( ! isset( $this->cluster_map[ $name ] ) ) {
                continue;
            }
            $slug            = $this->cluster_map[ $name ];
            $counts[ $slug ] = isset( $counts[ $slug ] ) ? $counts[ $slug ] + 1 : 1;
        }

        arsort( $counts );

        return $counts;
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
     *
     * A caller can narrow that surface for one request with the
     * X-Atarim-MCP-Clusters header; the clusters available are advertised in
     * the server description, which the adapter returns as `instructions`.
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

                $name    = $ability->get_name();
                $tools[] = $name;

                // Collected here, where the ability object is already in hand,
                // and applied to the listing in avcf_mcp_shape_tools_list().
                $short = isset( $meta['mcp']['short_description'] ) ? (string) $meta['mcp']['short_description'] : '';
                if ( '' === $short && method_exists( $ability, 'get_description' ) ) {
                    $short = $this->avcf_mcp_short_description( $ability->get_description() );
                }
                if ( '' !== $short ) {
                    $this->short_descriptions[ $name ] = $short;
                }
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

        // The catalogue advertises the whole surface, not the filtered one, so a
        // caller that narrowed too far can always see what else it could ask for.
        $this->cluster_counts = $this->avcf_mcp_cluster_counts( $allowed );
        $description          = 'Atarim AI action layer.' . $this->avcf_mcp_cluster_catalogue( $allowed );

        // Cluster filter. A site can expose 700+ tools; listing only the
        // clusters the caller named is what keeps that surface affordable.
        // Note this narrows tools/call for the same request too — harmless
        // because the header rides on tools/list alone, and safer than a
        // listing that disagrees with what the server will actually run.
        $requested = $this->avcf_mcp_requested_clusters();
        if ( ! empty( $requested ) ) {
            $selected = array_values( array_filter(
                $allowed,
                function( $name ) use ( $requested ) {
                    return isset( $this->cluster_map[ $name ] )
                        && in_array( $this->cluster_map[ $name ], $requested, true );
                }
            ) );

            // Every requested slug unknown (a typo, or a cluster whose plugin is
            // not active here) would otherwise hand back an empty tool list and
            // no way forward. Fall back to the full surface instead; the
            // catalogue in the response metadata shows what does exist.
            if ( ! empty( $selected ) ) {
                $allowed = $selected;
            }
        }

        // atarim/batch belongs to no one cluster: it is how a caller avoids
        // repeating any of the others once per item. Narrowing to a cluster
        // would hide it at exactly the moment it is needed — a caller that has
        // just found get-post-seo is about to call it forty times — so it rides
        // along with every listing, provided this site actually exposes it.
        if ( in_array( 'atarim/batch', $tools, true )
            && ! in_array( 'atarim/batch', $blocked, true )
            && ! in_array( 'atarim/batch', $allowed, true ) ) {
            $allowed[] = 'atarim/batch';
        }

        $this->requested_clusters = $requested;

        $adapter->create_server(
            'atarim-mcp-server',
            'atarim',
            'mcp',
            'Atarim MCP Server',
            $description,
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
     * Trim the tools/list payload on its way out.
     *
     * Two thirds of a full listing is detail the caller cannot use at the
     * moment it is choosing a tool:
     *
     *   - outputSchema, which describes a result the caller will read in full
     *     anyway. Dropping it here is presentation only: the adapter decides
     *     result wrapping from the ability's registered schema, which is
     *     untouched, and the schema is still there for a caller that asks a
     *     tool to run.
     *   - the body of each description, past the sentence that says which tool
     *     this is. X-Atarim-MCP-Detail: full brings it back.
     *
     * Annotations are deliberately left alone — the read-only and destructive
     * hints are what Atarim's approval gate reads to decide whether a call
     * needs a human first.
     *
     * @param mixed           $response Dispatch result.
     * @param WP_REST_Server  $server   REST server instance.
     * @param WP_REST_Request $request  Current request.
     * @return mixed
     */
    public function avcf_mcp_shape_tools_list( $response, $server, $request ) {
        if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
            return $response;
        }
        if ( ! $this->avcf_is_protected_mcp_route( $request->get_route() ) ) {
            return $response;
        }
        if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
            return $response;
        }

        $data = $response->get_data();
        if ( ! is_array( $data ) || $data === [] ) {
            return $response;
        }

        $full = $this->avcf_mcp_wants_full_descriptions();

        // A JSON-RPC batch comes back as a list of responses.
        if ( isset( $data[0] ) && is_array( $data[0] ) ) {
            $changed = false;
            foreach ( $data as $i => $entry ) {
                $new = $this->avcf_mcp_shape_listing( $entry, $full );
                if ( null !== $new ) { $data[ $i ] = $new; $changed = true; }
            }
            if ( $changed ) { $response->set_data( $data ); }
            return $response;
        }

        $new = $this->avcf_mcp_shape_listing( $data, $full );
        if ( null !== $new ) { $response->set_data( $new ); }

        return $response;
    }

    /**
     * Returns the rewritten entry, or null if it is not a tools/list result.
     *
     * @param mixed $entry JSON-RPC response entry.
     * @param bool  $full  Whether to keep full descriptions.
     * @return array|null
     */
    private function avcf_mcp_shape_listing( $entry, $full ) {
        if ( ! is_array( $entry ) || ! isset( $entry['result'] ) ) {
            return null;
        }

        // JsonRpcResponseBuilder casts every result to an object so an empty one
        // still serialises as {}, so this is an stdClass in practice.
        $result    = $entry['result'];
        $is_object = is_object( $result );
        $data      = $is_object ? get_object_vars( $result ) : $result;

        if ( ! is_array( $data ) || ! isset( $data['tools'] ) || ! is_array( $data['tools'] ) ) {
            return null;
        }

        foreach ( $data['tools'] as $i => $tool ) {
            $data['tools'][ $i ] = $this->avcf_mcp_shape_tool( $tool, $full );
        }

        // Travels with the listing so a caller that narrowed to one cluster can
        // still see the rest of the surface without a second call.
        $metadata = isset( $data['_metadata'] ) && is_array( $data['_metadata'] ) ? $data['_metadata'] : [];

        $metadata['clusters']           = (object) $this->cluster_counts;
        $metadata['clusters_requested'] = $this->requested_clusters;

        $data['_metadata'] = $metadata;
        $entry['result']   = $is_object ? (object) $data : $data;

        return $entry;
    }

    /**
     * The cluster and short-description maps re-keyed by MCP tool name, built
     * once per request rather than scanned per listed tool.
     *
     * @return array{descriptions:array<string,string>,clusters:array<string,string>}
     */
    private function avcf_mcp_folded_index() {
        if ( null !== $this->folded_index ) {
            return $this->folded_index;
        }

        $index = [ 'descriptions' => [], 'clusters' => [] ];

        foreach ( $this->short_descriptions as $ability_name => $short ) {
            $index['descriptions'][ $this->avcf_fold_tool_name( $ability_name ) ] = $short;
        }
        foreach ( $this->cluster_map as $ability_name => $slug ) {
            $index['clusters'][ $this->avcf_fold_tool_name( $ability_name ) ] = $slug;
        }

        $this->folded_index = $index;

        return $index;
    }

    /**
     * @param mixed $tool One entry of result.tools.
     * @param bool  $full Whether to keep the full description.
     * @return mixed
     */
    private function avcf_mcp_shape_tool( $tool, $full ) {
        if ( ! is_array( $tool ) || ! isset( $tool['name'] ) ) {
            return $tool;
        }

        unset( $tool['outputSchema'] );

        // Tool names arrive folded ("atarim-list-content"); our maps are keyed
        // by ability name ("atarim/list-content").
        $folded = $this->avcf_mcp_folded_index();
        $name   = $tool['name'];

        if ( ! $full && isset( $folded['descriptions'][ $name ] ) ) {
            $tool['description'] = $folded['descriptions'][ $name ];
        }

        if ( isset( $folded['clusters'][ $name ] ) ) {
            $tool['cluster'] = $folded['clusters'][ $name ];
        }

        return $tool;
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
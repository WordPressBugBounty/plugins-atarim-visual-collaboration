<?php
/**
 * Atarim DoIt — cluster class loader.
 *
 * Centralises every third-party / cluster require_once so the main plugin
 * file stays stable as clusters are added or removed. Loading flow is
 * UNCHANGED from when these lived in the main file: detectors load eagerly
 * (phase 1); ability classes load only when the Abilities API + MCP Adapter
 * are present, with the base class first, then the MCP layer is bootstrapped
 * (phase 2). To add a cluster, add its require_once lines here — the main plugin
 * file does not change.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

// ---------------------------------------------------------------------------
// Phase 1: detectors — eager, no AVCF_Abilities_Base dependency.
// ---------------------------------------------------------------------------
// WP Activity Log integration — third-party partner integration.
// (Abilities class is required below alongside the rest of the MCP layer.)
require_once( AVCF_PLUGIN_DIR . 'third-party/wp-activity-log/class-avcf-wpal-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/wp-activity-log/class-avcf-wpal-stats.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/wp-activity-log/class-avcf-wpal-rest.php' );

// WooCommerce integration — detector is safe to load eagerly (no abilities-base dependency).
// Ability classes themselves are loaded inside the MCP conditional block below.
require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-detector.php' );

// ACF integration — detector is safe to load eagerly (no abilities-base dependency).
// Ability classes themselves are loaded inside the MCP conditional block below.
require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/acf/class-avcf-acf-detector.php' );

// SEO integrations (Yoast / Rank Math / AIOSEO) — detectors load eagerly.
require_once( AVCF_PLUGIN_DIR . 'third-party/seo/yoast/class-avcf-yoast-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/seo/rank-math/class-avcf-rankmath-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/seo/aioseo/class-avcf-aioseo-detector.php' );

// Elementor integration — detector loads eagerly.
require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/elementor/class-avcf-elementor-detector.php' );

// ShortPixel image optimizer — detector loads eagerly.
require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/shortpixel/class-avcf-shortpixel-detector.php' );

// EWWW image optimizer — detector loads eagerly.
require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/ewww/class-avcf-ewww-detector.php' );

// reSmush.it image optimizer — detector loads eagerly.
require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/resmushit/class-avcf-resmushit-detector.php' );

// Smush image optimizer — detector loads eagerly.
require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/smush/class-avcf-smush-detector.php' );

// Optimole (CDN/offload) — detector loads eagerly.
require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/optimole/class-avcf-optimole-detector.php' );

// Field-framework integrations (Wave 3) — detectors load eagerly.
require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/metabox/class-avcf-metabox-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/jetengine/class-avcf-jetengine-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/pods/class-avcf-pods-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/acpt/class-avcf-acpt-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/ase/class-avcf-ase-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/bricks/class-avcf-bricks-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/divi/class-avcf-divi-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/wpbakery/class-avcf-wpbakery-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/breakdance/class-avcf-breakdance-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/etch/class-avcf-etch-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/mosaic/class-avcf-mosaic-detector.php' );

// Forms detectors (standalone per-plugin clusters).
require_once( AVCF_PLUGIN_DIR . 'third-party/forms/gravity-forms/class-avcf-gravity-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/forms/wpforms/class-avcf-wpforms-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/forms/fluent-forms/class-avcf-fluent-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/forms/formidable/class-avcf-formidable-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/forms/forminator/class-avcf-forminator-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/forms/ninja-forms/class-avcf-ninja-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/forms/contact-form-7/class-avcf-cf7-detector.php' );
require_once( AVCF_PLUGIN_DIR . 'third-party/flamingo/class-avcf-flamingo-detector.php' );

// Backup detectors (standalone per-plugin clusters).
require_once( AVCF_PLUGIN_DIR . 'third-party/backup/jetbackup/class-avcf-jetbackup-detector.php' );

// ---------------------------------------------------------------------------
// Phase 2: ability classes + MCP bootstrap — only when the Abilities API + MCP
// Adapter exist. Base class loads first; everything else extends it. This is the
// single place the availability check is made.
// ---------------------------------------------------------------------------
if ( function_exists('wp_get_abilities') && class_exists('\WP\MCP\Core\McpAdapter') ) {
    // Ability category base class + core categories.
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-base.php' );

    // WooCommerce ability classes — extend AVCF_Abilities_Base, must load AFTER the base.
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-products.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-variations.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-product-relations.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-orders.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-customers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-coupons.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-shipping.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-tax.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-checkout-email.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-attributes.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-catalog.php' );

    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-content.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-gutenberg.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-plugins.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-themes.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-theme-files.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-readonly.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-execute-php.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-core.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-taxonomies.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-users.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-settings.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-media.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-metadata.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-navigation.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-templates.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-global-styles.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-patterns.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-block-navigation.php' );
    require_once( AVCF_PLUGIN_DIR . 'doit/abilities/class-avcf-abilities-cache.php' );

    // Forms cluster — unified surface over WPForms / Gravity / Forminator /
    // Ninja / Fluent / Formidable / CF7. Load order matters: interface →
    // abstract → per-plugin detectors+adapters → registry → orchestrator.
    // Forms: Gravity Forms (standalone cluster).
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/gravity-forms/class-avcf-gravity-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/gravity-forms/class-avcf-abilities-gravity.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/gravity-forms/class-avcf-abilities-gravity-pro.php' );
    // Forms: WPForms (standalone cluster).
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/wpforms/class-avcf-wpforms-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/wpforms/class-avcf-abilities-wpforms.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/wpforms/class-avcf-abilities-wpforms-pro.php' );
    // Forms: Fluent Forms (standalone cluster).
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/fluent-forms/class-avcf-fluent-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/fluent-forms/class-avcf-abilities-fluent.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/fluent-forms/class-avcf-abilities-fluent-pro.php' );
    // Forms: Formidable Forms (standalone cluster).
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/formidable/class-avcf-formidable-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/formidable/class-avcf-abilities-formidable.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/formidable/class-avcf-abilities-formidable-pro.php' );
    // Forms: Forminator (standalone cluster).
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/forminator/class-avcf-forminator-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/forminator/class-avcf-abilities-forminator.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/forminator/class-avcf-abilities-forminator-pro.php' );
    // Forms: Ninja Forms (standalone cluster).
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/ninja-forms/class-avcf-ninja-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/ninja-forms/class-avcf-abilities-ninja.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/ninja-forms/class-avcf-abilities-ninja-pro.php' );
    // Forms: Contact Form 7 (standalone cluster, config-only).
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/contact-form-7/class-avcf-cf7-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/contact-form-7/class-avcf-abilities-cf7.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/forms/contact-form-7/class-avcf-abilities-cf7-pro.php' );
    // Forms: Flamingo (standalone cluster — CF7's companion entry store).
    require_once( AVCF_PLUGIN_DIR . 'third-party/flamingo/class-avcf-flamingo-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/flamingo/class-avcf-abilities-flamingo.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/flamingo/class-avcf-abilities-flamingo-pro.php' );

    // Third-party ability categories.
    require_once( AVCF_PLUGIN_DIR . 'third-party/ecommerce/woocommerce/class-avcf-wc-abilities.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/wp-activity-log/class-avcf-wpal-abilities.php' );

    // ACF ability classes — extend AVCF_Abilities_Base, must load AFTER the base.
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/acf/class-avcf-acf-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/acf/class-avcf-acf-field-groups.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/acf/class-avcf-acf-content-models.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/acf/class-avcf-acf-abilities.php' );

    // SEO ability classes — extend AVCF_Abilities_Base, must load AFTER the base.
    require_once( AVCF_PLUGIN_DIR . 'third-party/seo/yoast/class-avcf-abilities-yoast.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/seo/rank-math/class-avcf-abilities-rankmath.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/seo/aioseo/class-avcf-abilities-aioseo.php' );

    // Elementor ability classes — extend AVCF_Abilities_Base, must load AFTER the base.
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/elementor/class-avcf-elementor-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/elementor/class-avcf-abilities-elementor.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/elementor/class-avcf-abilities-elementor-pro.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/shortpixel/class-avcf-shortpixel-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/shortpixel/class-avcf-abilities-shortpixel.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/ewww/class-avcf-ewww-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/ewww/class-avcf-abilities-ewww.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/resmushit/class-avcf-resmushit-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/resmushit/class-avcf-abilities-resmushit.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/smush/class-avcf-smush-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/smush/class-avcf-abilities-smush.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/optimole/class-avcf-optimole-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/image-optimizer/optimole/class-avcf-abilities-optimole.php' );

    // Field-framework ability classes (Wave 3) — extend AVCF_Abilities_Base, load AFTER the base.
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/metabox/class-avcf-metabox-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/metabox/class-avcf-abilities-metabox.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/jetengine/class-avcf-jetengine-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/jetengine/class-avcf-abilities-jetengine.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/pods/class-avcf-pods-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/pods/class-avcf-abilities-pods.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/acpt/class-avcf-acpt-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/acpt/class-avcf-abilities-acpt.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/ase/class-avcf-ase-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/content-model/ase/class-avcf-abilities-ase.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/bricks/class-avcf-bricks-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/bricks/class-avcf-abilities-bricks.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/bricks/class-avcf-abilities-bricks-pro.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/divi/class-avcf-divi-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/divi/class-avcf-abilities-divi.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/divi/class-avcf-abilities-divi-pro.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/wpbakery/class-avcf-wpbakery-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/wpbakery/class-avcf-abilities-wpbakery.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/wpbakery/class-avcf-abilities-wpbakery-pro.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/breakdance/class-avcf-breakdance-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/breakdance/class-avcf-abilities-breakdance.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/breakdance/class-avcf-abilities-breakdance-pro.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/etch/class-avcf-etch-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/etch/class-avcf-abilities-etch.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/etch/class-avcf-abilities-etch-pro.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/mosaic/class-avcf-mosaic-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/mosaic/class-avcf-abilities-mosaic.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/page-builder/mosaic/class-avcf-abilities-mosaic-pro.php' );

    // Backup: JetBackup (standalone cluster). Helpers + per-area ability classes;
    // all extend AVCF_Abilities_Base and gate on the JetBackup detector.
    require_once( AVCF_PLUGIN_DIR . 'third-party/backup/jetbackup/class-avcf-jetbackup-helpers.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/backup/jetbackup/class-avcf-abilities-jetbackup-backups.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/backup/jetbackup/class-avcf-abilities-jetbackup-restore.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/backup/jetbackup/class-avcf-abilities-jetbackup-jobs.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/backup/jetbackup/class-avcf-abilities-jetbackup-schedules.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/backup/jetbackup/class-avcf-abilities-jetbackup-destinations.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/backup/jetbackup/class-avcf-abilities-jetbackup-queue.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/backup/jetbackup/class-avcf-abilities-jetbackup-settings.php' );
    require_once( AVCF_PLUGIN_DIR . 'third-party/backup/jetbackup/class-avcf-abilities-jetbackup-system.php' );

    // -----------------------------------------------------------------------
    // MCP bootstrap — orchestrator + hooks. Runs at load time, inside the same
    // guard, so the availability check lives in exactly one place.
    // -----------------------------------------------------------------------
    require_once( AVCF_PLUGIN_DIR . 'doit/class-avcf-mcp.php' );

    $avcf_mcp = new AVCF_MCP();

    // Register ability category and abilities at file load time.
    // wp_abilities_api_init fires between rest_api_init priority 3-6,
    // so hooks must be registered before rest_api_init fires — i.e. at file load time.
    add_action( 'wp_abilities_api_categories_init', function() {
        wp_register_ability_category( 'atarim', [
            'label'       => 'Atarim',
            'description' => 'Atarim AI action layer abilities.',
        ]);
    });

    add_action( 'wp_abilities_api_init', [ $avcf_mcp, 'avcf_mcp_register_abilities' ] );

    // Initialize MCP Adapter — hooks rest_api_init internally to fire mcp_adapter_init.
    \WP\MCP\Core\McpAdapter::instance();
}
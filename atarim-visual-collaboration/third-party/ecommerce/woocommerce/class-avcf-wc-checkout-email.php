<?php
/**
 * WooCommerce — Checkout settings and email templates.
 *
 * Checkout settings: WooCommerce stores them as discrete wp_options. Single
 * read returns the standard checkout flags; updates would be a separate
 * cluster of dedicated abilities if needed (checkout sub-pages, conditional
 * fields, etc. are deeper than option flags).
 *
 * Email templates: WooCommerce ships several transactional emails
 * (new-order, customer-processing-order, customer-completed-order,
 * customer-refunded-order, etc.). Each has subject / heading /
 * additional_content / enabled flags stored as wp_options. Email
 * BODY templates beyond these settings are theme/file work — out
 * of scope for MCP.
 *
 * Exposed abilities:
 *   atarim/get-checkout-settings    Read checkout flags.
 *   atarim/list-email-templates     All WC emails with status + subject summary.
 *   atarim/get-email-template       Full settings for one email by id.
 *   atarim/update-email-template    Write subject / heading / additional_content / enabled.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_WC_Checkout_Email extends AVCF_Abilities_Base {

    /**
     * @var AVCF_WC_Detector
     */
    private $detector;

    /**
     * Checkout-related wp_options surfaced by get-checkout-settings.
     * Read-only. To write, dedicated abilities would be needed.
     */
    private $checkout_options = [
        'woocommerce_enable_guest_checkout',
        'woocommerce_enable_checkout_login_reminder',
        'woocommerce_enable_signup_and_login_from_checkout',
        'woocommerce_enable_myaccount_registration',
        'woocommerce_registration_generate_username',
        'woocommerce_registration_generate_password',
        'woocommerce_checkout_company_field',
        'woocommerce_checkout_phone_field',
        'woocommerce_checkout_address_2_field',
        'woocommerce_checkout_highlight_required_fields',
        'woocommerce_checkout_terms_and_conditions_checkbox_text',
        'woocommerce_checkout_privacy_policy_text',
        'woocommerce_force_ssl_checkout',
        'woocommerce_unforce_ssl_checkout',
        'woocommerce_cart_page_id',
        'woocommerce_checkout_page_id',
        'woocommerce_myaccount_page_id',
        'woocommerce_terms_page_id',
    ];

    public function __construct() {
        $this->detector = new AVCF_WC_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_wc_is_available() ) {
            return;
        }

        // ---- get-checkout-settings ----
        wp_register_ability( 'atarim/get-checkout-settings', [
            'label'               => 'Get Checkout Settings',
            'description'         => 'Returns WooCommerce checkout-related settings: guest checkout, login reminders, registration on checkout, optional fields (company, phone, address 2), required-field highlighting, terms/privacy text, force SSL, and the IDs of the cart, checkout, my-account, and terms pages. Read-only in this round.',
            'category'            => 'atarim',
            'input_schema'        => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'settings' => [ 'type' => 'object' ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $settings = [];
                foreach ( $this->checkout_options as $opt ) {
                    $settings[ $opt ] = get_option( $opt, '' );
                }
                return [
                    'success'  => true,
                    'settings' => $settings,
                    'message'  => 'OK.',
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- list-email-templates ----
        wp_register_ability( 'atarim/list-email-templates', [
            'label'               => 'List Email Templates',
            'description'         => 'Returns all WooCommerce email templates registered on the site (new-order, customer-processing-order, customer-completed-order, customer-refunded-order, customer-on-hold-order, customer-note, customer-reset-password, customer-new-account, plus any added by plugins). Each entry shows id, title, recipient(s), enabled flag, and current subject. Use get-email-template for full detail; update-email-template to change subject / heading / additional_content / enabled.',
            'category'            => 'atarim',
            'input_schema'        => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [ 'type' => 'boolean' ],
                    'total'     => [ 'type' => 'integer' ],
                    'templates' => [ 'type' => 'array' ],
                    'message'   => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
                    return [ 'success' => false, 'message' => 'WooCommerce mailer not available.' ];
                }
                $emails = WC()->mailer()->get_emails();
                $rows = [];
                foreach ( $emails as $email ) {
                    $rows[] = [
                        'id'         => (string) $email->id,
                        'title'      => (string) $email->get_title(),
                        'recipient'  => (string) ( $email->is_customer_email() ? $email->get_recipient() : ( $email->recipient ?? '' ) ),
                        'is_customer_email' => (bool) $email->is_customer_email(),
                        'enabled'    => (bool) $email->is_enabled(),
                        'subject'    => (string) $email->get_subject(),
                        'heading'    => (string) $email->get_heading(),
                    ];
                }
                return [
                    'success'   => true,
                    'total'     => count( $rows ),
                    'templates' => $rows,
                    'message'   => sprintf( '%d email template(s) registered.', count( $rows ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- get-email-template ----
        wp_register_ability( 'atarim/get-email-template', [
            'label'               => 'Get Email Template',
            'description'         => 'Returns full settings for one WooCommerce email by id (e.g. "customer_processing_order"). Includes enabled flag, recipient, subject, heading, additional_content, email_type (html/plain/multipart), and any custom fields the email exposes. To find ids use list-email-templates.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'string',
                        'description' => 'Email id, e.g. "customer_processing_order". See list-email-templates for available ids.',
                        'minLength'   => 1,
                    ],
                ],
                'required' => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'  => [ 'type' => 'boolean' ],
                    'template' => [ 'type' => 'object' ],
                    'message'  => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (string) $input['id'] : '';
                if ( $id === '' ) {
                    return [ 'success' => false, 'message' => 'id is required.' ];
                }
                if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
                    return [ 'success' => false, 'message' => 'WooCommerce mailer not available.' ];
                }
                $emails = WC()->mailer()->get_emails();

                $email = null;
                foreach ( $emails as $e ) {
                    if ( (string) $e->id === $id ) {
                        $email = $e;
                        break;
                    }
                }
                if ( ! $email ) {
                    return [ 'success' => false, 'message' => sprintf( 'Email template "%s" not found.', $id ) ];
                }

                $template = [
                    'id'                 => (string) $email->id,
                    'title'              => (string) $email->get_title(),
                    'description'        => (string) $email->get_description(),
                    'recipient'          => (string) ( $email->is_customer_email() ? $email->get_recipient() : ( $email->recipient ?? '' ) ),
                    'is_customer_email'  => (bool) $email->is_customer_email(),
                    'enabled'            => (bool) $email->is_enabled(),
                    'subject'            => (string) $email->get_subject(),
                    'heading'            => (string) $email->get_heading(),
                    'additional_content' => (string) ( method_exists( $email, 'get_additional_content' ) ? $email->get_additional_content() : '' ),
                    'email_type'         => (string) $email->get_email_type(),
                ];

                return [ 'success' => true, 'template' => $template, 'message' => 'OK.' ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );

        // ---- update-email-template ----
        wp_register_ability( 'atarim/update-email-template', [
            'label'               => 'Update Email Template',
            'description'         => 'Update WooCommerce email template settings. Partial — pass any subset of enabled/subject/heading/additional_content/email_type. Writes go to wp_options as woocommerce_email_{id}_{setting}. Does NOT modify the email body template files — for layout changes, edit your theme\'s woocommerce/emails/ overrides.',
            'category'            => 'atarim',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'string',
                        'description' => 'Email id from list-email-templates.',
                        'minLength'   => 1,
                    ],
                    'enabled' => [ 'type' => 'boolean', 'description' => 'Enable / disable this email.' ],
                    'subject' => [ 'type' => 'string' ],
                    'heading' => [ 'type' => 'string' ],
                    'additional_content' => [ 'type' => 'string', 'description' => 'Text appended below the main email body (HTML/plain).' ],
                    'email_type' => [ 'type' => 'string', 'enum' => [ 'plain', 'html', 'multipart' ] ],
                ],
                'required' => [ 'id' ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'id'      => [ 'type' => 'string' ],
                    'updated' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'message' => [ 'type' => 'string' ],
                ],
                'required' => [ 'success', 'message' ],
            ],
            'execute_callback'    => function( $input = [] ) {
                $id = isset( $input['id'] ) ? (string) $input['id'] : '';
                if ( $id === '' ) {
                    return [ 'success' => false, 'message' => 'id is required.' ];
                }
                if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
                    return [ 'success' => false, 'message' => 'WooCommerce mailer not available.' ];
                }
                $emails = WC()->mailer()->get_emails();
                $email = null;
                foreach ( $emails as $e ) {
                    if ( (string) $e->id === $id ) {
                        $email = $e;
                        break;
                    }
                }
                if ( ! $email ) {
                    return [ 'success' => false, 'message' => sprintf( 'Email template "%s" not found.', $id ) ];
                }

                // WC stores email settings as a single serialized array per email:
                // woocommerce_email_{id}_settings (some) or via option_name = "woocommerce_{id}_settings".
                // The canonical pattern used by WC_Settings_API is per-instance settings,
                // stored under $email->get_option_key() which returns "woocommerce_{id}_settings".
                $option_key = method_exists( $email, 'get_option_key' ) ? $email->get_option_key() : ( 'woocommerce_' . $email->id . '_settings' );
                $settings = get_option( $option_key );
                if ( ! is_array( $settings ) ) {
                    $settings = [];
                }

                $updated = [];

                if ( array_key_exists( 'enabled', $input ) ) {
                    $settings['enabled'] = $input['enabled'] ? 'yes' : 'no';
                    $updated[] = 'enabled';
                }
                if ( array_key_exists( 'subject', $input ) ) {
                    $settings['subject'] = (string) $input['subject'];
                    $updated[] = 'subject';
                }
                if ( array_key_exists( 'heading', $input ) ) {
                    $settings['heading'] = (string) $input['heading'];
                    $updated[] = 'heading';
                }
                if ( array_key_exists( 'additional_content', $input ) ) {
                    $settings['additional_content'] = (string) $input['additional_content'];
                    $updated[] = 'additional_content';
                }
                if ( array_key_exists( 'email_type', $input ) ) {
                    $settings['email_type'] = sanitize_key( (string) $input['email_type'] );
                    $updated[] = 'email_type';
                }

                if ( empty( $updated ) ) {
                    return [ 'success' => false, 'id' => $id, 'updated' => [], 'message' => 'No fields provided to update.' ];
                }

                update_option( $option_key, $settings );

                return [
                    'success' => true,
                    'id'      => $id,
                    'updated' => $updated,
                    'message' => sprintf( 'Updated email "%s": %s.', $id, implode( ', ', $updated ) ),
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_woocommerce' );
            },
            'meta' => [
                'mcp' => [ 'public' => true, 'type' => 'tool' ],
                'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }
}

<?php
/**
 * Flamingo — power MCP abilities (standalone cluster, -pro file).
 *
 * Management depth for Flamingo: inbound delete + bulk-delete, a programmatic
 * add-inbound bridge (Flamingo_Inbound_Message::add — for custom/non-CF7 sources
 * or re-imports), the address book (flamingo_contact read + delete), and a
 * best-effort outbound-message listing. add-inbound is experimental; deletes are
 * confirm-gated. Flamingo-cap permissions with a manage_options fallback. Built
 * on Flamingo's storage model; not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Abilities_Flamingo_Pro extends AVCF_Abilities_Base {

    /** @var AVCF_Flamingo_Detector */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_Flamingo_Detector();
    }

    public function register() {
        if ( ! $this->detector->avcf_flamingo_is_available() ) {
            return;
        }
        $this->register_delete_inbound();
        $this->register_bulk_delete_inbound();
        $this->register_add_inbound();
        $this->register_list_contacts();
        $this->register_get_contact();
        $this->register_delete_contact();
        $this->register_list_outbound();
    }

    /* ------------------------------ shared ----------------------------- */

    private function ro_meta() { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ]; }
    private function write_meta( $d = false ) { return [ 'mcp' => [ 'public' => true, 'type' => 'tool' ], 'annotations' => [ 'readonly' => false, 'destructive' => (bool) $d, 'idempotent' => false ] ]; }
    private function std() { return [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ]; }

    public function can_edit() { return current_user_can( 'flamingo_edit_inbound_messages' ) || current_user_can( 'flamingo_edit_inbound_message' ) || current_user_can( 'manage_options' ); }
    public function can_delete() { return current_user_can( 'flamingo_delete_inbound_messages' ) || current_user_can( 'flamingo_delete_inbound_message' ) || current_user_can( 'manage_options' ); }
    public function can_contacts() { return current_user_can( 'flamingo_edit_contacts' ) || current_user_can( 'flamingo_edit_contact' ) || current_user_can( 'manage_options' ); }
    public function can_delete_contact() { return current_user_can( 'flamingo_delete_contact' ) || current_user_can( 'flamingo_edit_contacts' ) || current_user_can( 'manage_options' ); }

    /* --------------------------- delete-inbound ------------------------ */

    private function register_delete_inbound() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-delete-inbound', [
            'label' => 'Delete Flamingo Inbound Message', 'category' => 'atarim',
            'description' => 'Permanently delete a stored inbound message. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'message_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'message_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $id = (int) $input['message_id'];
                $p = get_post( $id );
                if ( ! $p || $p->post_type !== AVCF_Flamingo_Helpers::INBOUND_PT ) { return [ 'success' => false, 'message' => 'Inbound message not found.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to permanently delete this message.' ]; }
                $r = wp_delete_post( $id, true );
                return $r ? [ 'success' => true, 'message' => sprintf( 'Message %d deleted.', $id ) ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_delete(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ------------------------ bulk-delete-inbound ---------------------- */

    private function register_bulk_delete_inbound() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-bulk-delete-inbound', [
            'label' => 'Bulk Delete Flamingo Inbound Messages', 'category' => 'atarim',
            'description' => 'Permanently delete multiple inbound messages. Provide message_ids[]. DESTRUCTIVE — dry run unless confirm:true.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'message_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'minItems' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'message_ids' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'deleted' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                $ids = array_values( array_filter( array_map( 'intval', (array) $input['message_ids'] ) ) );
                if ( empty( $ids ) ) { return [ 'success' => false, 'message' => 'No valid message ids.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => sprintf( 'Dry run: re-call with confirm:true to permanently delete %d message(s).', count( $ids ) ) ]; }
                $deleted = 0;
                foreach ( $ids as $id ) {
                    $p = get_post( $id );
                    if ( $p && $p->post_type === AVCF_Flamingo_Helpers::INBOUND_PT && wp_delete_post( $id, true ) ) { $deleted++; }
                }
                return [ 'success' => true, 'deleted' => $deleted, 'message' => sprintf( '%d message(s) deleted.', $deleted ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_delete(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ----------------------------- add-inbound ------------------------- */

    private function register_add_inbound() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-add-inbound', [
            'label' => 'Add Flamingo Inbound Message', 'category' => 'atarim',
            'description' => 'Store a new inbound message via Flamingo_Inbound_Message::add (bridge for custom/non-CF7 sources or re-imports). EXPERIMENTAL. Provide channel (slug), subject, from_name, from_email, and fields (map of field => value). Returns the new message id.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'channel' => [ 'type' => 'string', 'minLength' => 1 ], 'subject' => [ 'type' => 'string' ], 'from_name' => [ 'type' => 'string' ], 'from_email' => [ 'type' => 'string' ], 'fields' => [ 'type' => 'object' ] ], 'required' => [ 'channel', 'fields' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'message_id' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! class_exists( 'Flamingo_Inbound_Message' ) || ! method_exists( 'Flamingo_Inbound_Message', 'add' ) ) { return [ 'success' => false, 'message' => 'Flamingo_Inbound_Message::add unavailable.' ]; }
                $name  = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '';
                $email = isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : '';
                $args = [
                    'channel'    => sanitize_title( $input['channel'] ),
                    'subject'    => isset( $input['subject'] ) ? sanitize_text_field( $input['subject'] ) : '',
                    'from'       => trim( sprintf( '%s <%s>', $name, $email ) ),
                    'from_name'  => $name,
                    'from_email' => $email,
                    'fields'     => is_array( $input['fields'] ) ? $input['fields'] : [],
                ];
                $msg = Flamingo_Inbound_Message::add( $args );
                $id = ( is_object( $msg ) && method_exists( $msg, 'id' ) ) ? (int) $msg->id() : 0;
                if ( ! $id && is_object( $msg ) && isset( $msg->id ) ) { $id = (int) $msg->id; }
                if ( ! $id ) { return [ 'success' => false, 'message' => 'Add failed (or id not returned by this Flamingo version).' ]; }
                return [ 'success' => true, 'message_id' => $id, 'message' => sprintf( 'Inbound message %d stored (experimental).', $id ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit(); },
            'meta' => $this->write_meta( false ),
        ] );
    }

    /* ---------------------------- list-contacts ------------------------ */

    private function register_list_contacts() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-list-contacts', [
            'label' => 'List Flamingo Contacts', 'category' => 'atarim',
            'description' => 'List the Flamingo address book (flamingo_contact): { id, email, name, tags[] }. Optional search (email/name), limit, offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'search' => [ 'type' => 'string' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'contacts' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $qa = [ 'post_type' => AVCF_Flamingo_Helpers::CONTACT_PT, 'post_status' => 'any', 'posts_per_page' => isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50, 'offset' => isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0, 'orderby' => 'title', 'order' => 'ASC' ];
                if ( isset( $input['search'] ) && $input['search'] !== '' ) { $qa['s'] = (string) $input['search']; }
                $q = new WP_Query( $qa );
                $out = [];
                foreach ( $q->posts as $p ) { $out[] = $self->map_contact( $p ); }
                return [ 'success' => true, 'contacts' => $out, 'total' => (int) $q->found_posts, 'message' => sprintf( '%d contact(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_contacts(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    public function map_contact( $p ) {
        $pid = (int) $p->ID;
        $email = (string) get_post_meta( $pid, '_email', true );
        $name  = (string) get_post_meta( $pid, '_name', true );
        if ( $email === '' ) { $email = (string) $p->post_title; }
        $tags = [];
        if ( taxonomy_exists( 'flamingo_contact_tag' ) ) {
            $terms = wp_get_object_terms( $pid, 'flamingo_contact_tag' );
            if ( is_array( $terms ) && ! is_wp_error( $terms ) ) { foreach ( $terms as $t ) { $tags[] = (string) $t->name; } }
        }
        return [ 'id' => $pid, 'email' => $email, 'name' => $name, 'tags' => $tags ];
    }

    /* ----------------------------- get-contact ------------------------- */

    private function register_get_contact() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-get-contact', [
            'label' => 'Get Flamingo Contact', 'category' => 'atarim',
            'description' => 'A single address-book contact by id: { id, email, name, tags[], history_count } (history_count = inbound messages from this email).',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'contact_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'contact_id' ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'contact' => [ 'type' => 'object' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) use ( $self ) {
                $p = get_post( (int) $input['contact_id'] );
                if ( ! $p || $p->post_type !== AVCF_Flamingo_Helpers::CONTACT_PT ) { return [ 'success' => false, 'message' => 'Contact not found.' ]; }
                $contact = $self->map_contact( $p );
                $count = 0;
                if ( $contact['email'] !== '' ) {
                    $cq = new WP_Query( [ 'post_type' => AVCF_Flamingo_Helpers::INBOUND_PT, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => [ [ 'key' => '_from_email', 'value' => $contact['email'] ] ] ] );
                    $count = (int) $cq->found_posts;
                }
                $contact['history_count'] = $count;
                return [ 'success' => true, 'contact' => $contact, 'message' => 'OK.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_contacts(); },
            'meta' => $this->ro_meta(),
        ] );
    }

    /* ---------------------------- delete-contact ----------------------- */

    private function register_delete_contact() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-delete-contact', [
            'label' => 'Delete Flamingo Contact', 'category' => 'atarim',
            'description' => 'Permanently delete an address-book contact. DESTRUCTIVE — dry run unless confirm:true. (Inbound messages are not affected.)',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'contact_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'confirm' => [ 'type' => 'boolean', 'default' => false ] ], 'required' => [ 'contact_id' ], 'additionalProperties' => false ],
            'output_schema'=> $this->std(),
            'execute_callback' => function( $input = [] ) {
                $id = (int) $input['contact_id'];
                $p = get_post( $id );
                if ( ! $p || $p->post_type !== AVCF_Flamingo_Helpers::CONTACT_PT ) { return [ 'success' => false, 'message' => 'Contact not found.' ]; }
                if ( empty( $input['confirm'] ) ) { return [ 'success' => true, 'message' => 'Dry run: re-call with confirm:true to permanently delete this contact.' ]; }
                $r = wp_delete_post( $id, true );
                return $r ? [ 'success' => true, 'message' => sprintf( 'Contact %d deleted.', $id ) ] : [ 'success' => false, 'message' => 'Delete failed.' ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_delete_contact(); },
            'meta' => $this->write_meta( true ),
        ] );
    }

    /* ---------------------------- list-outbound ------------------------ */

    private function register_list_outbound() {
        $self = $this;
        wp_register_ability( 'atarim/flamingo-list-outbound', [
            'label' => 'List Flamingo Outbound Messages', 'category' => 'atarim',
            'description' => 'List logged outbound messages (flamingo_outbound), if present: { id, submitted_at, subject, to }. Best-effort — not all setups record outbound. Paged via limit/offset.',
            'input_schema' => [ 'type' => 'object', 'properties' => [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ] ], 'additionalProperties' => false ],
            'output_schema'=> [ 'type' => 'object', 'properties' => [ 'success' => [ 'type' => 'boolean' ], 'messages' => [ 'type' => 'array' ], 'total' => [ 'type' => 'integer' ], 'message' => [ 'type' => 'string' ] ], 'required' => [ 'success', 'message' ] ],
            'execute_callback' => function( $input = [] ) {
                if ( ! post_type_exists( 'flamingo_outbound' ) ) { return [ 'success' => true, 'messages' => [], 'total' => 0, 'message' => 'Outbound messages are not recorded on this site.' ]; }
                $q = new WP_Query( [ 'post_type' => 'flamingo_outbound', 'post_status' => 'any', 'posts_per_page' => isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50, 'offset' => isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0, 'orderby' => 'date', 'order' => 'DESC' ] );
                $out = [];
                foreach ( $q->posts as $p ) {
                    $pid = (int) $p->ID;
                    $out[] = [ 'id' => $pid, 'submitted_at' => (string) $p->post_date, 'subject' => (string) get_post_meta( $pid, '_subject', true ), 'to' => (string) get_post_meta( $pid, '_to', true ) ];
                }
                return [ 'success' => true, 'messages' => $out, 'total' => (int) $q->found_posts, 'message' => sprintf( '%d outbound message(s).', count( $out ) ) ];
            },
            'permission_callback' => function() use ( $self ) { return $self->can_edit(); },
            'meta' => $this->ro_meta(),
        ] );
    }
}

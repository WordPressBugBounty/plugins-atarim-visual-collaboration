<?php
/**
 * Flamingo detector.
 *
 * Flamingo is a standalone message-storage plugin (the de-facto entry store for
 * Contact Form 7, but independently installable and form-agnostic). Detected by
 * its inbound-message class / custom post type. This cluster reads Flamingo by
 * channel and offers a CF7-aware convenience: resolving a CF7 form_id to its
 * Flamingo channel via the form's _flamingo meta.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class AVCF_Flamingo_Detector {

    public function avcf_flamingo_is_available() {
        return class_exists( 'Flamingo_Inbound_Message' ) || post_type_exists( 'flamingo_inbound' );
    }
}

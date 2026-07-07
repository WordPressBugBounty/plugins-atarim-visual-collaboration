<?php
/**
 * Wraps WP Activity Log's Occurrences_Entity to expose dashboard statistics.
 *
 * This is the single data layer for WPAL stats. Both the REST endpoint
 * (Atarim dashboard) and the MCP abilities (DoIt AI layer) consume this
 * same class, so the integration logic lives in one place.
 *
 * Note: WPAL's get_last24_records('total'|'logins'|'changes') returns
 * the count of events for the previous calendar day (00:00:00 to 23:59:59
 * yesterday), not a rolling 24 hour window. This is WPAL's own behaviour;
 * we surface it as-is.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined('ABSPATH') ) {
    exit; // Exit if accessed directly
}

class AVCF_WPAL_Stats {

    /**
     * Supported stat types accepted by WPAL.
     */
    const TYPES = array( 'total', 'logins', 'changes' );

    /**
     * @var AVCF_WPAL_Detector
     */
    private $detector;

    public function __construct() {
        $this->detector = new AVCF_WPAL_Detector();
    }

    /**
     * Returns yesterday's event counts: total, logins, and plugin/theme changes.
     *
     * Returns an array with WPAL availability info plus the three counts.
     * When WPAL is not available, counts are 0 and 'available' is false so
     * callers can render a neutral state without extra branching.
     *
     * @return array
     */
    public function avcf_wpal_get_yesterday_counts() {
        $result = array(
            'available' => $this->detector->avcf_wpal_is_available(),
            'edition'   => $this->detector->avcf_wpal_edition(),
            'window'    => 'yesterday',
            'counts'    => array(
                'total'   => 0,
                'logins'  => 0,
                'changes' => 0,
            ),
        );

        if ( ! $result['available'] ) {
            return $result;
        }

        foreach ( self::TYPES as $type ) {
            $result['counts'][ $type ] = $this->avcf_wpal_count_for( $type );
        }

        return $result;
    }

    /**
     * Returns yesterday's filtered log viewer links for each category.
     *
     * Premium-only. Returns an array of three links when Premium is active,
     * or an empty array of links plus a fallback URL otherwise.
     *
     * @return array
     */
    public function avcf_wpal_get_yesterday_links() {
        $result = array(
            'available' => $this->detector->avcf_wpal_is_available(),
            'edition'   => $this->detector->avcf_wpal_edition(),
            'premium'   => $this->detector->avcf_wpal_is_premium(),
            'links'     => array(
                'total'   => '',
                'logins'  => '',
                'changes' => '',
            ),
            'fallback_url' => '',
        );

        if ( ! $result['available'] ) {
            return $result;
        }

        // Generic deep-link to the activity log viewer; used when premium
        // drill-down links are not available.
        $result['fallback_url'] = admin_url( 'admin.php?page=wsal-auditlog' );

        if ( ! $result['premium'] ) {
            return $result;
        }

        foreach ( self::TYPES as $type ) {
            $result['links'][ $type ] = $this->avcf_wpal_link_for( $type );
        }

        return $result;
    }

    /**
     * Returns a single count value for one of the supported types.
     * Defensive: returns 0 if the type is invalid or WPAL is unavailable.
     *
     * @param string $type 'total', 'logins', or 'changes'.
     * @return int
     */
    public function avcf_wpal_count_for( $type ) {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            return 0;
        }
        if ( ! $this->detector->avcf_wpal_is_available() ) {
            return 0;
        }

        $class = AVCF_WPAL_Detector::WPAL_OCCURRENCES_CLASS;
        $value = call_user_func( array( $class, 'get_last24_records' ), $type );

        return is_numeric( $value ) ? (int) $value : 0;
    }

    /**
     * Returns the premium drill-down link for one of the supported types.
     * Returns an empty string when Premium is not active or type is invalid.
     *
     * @param string $type 'total', 'logins', or 'changes'.
     * @return string
     */
    public function avcf_wpal_link_for( $type ) {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            return '';
        }
        if ( ! $this->detector->avcf_wpal_is_premium() ) {
            return '';
        }

        $class = AVCF_WPAL_Detector::WPAL_OCCURRENCES_CLASS;
        $value = call_user_func( array( $class, 'get_last24_links' ), $type );

        return is_string( $value ) ? $value : '';
    }
}

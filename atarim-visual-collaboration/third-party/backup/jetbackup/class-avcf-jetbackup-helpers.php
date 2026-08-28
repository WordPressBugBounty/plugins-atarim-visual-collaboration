<?php
/**
 * JetBackup — shared helpers for the atarim/jetbackup-* ability cluster.
 *
 * One invocation path for every ability: instantiate the JetBackup AJAX Call
 * class, setData(), execute(), read the response — mirroring JetBackup's own
 * JetBackup\Wordpress\Abilities::_execute(). Also holds the request field-name
 * and enum constants so the ability files never reach into JetBackup internals.
 *
 * We deliberately bypass JetBackup's own "Abilities" toggle and MFA-for-abilities
 * gate (those only guard JetBackup's own ability registration, not these direct
 * AJAX-class calls); our gate is manage_options. Destructive abilities add a
 * confirm:true guard on top.
 *
 * Built on JetBackup's AJAX Call classes (v3.1.23.x). Every call is guarded with
 * class_exists and wrapped in try/catch, so a missing or renamed class degrades
 * cleanly instead of fataling. Not runtime-tested here.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AVCF_JetBackup_Helpers {

    const PLUGIN_SLUG  = 'jetbackup';
    const PLUGIN_LABEL = 'JetBackup';

    // Request field names (mirror JetBackup constants so ability files stay decoupled).
    const ID_FIELD   = '_id';   // \JetBackup\JetBackup::ID_FIELD
    const TYPE_FIELD = 'type';  // \JetBackup\Queue\QueueItem::TYPE

    // Pagination (\JetBackup\Wordpress\Abilities contract).
    const KEY_SKIP      = 'skip';
    const KEY_LIMIT     = 'limit';
    const LIMIT_DEFAULT = 50;
    const LIMIT_MAX     = 100;

    // Queue types (\JetBackup\Queue\Queue::QUEUE_TYPE_*).
    const QUEUE_BACKUP       = 1;
    const QUEUE_RESTORE      = 2;
    const QUEUE_DOWNLOAD     = 4;
    const QUEUE_DOWNLOAD_LOG = 5;
    const QUEUE_REINDEX      = 8;
    const QUEUE_EXPORT       = 64;
    const QUEUE_EXTRACT      = 128;

    // Restore option bitmask (\JetBackup\Queue\QueueItemRestore::OPTION_RESTORE_*).
    const R_DB_ENTIRE     = 1;     // 1 << 0  import full db dump
    const R_FILES_ENTIRE  = 2;     // 1 << 1  restore full homedir
    const R_DB_EXCLUDE    = 64;    // 1 << 6  restore db minus selectedTables
    const R_DB_SKIP       = 128;   // 1 << 7  skip database
    const R_DB_INCLUDE    = 256;   // 1 << 8  restore only selectedTables
    const R_FILES_EXCLUDE = 512;   // 1 << 9  restore files minus folderList
    const R_FILES_SKIP    = 1024;  // 1 << 10 skip files
    const R_FILES_INCLUDE = 2048;  // 1 << 11 restore only folderList

    // Keys redacted from get-system-info output (parity with JetBackup's own sanitiser).
    const SYSTEM_INFO_REDACT = [ 'secured_dir', 'data_dir', 'wordpress_path', 'jetbackup_data_dir' ];

    /**
     * Is the JetBackup cluster usable right now?
     */
    public static function is_available() {
        return class_exists( 'AVCF_JetBackup_Detector' )
            && ( new AVCF_JetBackup_Detector() )->avcf_jetbackup_is_available();
    }

    /**
     * The single permission gate for every JetBackup ability.
     */
    public static function can_manage() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    }

    /**
     * Invoke a JetBackup AJAX Call by short class name (e.g. 'ListBackups',
     * 'AddToQueue'). Returns a normalised array: [ success, message, data ].
     *
     * @param string $call  Short class name under \JetBackup\Ajax\Calls\.
     * @param array  $data  Request payload keyed by JetBackup field names.
     * @return array{success:bool,message:string,data:array}
     */
    public static function invoke( $call, array $data = [] ) {
        $fqcn = '\\JetBackup\\Ajax\\Calls\\' . $call;

        if ( ! class_exists( $fqcn ) ) {
            return [
                'success' => false,
                'message' => sprintf( 'JetBackup action "%s" is unavailable on this JetBackup version.', $call ),
                'data'    => [],
            ];
        }

        try {
            $obj = new $fqcn();
            $obj->setData( $data );
            $obj->execute();

            return [
                'success' => true,
                'message' => (string) $obj->getResponseMessage(),
                'data'    => (array) $obj->getResponseData(),
            ];
        } catch ( \Throwable $e ) {
            // JetBackup's AjaxException messages are the same user-facing validation
            // strings its own UI shows (e.g. "No backup job id was provided"), so
            // surfacing them helps the caller correct the request.
            //
            // Some of those messages are printf templates whose arguments live on
            // the exception's getData() rather than in the message itself, e.g.
            // AjaxException( 'Failed adding to queue. Error: %s', [ 'Already in queue' ] ).
            // getMessage() alone would surface a bare "%s", dropping the reason, so
            // interpolate the data into the template when both are present. Guarded
            // so a placeholder/argument mismatch can never fatal: only attempt it
            // when there is data and a placeholder, and fall back to the raw message.
            $message = (string) $e->getMessage();
            if ( '' !== $message && method_exists( $e, 'getData' ) ) {
                $args = $e->getData();
                if ( is_array( $args ) && ! empty( $args ) && false !== strpos( $message, '%' ) ) {
                    try {
                        $interpolated = vsprintf( $message, $args );
                        if ( is_string( $interpolated ) && '' !== $interpolated ) {
                            $message = $interpolated;
                        }
                    } catch ( \Throwable $ignore ) {
                        // Template/argument mismatch — keep the raw message.
                    }
                }
            }
            return [
                'success' => false,
                'message' => '' !== $message ? $message : 'JetBackup action failed.',
                'data'    => [],
            ];
        }
    }

    /**
     * Map a friendly {id} input to JetBackup's _id request field, merging any extra
     * pre-built fields.
     */
    public static function id_payload( array $input, array $extra = [] ) {
        $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
        return array_merge( [ self::ID_FIELD => $id ], $extra );
    }

    /**
     * Bound pagination inputs to JetBackup's skip/limit contract.
     */
    public static function paginate( array $input ) {
        $skip  = isset( $input['skip'] ) ? max( 0, (int) $input['skip'] ) : 0;
        $limit = isset( $input['limit'] ) ? (int) $input['limit'] : self::LIMIT_DEFAULT;
        if ( $limit < 1 ) {
            $limit = self::LIMIT_DEFAULT;
        }
        if ( $limit > self::LIMIT_MAX ) {
            $limit = self::LIMIT_MAX;
        }
        return [ self::KEY_SKIP => $skip, self::KEY_LIMIT => $limit ];
    }

    /**
     * Was an explicit confirm:true supplied? Destructive abilities require this.
     */
    public static function confirmed( array $input ) {
        return ! empty( $input['confirm'] ) && filter_var( $input['confirm'], FILTER_VALIDATE_BOOLEAN );
    }

    /**
     * Standard "needs confirmation" response for destructive abilities.
     */
    public static function confirm_required_response( $what ) {
        return [
            'success' => false,
            'message' => sprintf( 'This is a destructive action (%s). Re-run with confirm:true to proceed.', $what ),
            'data'    => [],
        ];
    }

    /**
     * Recursively strip redacted keys from get-system-info output.
     */
    public static function redact_system_info( array $data ) {
        foreach ( $data as $key => $value ) {
            if ( in_array( $key, self::SYSTEM_INFO_REDACT, true ) ) {
                unset( $data[ $key ] );
                continue;
            }
            if ( is_array( $value ) ) {
                $data[ $key ] = self::redact_system_info( $value );
            }
        }
        return $data;
    }

    /**
     * Lift the created task's id to the top level so callers that start a task
     * (e.g. run-backup) get an unmissable handle instead of digging JetBackup's
     * internal _id out of the raw data.
     *
     * AddToQueue answers with the JOB id, not the id of the queue item it just
     * created, so polling get-queue-item with it returns "Invalid queue item id
     * provided". The job id is still useful, so it keeps its own field, and
     * queue_item_id is resolved from the queue itself when $queue_type is given.
     */
    public static function surface_queue_id( array $result, $queue_type = null ) {
        if ( empty( $result['success'] ) || empty( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return $result;
        }

        $jid = $result['data'][ self::ID_FIELD ] ?? ( $result['data']['id'] ?? null );

        if ( null === $jid || '' === $jid ) {
            return $result;
        }

        $jid = is_numeric( $jid ) ? (int) $jid : $jid;

        $result['job_id']        = $jid;
        $result['queue_item_id'] = ( null === $queue_type )
            ? $jid
            : ( self::latest_queue_item_id( $queue_type ) ?? $jid );

        return $result;
    }

    /**
     * Newest queue item of the given type, used to name the item AddToQueue just
     * created. Returns null when the queue cannot be read, in which case the
     * caller keeps the job id and list-queue-items remains the way through.
     */
    private static function latest_queue_item_id( $queue_type ) {
        $res = self::invoke( 'ListQueueItems', [ self::KEY_SKIP => 0, self::KEY_LIMIT => self::LIMIT_DEFAULT ] );

        if ( empty( $res['success'] ) || empty( $res['data'] ) ) {
            return null;
        }

        $newest = null;

        foreach ( self::extract_snapshot_list( $res['data'] ) as $item ) {
            if ( ! is_array( $item ) || (int) ( $item[ self::TYPE_FIELD ] ?? 0 ) !== (int) $queue_type ) {
                continue;
            }

            $id = $item[ self::ID_FIELD ] ?? ( $item['id'] ?? null );

            if ( is_numeric( $id ) && ( null === $newest || (int) $id > $newest ) ) {
                $newest = (int) $id;
            }
        }

        return $newest;
    }

    /**
     * Pull the array of snapshots out of a ListBackups response, tolerating the
     * common wrapper shapes.
     */
    private static function extract_snapshot_list( $data ) {
        if ( ! is_array( $data ) ) {
            return [];
        }
        if ( isset( $data[0] ) && is_array( $data[0] ) ) {
            return $data;
        }
        foreach ( [ 'data', 'items', 'backups', 'snapshots', 'list' ] as $k ) {
            if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
                return $data[ $k ];
            }
        }
        return [];
    }

    /**
     * Resolve a snapshot NAME (as reported by a completed backup queue item's
     * snapshot_name) to its numeric snapshot id, so restore-backup can accept a
     * name directly and callers can skip a manual list-backups lookup. Searches
     * the snapshot list in pages, capped, and returns null if not found.
     */
    public static function resolve_snapshot_id_by_name( $name ) {
        $name = (string) $name;
        if ( '' === $name ) {
            return null;
        }
        $skip  = 0;
        $limit = 100;
        for ( $page = 0; $page < 10; $page++ ) { // cap the search (<= 1000 snapshots)
            $res = self::invoke( 'ListBackups', [ self::KEY_SKIP => $skip, self::KEY_LIMIT => $limit ] );
            if ( empty( $res['success'] ) || empty( $res['data'] ) ) {
                break;
            }
            $list = self::extract_snapshot_list( $res['data'] );
            if ( empty( $list ) ) {
                break;
            }
            foreach ( $list as $snap ) {
                if ( is_array( $snap ) && isset( $snap['name'] ) && (string) $snap['name'] === $name ) {
                    $id = $snap[ self::ID_FIELD ] ?? ( $snap['id'] ?? null );
                    return ( null !== $id ) ? (int) $id : null;
                }
            }
            if ( count( $list ) < $limit ) {
                break; // last page
            }
            $skip += $limit;
        }
        return null;
    }

    /**
     * Call an Atarim restore-point endpoint with this site's Atarim token.
     *
     * @param string $path   Path under AVCF_CRM_API, e.g. 'v1/jetbackup/restore-point'.
     * @param string $method 'GET' or 'POST'.
     * @param array  $body   Body for POST.
     * @return array {success:bool, message:string, data:array}
     */
    public static function atarim_call( $path, $method = 'GET', $body = [] ) {
        $functions = new AVCF_Functions();
        $token     = $functions->avcf_get_setting_data( 'avc_atarim_secret_token' );

        if ( empty( $token ) ) {
            return [ 'success' => false, 'message' => 'This site is not connected to Atarim, so restore points cannot be tracked. Reconnect the site in the Atarim plugin settings.', 'data' => [] ];
        }

        $response = $functions->avcf_make_api_call(
            rtrim( AVCF_CRM_API, '/' ) . '/' . ltrim( $path, '/' ),
            $body,
            '',
            $token,
            $method
        );

        $code = isset( $response['status_code'] ) ? (int) $response['status_code'] : 0;

        if ( $code === 401 ) {
            return [ 'success' => false, 'message' => 'Atarim rejected this site\'s token. Reconnect the site in the Atarim plugin settings.', 'data' => [] ];
        }

        // avcf_make_api_call() only returns a decoded body on a 200; anything
        // else arrives under 'error', so the endpoint's own message would be
        // lost if we only ever read 'data'.
        $payload = isset( $response['data'] ) && is_array( $response['data'] )
            ? $response['data']
            : ( isset( $response['error'] ) && is_array( $response['error'] ) ? $response['error'] : [] );

        if ( empty( $payload ) ) {
            return [ 'success' => false, 'message' => 'Atarim returned an unreadable response (HTTP ' . $code . ').', 'data' => [] ];
        }

        return [
            'success' => ! empty( $payload['success'] ),
            'message' => isset( $payload['message'] ) ? (string) $payload['message'] : '',
            'data'    => isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : [],
        ];
    }
}
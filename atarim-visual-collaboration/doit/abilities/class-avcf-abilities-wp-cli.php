<?php
/**
 * WP-CLI escape hatch.
 *
 * Runs the site's `wp` binary for actions that named abilities can't do or that
 * are too large / long-running for a single web request (bulk search-replace,
 * db export/import, regenerate-thumbnails, cron runs, etc). Two abilities:
 *
 *   - atarim/run-wp-cli     : run a `wp` command, synchronously (returns
 *                             stdout/stderr/exit_code) or asynchronously in the
 *                             background (returns a job_id to poll).
 *   - atarim/get-wp-cli-job : poll a background job's status + output log.
 *
 * Capability parity with Novamira's run-wp-cli: arbitrary `wp` commands, no
 * command allow-list. Guardrails (Atarim conventions):
 *   - Requires `manage_options` (administrator).
 *   - Requires PHP process functions (proc_open/exec); refuses cleanly if the
 *     host disables them or the `wp` binary is absent — so it degrades to
 *     "unavailable", never a fatal.
 *   - Every invocation is audit-logged (user, time, command hash + preview) via
 *     error_log and the `avcf_wp_cli_audit` action.
 *   - Sync runs are bounded by a timeout (default 58s) and the process is
 *     terminated if exceeded — use async for anything longer.
 *   - Background job logs are written to a protected dir under uploads
 *     (.htaccess deny + index.php), since output can contain sensitive data.
 *
 * This is the most powerful ability in the catalog (arbitrary `wp`, marked
 * destructive). Consent is handled agent-side, as with execute-php.
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_Abilities_WP_CLI {

	/** Default seconds a synchronous command may run before it is terminated. */
	const SYNC_TIMEOUT = 58;
	/** Hard ceiling on the sync timeout a caller can request. */
	const MAX_SYNC_TIMEOUT = 300;
	/** Default max bytes of a job log returned by get-wp-cli-job. */
	const DEFAULT_LOG_LIMIT = 1048576; // 1 MB

	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$this->register_run();
		$this->register_get_job();
	}

	private function register_run() {
		$self = $this;
		wp_register_ability( 'atarim/run-wp-cli', [
			'label'        => 'Run WP-CLI Command',
			'description'  => 'Run a WP-CLI command on the server via the site\'s `wp` binary — for actions that no named ability covers, or that are too large/slow for one web request (e.g. wp search-replace, wp db export, wp media regenerate, wp cron event run). Pass args as an array WITHOUT the leading "wp" (e.g. ["plugin","list","--format=json"]). Runs synchronously by default (returns stdout/stderr/exit_code); set async:true for long-running commands (returns a job_id — poll get-wp-cli-job for output). Powerful and DESTRUCTIVE: it can run any wp command, including database and file changes. Requires the wp binary and PHP process execution; if the host disables those it returns a clear "unavailable" message. Every call is audit-logged.',
			'category'     => 'atarim',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'args'    => [ 'type' => 'array', 'minItems' => 1, 'items' => [ 'type' => 'string' ], 'description' => 'Arguments passed to `wp`, without the leading "wp". Example: ["option","get","siteurl"].' ],
					'async'   => [ 'type' => 'boolean', 'default' => false, 'description' => 'Run in the background and return a job_id to poll (use for long-running commands).' ],
					'timeout' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_SYNC_TIMEOUT, 'description' => 'Synchronous-run timeout in seconds (default ' . self::SYNC_TIMEOUT . ', max ' . self::MAX_SYNC_TIMEOUT . '). Ignored when async is true.' ],
				],
				'required'             => [ 'args' ],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success'   => [ 'type' => 'boolean' ],
					'exit_code' => [ 'type' => 'integer' ],
					'stdout'    => [ 'type' => 'string' ],
					'stderr'    => [ 'type' => 'string' ],
					'job_id'    => [ 'type' => 'string' ],
					'pid'       => [ 'type' => 'integer' ],
					'message'   => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback'    => function( $input = [] ) use ( $self ) { return $self->run( (array) $input ); },
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			'meta' => [
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
			],
		] );
	}

	private function register_get_job() {
		$self = $this;
		wp_register_ability( 'atarim/get-wp-cli-job', [
			'label'        => 'Get WP-CLI Job Status',
			'description'  => 'Check an asynchronous WP-CLI job started by run-wp-cli with async:true. Returns status ("running" | "completed" | "not_found"), the exit_code once finished, and the captured output log. Use offset/limit to page through a large log (limit -1 returns the whole file).',
			'category'     => 'atarim',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'job_id' => [ 'type' => 'string', 'minLength' => 1, 'description' => 'The job_id returned by run-wp-cli.' ],
					'offset' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Byte offset to start reading the log from.' ],
					'limit'  => [ 'type' => 'integer', 'description' => 'Max bytes of log to return (default 1 MB; -1 for the whole file).' ],
				],
				'required'             => [ 'job_id' ],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success'    => [ 'type' => 'boolean' ],
					'job_id'     => [ 'type' => 'string' ],
					'status'     => [ 'type' => 'string' ],
					'exit_code'  => [ 'type' => 'integer' ],
					'stdout'     => [ 'type' => 'string' ],
					'bytes_read' => [ 'type' => 'integer' ],
					'truncated'  => [ 'type' => 'boolean' ],
					'message'    => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'job_id', 'status', 'message' ],
			],
			'execute_callback'    => function( $input = [] ) use ( $self ) { return $self->get_job( (array) $input ); },
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			'meta' => [
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			],
		] );
	}

	/** Main run entry: validate, guard host, resolve wp, audit, dispatch. */
	public function run( $input ) {
		$raw = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : [];
		$args = [];
		foreach ( $raw as $arg ) {
			if ( ! is_string( $arg ) ) {
				return [ 'success' => false, 'message' => 'Every entry in args must be a string.' ];
			}
			$args[] = $arg;
		}
		if ( empty( $args ) ) {
			return [ 'success' => false, 'message' => 'args is required (the wp command as an array, e.g. ["plugin","list"]).' ];
		}
		if ( ! function_exists( 'proc_open' ) || ! function_exists( 'exec' ) ) {
			return [ 'success' => false, 'message' => 'WP-CLI is unavailable: PHP process execution (proc_open/exec) is disabled on this host. This is a server configuration limit — an administrator would need to enable it, or run the command manually.' ];
		}
		$wp_path = $this->find_wp_path();
		if ( null === $wp_path ) {
			return [ 'success' => false, 'message' => 'WP-CLI is unavailable: the `wp` binary was not found on this server. Install WP-CLI or run the command manually.' ];
		}
		// Run as root needs --allow-root, or wp refuses.
		if ( $this->is_root() && ! in_array( '--allow-root', $args, true ) ) {
			array_unshift( $args, '--allow-root' );
		}

		$async = ! empty( $input['async'] );
		$this->audit( $args, $async );

		if ( $async ) {
			return $this->run_async( $wp_path, $args );
		}
		$timeout = isset( $input['timeout'] ) ? (int) $input['timeout'] : self::SYNC_TIMEOUT;
		$timeout = max( 1, min( self::MAX_SYNC_TIMEOUT, $timeout ) );
		return $this->run_sync( $wp_path, $args, $timeout );
	}

	/** Synchronous run via proc_open, bounded by $timeout seconds. */
	private function run_sync( $wp_path, $args, $timeout ) {
		$descriptors = [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
		$pipes = [];
		// Array form (no shell) on PHP 7.4+; escaped string form otherwise.
		if ( PHP_VERSION_ID >= 70400 ) {
			$cmd = array_merge( [ $wp_path ], $args );
		} else {
			$cmd = escapeshellarg( $wp_path );
			foreach ( $args as $a ) {
				$cmd .= ' ' . escapeshellarg( $a );
			}
		}
		$process = proc_open( $cmd, $descriptors, $pipes, ABSPATH );
		if ( ! is_resource( $process ) ) {
			return [ 'success' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => '', 'message' => 'Failed to start the wp process.' ];
		}
		if ( isset( $pipes[0] ) && is_resource( $pipes[0] ) ) {
			fclose( $pipes[0] );
		}
		foreach ( [ 1, 2 ] as $i ) {
			if ( isset( $pipes[ $i ] ) && is_resource( $pipes[ $i ] ) ) {
				stream_set_blocking( $pipes[ $i ], false );
			}
		}
		$stdout   = '';
		$stderr   = '';
		$deadline = time() + $timeout;
		$timed_out = false;
		do {
			$status = proc_get_status( $process );
			if ( isset( $pipes[1] ) && is_resource( $pipes[1] ) ) {
				$stdout .= stream_get_contents( $pipes[1] );
			}
			if ( isset( $pipes[2] ) && is_resource( $pipes[2] ) ) {
				$stderr .= stream_get_contents( $pipes[2] );
			}
			if ( ! $status['running'] ) {
				break;
			}
			if ( time() >= $deadline ) {
				$timed_out = true;
				proc_terminate( $process, 9 );
				break;
			}
			usleep( 100000 ); // 100ms
		} while ( true );

		foreach ( [ 1, 2 ] as $i ) {
			if ( isset( $pipes[ $i ] ) && is_resource( $pipes[ $i ] ) ) {
				fclose( $pipes[ $i ] );
			}
		}
		$exit_code = proc_close( $process );

		if ( $timed_out ) {
			return [
				'success'   => false,
				'exit_code' => -1,
				'stdout'    => $stdout,
				'stderr'    => $stderr,
				'message'   => sprintf( 'Command exceeded the %ds synchronous timeout and was terminated. Re-run with async:true for long-running commands.', $timeout ),
			];
		}
		return [
			'success'   => ( 0 === $exit_code ),
			'exit_code' => $exit_code,
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'message'   => ( 0 === $exit_code ) ? 'Command completed.' : sprintf( 'Command exited with code %d.', $exit_code ),
		];
	}

	/** Background run: nohup the command, capture output+exit to job files. */
	private function run_async( $wp_path, $args ) {
		$dir = $this->jobs_dir();
		if ( null === $dir ) {
			return [ 'success' => false, 'message' => 'Could not create the job directory for background execution.' ];
		}
		$job_id      = bin2hex( random_bytes( 8 ) );
		$log_file    = $dir . 'job_' . $job_id . '.log';
		$status_file = $dir . 'job_' . $job_id . '.status';
		if ( false === file_put_contents( $log_file, '' ) ) { // phpcs:ignore
			return [ 'success' => false, 'message' => 'Failed to create the job log file.' ];
		}

		$cmd_args = array_map( 'escapeshellarg', $args );
		$wp_cmd   = escapeshellarg( $wp_path ) . ' ' . implode( ' ', $cmd_args );
		$inner    = sprintf( 'cd %s && (%s > %s 2>&1; echo $? > %s)', escapeshellarg( ABSPATH ), $wp_cmd, escapeshellarg( $log_file ), escapeshellarg( $status_file ) );
		$cmd      = 'nohup sh -c ' . escapeshellarg( $inner ) . ' > /dev/null 2>&1 & echo $!';

		$out = [];
		$rc  = 0;
		exec( $cmd, $out, $rc ); // phpcs:ignore
		$pid = ( 0 === $rc && isset( $out[0] ) && '' !== trim( $out[0] ) ) ? (int) trim( $out[0] ) : null;
		if ( 0 !== $rc || null === $pid ) {
			file_put_contents( $status_file, '127' ); // phpcs:ignore
			return [ 'success' => false, 'job_id' => $job_id, 'message' => 'Failed to start the background wp process.' ];
		}
		return [
			'success' => true,
			'job_id'  => $job_id,
			'pid'     => $pid,
			'message' => sprintf( 'Started background job %s (pid %d). Poll get-wp-cli-job for status and output.', $job_id, $pid ),
		];
	}

	/** Poll a background job. */
	public function get_job( $input ) {
		$job_id = isset( $input['job_id'] ) ? (string) $input['job_id'] : '';
		if ( ! preg_match( '/^[a-f0-9]{16}$/i', $job_id ) ) {
			return [ 'success' => false, 'job_id' => $job_id, 'status' => 'not_found', 'message' => 'Invalid job_id format.' ];
		}
		$dir         = $this->jobs_dir( false );
		$log_file    = $dir . 'job_' . $job_id . '.log';
		$status_file = $dir . 'job_' . $job_id . '.status';
		if ( ! is_file( $log_file ) ) {
			return [ 'success' => false, 'job_id' => $job_id, 'status' => 'not_found', 'message' => 'No job with that id (it may have expired or never existed).' ];
		}
		$status    = 'running';
		$exit_code = null;
		if ( is_file( $status_file ) ) {
			$status  = 'completed';
			$content = trim( (string) file_get_contents( $status_file ) );
			if ( '' !== $content ) {
				$exit_code = (int) $content;
			}
		}
		$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : self::DEFAULT_LOG_LIMIT;
		$slice  = $this->read_log_slice( $log_file, $offset, $limit );

		$res = [
			'success'    => true,
			'job_id'     => $job_id,
			'status'     => $status,
			'stdout'     => $slice['content'],
			'bytes_read' => $slice['bytes_read'],
			'truncated'  => $slice['truncated'],
			'message'    => ( 'completed' === $status ) ? sprintf( 'Job completed (exit %s).', ( null === $exit_code ? '?' : $exit_code ) ) : 'Job still running.',
		];
		if ( null !== $exit_code ) {
			$res['exit_code'] = $exit_code;
		}
		return $res;
	}

	/* ------------------------------------------------------------------ */

	/** Locate the `wp` binary. Returns absolute path or null. */
	private function find_wp_path() {
		if ( function_exists( 'exec' ) ) {
			foreach ( [ 'which wp 2>/dev/null', 'command -v wp 2>/dev/null' ] as $probe ) {
				$out = [];
				$rc  = 0;
				exec( $probe, $out, $rc ); // phpcs:ignore
				if ( 0 === $rc && isset( $out[0] ) && '' !== trim( $out[0] ) ) {
					return trim( $out[0] );
				}
			}
		}
		foreach ( [ '/usr/local/bin/wp', '/usr/bin/wp', '/bin/wp', '/usr/local/sbin/wp', '/usr/sbin/wp' ] as $p ) {
			if ( is_file( $p ) && is_executable( $p ) ) {
				return $p;
			}
		}
		return null;
	}

	/** Whether the PHP process is running as root. */
	private function is_root() {
		if ( function_exists( 'posix_geteuid' ) ) {
			return 0 === posix_geteuid();
		}
		if ( function_exists( 'exec' ) ) {
			$out = [];
			$rc  = 0;
			exec( 'id -u 2>/dev/null', $out, $rc ); // phpcs:ignore
			if ( 0 === $rc && isset( $out[0] ) ) {
				return '0' === trim( $out[0] );
			}
		}
		return false;
	}

	/** Protected jobs directory under uploads. Returns path (with trailing slash) or null. */
	private function jobs_dir( $ensure = true ) {
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) ) {
			return null;
		}
		$dir = trailingslashit( $up['basedir'] ) . 'atarim-wpcli-jobs/';
		if ( $ensure && ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Harden: job logs can contain sensitive output — block web access.
			@file_put_contents( $dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
			@file_put_contents( $dir . 'index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/** Read a byte slice of a log file. Returns [ content, bytes_read, truncated ]. */
	private function read_log_slice( $log_file, $offset, $limit ) {
		$size = (int) @filesize( $log_file ); // phpcs:ignore
		if ( $offset >= $size ) {
			return [ 'content' => '', 'bytes_read' => 0, 'truncated' => false ];
		}
		$handle = fopen( $log_file, 'rb' ); // phpcs:ignore
		if ( false === $handle ) {
			return [ 'content' => '', 'bytes_read' => 0, 'truncated' => false ];
		}
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}
		$read_length = ( -1 === $limit ) ? ( $size - $offset ) : $limit;
		$content     = fread( $handle, max( 1, (int) $read_length ) );
		fclose( $handle );
		if ( false === $content ) {
			return [ 'content' => '', 'bytes_read' => 0, 'truncated' => false ];
		}
		$bytes_read = strlen( $content );
		$truncated  = ( -1 !== $limit ) && ( ( $offset + $bytes_read ) < $size );
		return [ 'content' => $content, 'bytes_read' => $bytes_read, 'truncated' => $truncated ];
	}

	/** Audit every invocation (user, time, command hash + preview). */
	public function audit( $args, $async ) {
		$user   = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$joined = implode( ' ', $args );
		$entry  = [
			'time'    => gmdate( 'c' ),
			'user_id' => ( $user && isset( $user->ID ) ) ? (int) $user->ID : 0,
			'login'   => ( $user && isset( $user->user_login ) ) ? (string) $user->user_login : '',
			'async'   => (bool) $async,
			'sha1'    => sha1( $joined ),
			'preview' => substr( $joined, 0, 200 ),
		];
		error_log( sprintf( // phpcs:ignore
			'[atarim/run-wp-cli] user=%d(%s) async=%s sha1=%s cmd=%s',
			$entry['user_id'], $entry['login'], $entry['async'] ? '1' : '0', $entry['sha1'], $entry['preview']
		) );
		do_action( 'avcf_wp_cli_audit', $entry );
	}
}

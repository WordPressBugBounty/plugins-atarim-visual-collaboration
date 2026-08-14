<?php
/**
 * execute-php: run arbitrary PHP (developer / debug capability).
 *
 * This is the most powerful ability in the plugin — arbitrary code execution,
 * equivalent in reach to WP-CLI `wp eval`, Code Snippets, or WP Console. It is
 * therefore heavily gated:
 *
 *   - Refuses when DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS is set — a site that
 *     forbids code changes should not have an eval endpoint either. This is the
 *     plugin-side gate. Opt-in / consent for actually invoking this ability is
 *     handled on the AGENT side (the MCP client prompts the operator before the
 *     call), so this ability is not additionally blocklisted plugin-side.
 *   - Requires `manage_options` (administrator).
 *   - Every execution is audit-logged (user, time, code hash) via error_log and
 *     the `avcf_execute_php_audit` action so a site can capture it.
 *   - Output and return value are size-capped; execution is wrapped in a
 *     Throwable catch (catches Errors/ParseErrors; true fatals like OOM are not
 *     catchable in PHP and will surface as a request failure).
 *
 * @package atarim-visual-collaboration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AVCF_Abilities_ExecutePHP {

	/** Max characters returned for captured output and for the return value each. */
	const MAX_OUTPUT = 102400; // 100 KB

	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$self = $this;
		wp_register_ability( 'atarim/execute-php', [
			'label'        => 'Execute PHP (developer)',
			'description'  => 'Run a snippet of PHP on the server and return its echoed output and return value. Powerful developer/debug tool — equivalent to WP-CLI "wp eval". Do NOT include a "<?php" opening tag; the code is evaluated directly (use "return $x;" to return a value). WordPress is fully loaded, so core/plugin functions and $wpdb are available. Every call is audit-logged. Refused when the site disables file editing (DISALLOW_FILE_EDIT). Output and return value are capped at 100 KB each.',
			'category'     => 'atarim',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'code' => [ 'type' => 'string', 'description' => 'PHP code to evaluate (no <?php tag). Use return to hand back a value; echo/print is captured as output.' ],
				],
				'required'             => [ 'code' ],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'success'       => [ 'type' => 'boolean' ],
					'executed'      => [ 'type' => 'boolean' ],
					'return_value'  => [ 'type' => 'string' ],
					'output'        => [ 'type' => 'string' ],
					'output_truncated' => [ 'type' => 'boolean' ],
					'error'         => [ 'type' => 'string' ],
					'message'       => [ 'type' => 'string' ],
				],
				'required' => [ 'success', 'message' ],
			],
			'execute_callback' => function( $input = [] ) use ( $self ) {
				if ( ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
					return [ 'success' => false, 'executed' => false, 'message' => 'Refused: PHP execution is turned off because this site disables code/file modifications at the configuration level (DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS is set in wp-config.php). This is a permanent, site-wide setting, so retrying will not help — an administrator would need to change the site configuration to allow it.' ];
				}
				$code = isset( $input['code'] ) ? (string) $input['code'] : '';
				if ( '' === trim( $code ) ) {
					return [ 'success' => false, 'executed' => false, 'message' => 'code is required.' ];
				}
				// A leading <?php would be a parse error under eval(); strip one if present.
				$code = preg_replace( '/^\s*<\?php\s*/', '', $code );

				$self->audit( $code );

				$error   = null;
				$ret     = null;
				ob_start();
				try {
					$ret = eval( $code ); // phpcs:ignore Squiz.PHP.Eval
				} catch ( \Throwable $e ) {
					$error = $e->getMessage() . ' (' . get_class( $e ) . ')';
				}
				$output = (string) ob_get_clean();

				$ret_str = $self->cap( ( null === $ret ) ? 'null' : var_export( $ret, true ) );
				$out_cap = $self->cap( $output );

				if ( null !== $error ) {
					return [
						'success'  => false,
						'executed' => true,
						'output'   => $out_cap['text'],
						'error'    => $error,
						'message'  => 'PHP raised an error: ' . $error,
					];
				}
				return [
					'success'          => true,
					'executed'         => true,
					'return_value'     => $ret_str['text'],
					'output'           => $out_cap['text'],
					'output_truncated' => ( $out_cap['truncated'] || $ret_str['truncated'] ),
					'message'          => 'Executed.' . ( ( $out_cap['truncated'] || $ret_str['truncated'] ) ? ' Output/return value was truncated at 100 KB.' : '' ),
				];
			},
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			'meta' => [
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
			],
		] );
	}

	/** Record an execution to the error log and an action hook. */
	public function audit( $code ) {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$entry = [
			'time'    => gmdate( 'c' ),
			'user_id' => ( $user && isset( $user->ID ) ) ? (int) $user->ID : 0,
			'login'   => ( $user && isset( $user->user_login ) ) ? (string) $user->user_login : '',
			'sha1'    => sha1( (string) $code ),
			'bytes'   => strlen( (string) $code ),
			'preview' => substr( (string) $code, 0, 200 ),
		];
		error_log( sprintf( // phpcs:ignore
			'[atarim/execute-php] user=%d(%s) bytes=%d sha1=%s',
			$entry['user_id'], $entry['login'], $entry['bytes'], $entry['sha1']
		) );
		do_action( 'avcf_execute_php_audit', $entry );
	}

	/** Cap a string to MAX_OUTPUT; returns [ 'text' => ..., 'truncated' => bool ]. */
	public function cap( $text ) {
		$text = (string) $text;
		if ( strlen( $text ) > self::MAX_OUTPUT ) {
			return [ 'text' => substr( $text, 0, self::MAX_OUTPUT ), 'truncated' => true ];
		}
		return [ 'text' => $text, 'truncated' => false ];
	}
}

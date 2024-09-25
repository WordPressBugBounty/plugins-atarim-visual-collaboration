<?php 
/**
 * Atarim  - wp_feedback class.
 * Defines front end functionality
 *
 */
class WP_Feedback {

	/**
	 * Plugin version, used for cache-busting of style and script file references.
	 *
	 * @since   1.0.0
	 *
	 * @var     string
	 */
	const VERSION = '1.2.0.1';

	/**
	 * Unique identifier for plugin.
	 *
	 * @since    1.2.0.1
	 *
	 * @var      string
	 */
	protected $plugin_slug = 'atarim-visual-collaboration';

	/**
	 * Instance of this class.
	 *
	 * @since    1.2.0.1
	 *
	 * @var      object
	 */
	protected static $instance = null;

	/**
	 * Initialize the plugin by setting localization and loading public scripts
	 * and styles.
	 *
	 * @since     1.2.0.1
	 */
	public function __construct() {

		// Activate plugin when new blog is added
		add_action( 'wpf_new_blog', array( $this, 'wpf_activate_new_site' ) );

	}

	/**
	 * Return the plugin slug.
	 *
	 * @return    Atarim slug variable.
	 */
	public function get_plugin_slug() {
		return $this->plugin_slug;
	}

	/**
	 * Return an instance of this class.
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Fired when the plugin is activated.
	 *
	 * @param    boolean    $network_wide
	 */
	public static function activate( $network_wide ) {

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {

			if ( $network_wide  ) {

				// Get all blog ids
				$blog_ids = self::get_blog_ids();

				foreach ( $blog_ids as $blog_id ) {

					switch_to_blog( $blog_id );
					self::single_activate();
					self::create_guest_token();
				}

				restore_current_blog();

			} else {
				self::single_activate();
				self::create_guest_token();
			}

		} else {
			self::single_activate();
			self::create_guest_token();
		}

		// This is to flag that redirect to setting page is needed after plugin activation.
		add_option('wpf_plugin_do_activation_redirect', true);
	}

	/**
	 * Fired when the plugin is deactivated.
	 * @param    boolean    $network_wide
	 */
	public static function deactivate( $network_wide ) {

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {

			if ( $network_wide ) {

				// Get all blog ids
				$blog_ids = self::get_blog_ids();

				foreach ( $blog_ids as $blog_id ) {

					switch_to_blog( $blog_id );
					self::single_deactivate();

				}

				restore_current_blog();

			} else {
				self::single_deactivate();
			}

		} else {
			self::single_deactivate();
		}

	}

	/**
	 * Fired when a new site is activated
	 *
	 * @param    int    $blog_id    ID of the new blog.
	 */
	public function wpf_activate_new_site( $blog_id ) {

		if ( 1 !== did_action( 'wpf_new_blog' ) ) {
			return;
		}

		switch_to_blog( $blog_id );
		self::single_activate();
		restore_current_blog();

	}

	/**
	 * Get all blog ids of blogs in the current network that are:
	 * - not archived
	 * - not spam
	 * - not deleted
	 *
	 * @return   array|false    The blog ids, false if no matches.
	 */
	private static function get_blog_ids() {

		global $wpdb;

		// get an array of blog ids
		$sql = "SELECT blog_id FROM $wpdb->blogs
			WHERE archived = '0' AND spam = '0'
			AND deleted = '0'";

		return $wpdb->get_col( $sql );
	}

	/**
	 * Fired for each blog when the plugin is activated.
	 */
	private static function single_activate() {
	    $wp_rocket_settings                  = get_option( 'wp_rocket_settings' );
	    $wp_rocket_settings['exclude_css'][] = '/wp-content/plugins/atarim-visual-collaboration/css/(.*).css';
	    $wp_rocket_settings['exclude_js'][]  = '/wp-content/plugins/atarim-visual-collaboration/js/(.*).js';
	    update_option( 'wp_rocket_settings', $wp_rocket_settings );
	}

	/**
	 * Fired for each blog when the plugin is deactivated.
	 */
	private static function single_deactivate() {}

	/**
	 * Fired for each blog when the plugin is activated to insert unique token for guest collab link.
	 * @author Pratap <email>
	 * @version 3.15
	 */
	private static function create_guest_token() {
		$bytes = random_bytes(10);
		$token = bin2hex( $bytes );
		$exist = get_option( 'wpf_guest_token' );
		if ( $exist == '' ) {
			update_option( 'wpf_guest_token', $token );
		}
	}

	/**
	 * Fired when the plugin is updated to insert unique token for guest collab link.
	 * @author Pratap <email>
	 * @version 3.17
	 */
	public function call_guest_token() {
		self::create_guest_token();
	}

	public function remove_restrict_plugin() {
		delete_option( 'restrict_plugin' );
	}
}
<?php
if (!defined('ABSPATH')) {
    exit;
}

class AVCF_User_Meta {
    public function __construct() {
        // Display checkbox
        add_action('show_user_profile', [$this, 'add_webmaster_checkbox']);
        add_action('edit_user_profile', [$this, 'add_webmaster_checkbox']);

        // Save checkbox
        add_action('personal_options_update', [$this, 'save_webmaster_checkbox']); // admin editing self
        add_action('edit_user_profile_update', [$this, 'save_webmaster_checkbox']); // admin editing others
    }

    /**
     * Add the "Webmaster" checkbox to the user profile screen.
     *
     * @param WP_User $user
     */
    public function add_webmaster_checkbox($user) {
        // Only admins can see this
        if (!current_user_can('administrator')) {
            return;
        }

        $checked = get_user_meta($user->ID, 'avc_user_type', true) === 'webmaster' ? 'checked' : '';
        ?>
        <h3><?php esc_html_e('Custom User Settings', 'atarim-visual-collaboration'); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="avc_user_type"><?php esc_html_e('Webmaster', 'atarim-visual-collaboration'); ?></label></th>
                <td>
                    <input type="checkbox" name="avc_user_type" id="avc_user_type" value="webmaster" <?php echo $checked; ?> />
                    <label for="avc_user_type"><?php esc_html_e('Make this user webmaster', 'atarim-visual-collaboration'); ?></label>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the "Webmaster" checkbox value.
     *
     * @param int $user_id
     */
    public function save_webmaster_checkbox($user_id) {
        // Only admins can save
        if (!current_user_can('administrator')) {
            return;
        }

        if (isset($_POST['avc_user_type']) &&  sanitize_text_field(wp_unslash($_POST['avc_user_type'])) === 'webmaster') {
            update_user_meta($user_id, 'avc_user_type', 'webmaster');
        } else {
            delete_user_meta($user_id, 'avc_user_type');
        }
    }
}

new AVCF_User_Meta();
<?php
global $current_user;
if ( $current_user->display_name == '' ) {
	$wpf_user_name = $current_user->user_nicename;
} else {
	$wpf_user_name = $current_user->display_name;
}
$wpf_admin_users = get_users( array( 'role' => 'Administrator' ) );
?>
<div class="wpf_backend_initial_setup">
	<div class="wpf_backend_initial_setup_inner">
		<form method="post" action="admin-post.php">
			<div class="wpf_loader_admin wpf_hide"></div>
			<div id="wpf_initial_settings_first_step" class="wpf_initial_container">
				<div class="wpf_wizard_content_box">
					<div class="wpf_logo_wizard">
						<img src="<?php echo esc_url( WPF_PLUGIN_URL . 'images/reg-logo.svg' ); ?>" width="150" height="40" alt="Atarim">
					</div>
					<div class="wpf_title_wizard"><?php esc_attr_e( "Let's Get You Up and Running", 'atarim-visual-collaboration' ); ?></div>
					<p class="wpf_desc_wizard">
						<?php
						printf( __( "Good to have you here %s!", 'atarim-visual-collaboration' ), $wpf_user_name );
						echo '<br>';
						printf( __( "We need to connect you to your Atarim account", 'atarim-visual-collaboration' ) );
						echo '. ';
						printf( __( '<a href="https://atarim.io/help/wordpress-plugin/why-you-need-to-register" target="_blank">See Why.</a>', 'atarim-visual-collaboration' ) );
						?>
					</p>
					<input type="hidden" name="action" value="save_wpfeedback_options"/>
					<?php 
						$google_sup = WPF_APP_SITE_URL . '/google-auth?activation_callback='.Base64_encode( WPF_SITE_URL ).'&page_redirect=' . Base64_encode( "collaboration_page_settings" ) . '&site_url=' . Base64_encode( WPF_HOME_URL );
					?>
					<a href="<?php echo $google_sup; ?>" class="supg-btn">
						<span>
							<svg viewBox="0 0 48 48" width="18" height="18" style="margin-top: 2px; margin-right: 5px;"><defs><path id="a" d="M44.5 20H24v8.5h11.8C34.7 33.9 30.1 37 24 37c-7.2 0-13-5.8-13-13s5.8-13 13-13c3.1 0 5.9 1.1 8.1 2.9l6.4-6.4C34.6 4.1 29.6 2 24 2 11.8 2 2 11.8 2 24s9.8 22 22 22c11 0 21-8 21-22 0-1.3-.2-2.7-.5-4z"></path></defs><clipPath id="b"><use xlink:href="#a" overflow="visible"></use></clipPath><path clip-path="url(#b)" fill="#FBBC05" d="M0 37V11l17 13z"></path><path clip-path="url(#b)" fill="#EA4335" d="M0 11l17 13 7-6.1L48 14V0H0z"></path><path clip-path="url(#b)" fill="#34A853" d="M0 37l30-23 7.9 1L48 0v48H0z"></path><path clip-path="url(#b)" fill="#4285F4" d="M48 48L17 24l-4-3 35-10z"></path></svg>
						</span>   <?php esc_attr_e( 'Sign Up with Google', 'atarim-visual-collaboration' ); ?>
					</a>
					<div class="supg-or"><span><?php esc_attr_e( 'or', 'atarim-visual-collaboration' ); ?></span></div>
					<?php wp_nonce_field( 'wpfeedback' ); ?>    
					<!-- new activation by Pratap-->
					<div class='wpf_signup_form'>
						<label><?php esc_attr_e( 'Your Full Name', 'atarim-visual-collaboration' ); ?></label><br>
						<span class='wpf-name-error'><?php esc_attr_e( 'Please add your full name', 'atarim-visual-collaboration' ); ?></span>
						<input type='text' name='username' class='wpf-user-name' placeholder='Webmaster Name' /><br>
						<label><?php esc_attr_e( 'Your Work Email Address', 'atarim-visual-collaboration' ); ?></label><br>
						<span class='wpf-email-error'><?php esc_attr_e( 'Please add your proper email address', 'atarim-visual-collaboration' ); ?></span>
						<input type='text' name='useremail' class='wpf-user-email' placeholder='name@yourdomain.com' /><br>
						<label><?php esc_attr_e( 'Set Password', 'atarim-visual-collaboration' ); ?></label><br>
						<input type='password' name='userpass' class='wpf-user-pass' placeholder='xxxxxxxxxx' /><br>
						<span class='wpf-pass-error wpf-pass-error-hide'>
							<?php esc_attr_e( 'Craft a strong password, including:', 'atarim-visual-collaboration' ); ?>
							<ul class="pass-error-msgs">
								<li class="wpf-pass-leng"><span class="wpf-pass-img"><img src="<?php echo esc_url( WPF_PLUGIN_URL . 'images/check-li.svg' ); ?>" alt="check li"></span><?php esc_attr_e( '8 characters or more', 'atarim-visual-collaboration' ); ?></li>
								<li class="wpf-pass-capnum"><span class="wpf-pass-img"><img src="<?php echo esc_url( WPF_PLUGIN_URL . 'images/check-li.svg' ); ?>" alt="check li"></span><?php esc_attr_e( 'One number and one capital letter', 'atarim-visual-collaboration' ); ?></li>
								<li class="wpf-pass-special"><span class="wpf-pass-img"><img src="<?php echo esc_url( WPF_PLUGIN_URL . 'images/check-li.svg' ); ?>" alt="check li"></span><?php esc_attr_e( 'A special character', 'atarim-visual-collaboration' ); ?></li>
							</ul>
						</span>
					</div>
					<span class='wpf-account-msg'></span>
					<div class="wpfeedback_licence_key_field">
					<?php echo '<button type="button" class="wpf_create_user" name="wpf_activate" access="false" id="ber_page4_save">Create a free Atarim Account</button>'; ?>
					</div>
					<?php
						printf( __( '<p class="wpf_tcpp">By opening an account I agree to the <a href="https://atarim.io/privacy-policy/" target="_blank">privacy policy</a>.</p>', 'atarim-visual-collaboration' ) );
					?>
					<?php
						$home_url = WPF_APP_SITE_URL . '?activation_callback=' . Base64_encode( WPF_SITE_URL ) . '&page_redirect=' . Base64_encode( "collaboration_page_settings" ) . '&site_url=' . Base64_encode( WPF_HOME_URL );
						echo '<p class="wpf_has_account" style="width:100%"><a class="wpf_account_link" href="' . $home_url . '">I already have an account (Login)</a></p>';
					?>
					<!--End new activation-->
				</div>
			</div>
        </form>
    </div>
	<div class="wpf_backend_initial_setup_image">
		<div>
			<img src="<?php echo esc_url( WPF_PLUGIN_URL . 'images/Websites-Mockup.png' ); ?>" alt="registration image">
		</div>
	</div>
</div>
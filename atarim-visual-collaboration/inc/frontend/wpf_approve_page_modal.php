<div class="wpf_wizard_container wpf_comment_container wpf_hide" data-html2canvas-ignore="true" id="approve_pg">
	<div class="wpf_approve_page_wrapper">
		<div class="wpf_wizard_modal wpf_approve_modal approve_confirm_modal">
			<a href="javascript:void(0)" class="wpf_approve_pg_close" data-dismiss="alert">×</a>
			<div class="wpf_wizard_title"><?php esc_attr_e( "Approve Page", 'atarim-visual-collaboration' ); ?></div>
			<p><?php esc_attr_e( "When the page is ready, click the green button to signal to the webmaster that <b>this page is approved.", 'atarim-visual-collaboration' ); ?></b></p>
				<div class="wpf_approve_check">
				<input type="checkbox" id="wpf_approve_all" name="approve"><label for="wpf_approve_all"><?php esc_attr_e( 'Mark all tasks on this page as complete', 'atarim-visual-collaboration' ); ?></label> 		
			</div>
		<button id="confirm_approve_page" class="wpf_green_btn approve-page" title="<?php esc_attr_e( 'Approve Page', 'atarim-visual-collaboration' ); ?>"><?php esc_attr_e( 'Approve Page', 'atarim-visual-collaboration' ); ?></button>
		<span id="approve_error" class="wpf_hide"><?php esc_attr_e( 'there was some error. Please try again.', 'atarim-visual-collaboration' ); ?></span>
		<div class="wpf_loader wpf_loader_approve_page wpf_hide"></div>
		</div>
		<div class="wpf_wizard_modal wpf_approve_modal wpf_page_approved wpf_hide">
		<a href="javascript:void(0)" class="wpf_approve_pg_close" data-dismiss="alert">×</a>
			<div class="wpf_wizard_title"><?php esc_attr_e( 'Page Approved', 'atarim-visual-collaboration' ); ?></div>
		</div>
	</div>
</div>
<style>
.wpf-uf-pop-wrapper {
    display: none;
    position: fixed;
    width: 100%;
    background-color: rgba(255, 255, 255, 0.95);
    height: 100%;
    font-size: 14px !important;
    color: #363d4d !important;
    text-decoration: none !important;
    font-family: 'Roboto', sans-serif;
    z-index: 999999;
    top: 0;
    left: 0;
}
.wpf-uf-pop-container {
    display: flex;
    align-items: center;
    height: 100%;
}
.wpf-uf-popup {
    box-shadow: 0em 0em 3em 0em rgb(0 0 0 / 13%);
    padding: 20px;
    border-radius: 5px;
    border: 1px solid #e3ebf6;
    background-color: #fff !important;
    width: 750px;
    max-width: 98%;
	box-sizing: border-box;
    margin: 0 auto;
    position: relative;
	max-height: 90vh;
    overflow-y: auto;
	-webkit-animation: fade-in-top 0.5s cubic-bezier(0.390, 0.575, 0.565, 1.000) both;
    animation: fade-in-top 0.5s cubic-bezier(0.390, 0.575, 0.565, 1.000) both !important;
}
.wpf-uf-close-popup {
    position: absolute;
    top: 15px;
    right: 20px;
    color: #E72D67 !important;
    cursor: pointer;
}
.wpf-uf-popup-title {
	line-height: 0.9 !important;
    font-size: 22px;
    font-weight: 700;
}
.wpf-uf-popup p {
    font-size: 14px !important;
    line-height: 1.5 !important;
    margin: 1em 0 0 0 !important;
}
.wpf-uf-popup-plans {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.wpf-uf-popup-image {
    flex: auto 1 0;
    width: 480px;
    max-width: 100%;
    margin-left: -20px;
	position: relative;
}
.wpf-uf-popup-image:before {
    width: 40%;
    height: 10%;
    display: block;
    content: "";
    left: 30%;
    top: 45%;
    position: absolute;
    box-shadow: 0px 0px 90px 80px #292170;
    z-index: -1;
    border-radius: 50%;
}
.wpf-uf-popup-image img {
    max-width: 100%;
}
.wpf-uf-plan {
    width: 100%;
    min-height: 200px;
    border: 1px solid #e3ebf6;
    border-radius: 5px 5px;
    padding: 15px 15px;
    display: flex;
	background-color: #fff;
    flex-direction: column;
    justify-content: space-between;
	box-sizing: border-box;
}
.wpf-uf-plan-title {
	font-size: 18px !important;
    font-weight: 700;
	}
.wpf-uf-plan-detail {
    font-size: 14px !important;
    line-height: 24px !important;
    font-weight: 400 !important;
    margin: 5px 0 10px 0;
}
.wpf-uf-plan a {
	color: #272d3c !important;
	text-decoration: none !important;
}
.wpf-uf-plan a:focus {
    box-shadow: none;
}
.wpf-uf-plan-btn {    
	color: #272d3c !important;
    background-color: #3ed696;
    border-radius: 5px;
    font-size: 14px;
    font-family: 'Roboto', sans-serif;
    font-weight: 500;
    cursor: pointer;
	height: 36px;
    padding: 0 20px;
    border: none;
	box-sizing: border-box;
	display: flex;
	align-items: center;
    justify-content: center;
    -webkit-transition: all 0.2s ease-in;
    -moz-transition: all 0.2s ease-in;
    -ms-transition: all 0.2s ease-in;
    -o-transition: all 0.2s ease-in;
    transition: all 0.2s ease-in;
}
.wpf-uf-plan-btn :hover {
    background-color: #33bf84;
}
.wpf-uf-webmaster-notice, .wpf-uf-otheruser-notice {
    color: #E72D67;
}
@media only screen and (max-width: 700px) {
	.wpf-uf-popup-image {
		width: 100%;
		margin: auto;
	}
	.wpf-uf-popup-plans {
		display: block;
	}
}
</style>
<div class="wpf-uf-pop-wrapper">
    <div class="wpf-uf-pop-container">
        <div class="wpf-uf-popup">
            <div class="wpf-uf-close-popup"><i class="gg-close"></i></div>
            <div class="wpf-uf-popup-title"><?php esc_attr_e( "Let's Unlock This Feature", 'atarim-visual-collaboration' ); ?></div>
            <p><?php esc_attr_e( 'Unlock this feature and improve your workflow even further by upgrading your plan.', 'atarim-visual-collaboration' ); ?></p>
            <div class="wpf-uf-popup-plans">
                <div class="wpf-uf-popup-image">
                    <img alt="" src="">
                </div>
                <div class="wpf-uf-plan">
                    <div class="wpf-uf-plan-title"></div>
                    <div class="wpf-uf-plan-detail"></div>
                    <a class="wpf-uf-plan-link" href="#" target="_blank"><div class="wpf-uf-plan-btn"><?php esc_attr_e( 'Upgrade To Unlock', 'atarim-visual-collaboration' ); ?></div></a>
                </div>
            </div>
            <div class="wpf-uf-webmaster-notice"><?php esc_attr_e( '*This is only shown to you as a webmaster, other users will not see this', 'atarim-visual-collaboration' ); ?></div>
            <div class="wpf-uf-otheruser-notice"><?php esc_attr_e( 'Please contact your webmaster to unlock this feature', 'atarim-visual-collaboration' ); ?></div>
        </div>
    </div>
</div>
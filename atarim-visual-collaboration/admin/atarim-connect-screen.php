<?php
/**
 * Atarim Connect Screen — first-run overlay.
 *
 * Full-screen overlay shown ON TOP of the plugin settings page for first-time
 * users (those who have never completed initial setup). It covers the settings
 * page and the wp-admin chrome so nothing behind it is visible. Self-contained:
 * no Tailwind, no React, no build step — plain CSS, CSS-keyframe motion, and a
 * few lines of vanilla JS.
 *
 * Rendering:
 *   require_once AVCF_PLUGIN_DIR . 'includes/atarim-connect-screen.php';
 *   atarim_connect_screen_render();   // echoes the overlay markup
 *
 * The caller decides WHEN to show it (gated on avc_initial_setup_complete in
 * the settings page renderer).
 *
 * Connect button:
 *   The primary CTA carries the class `avc-trigger-activate`, the same class as
 *   the settings-page connect button, so the settings page's inline script sets
 *   its activation URL and click behaviour on both buttons in one pass (single
 *   source of truth for the URL). It is marked `data-keep-label` so that shared
 *   script does not overwrite its styled label/icon.
 *
 * Close:
 *   The top-right dismiss button just hides the overlay (no reload); the
 *   settings page is already underneath.
 *
 * CSS scoping:
 *   All rules are scoped under #atarim-connect-overlay (and the .atc-* classes
 *   are uniquely namespaced), so nothing leaks into wp-admin. No global element
 *   or `*` selectors are emitted unscoped.
 *
 * Expert avatars are loaded from the app-side S3 bucket (see $s3 below).
 *
 * @package Atarim
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'atarim_connect_screen_render' ) ) {

    function atarim_connect_screen_render() {

        $site_host = function_exists( 'home_url' )
            ? wp_parse_url( home_url(), PHP_URL_HOST )
            : 'your-site.com';

        $s3 = 'https://atarim-wpfeedback-image.s3.eu-west-1.amazonaws.com/images/ai-bots/';

        $esc = function_exists( 'esc_html' ) ? 'esc_html' : 'htmlspecialchars';
        $url = function_exists( 'esc_url' ) ? 'esc_url' : 'htmlspecialchars';

        $t = function ( $s ) use ( $esc ) {
            return function_exists( '__' ) ? esc_html__( $s, 'atarim-visual-collaboration' ) : $esc( $s );
        };
        ?>
        <div id="atarim-connect-overlay" style="position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;background:#ffffff;color:#272D3C;font-family:Roboto,-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;-webkit-font-smoothing:antialiased;overflow:auto;">

            <button type="button" class="atc-dismiss" id="atc-dismiss" aria-label="<?php echo $t( 'Close' ); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
            </button>

            <div class="atc" id="atarim-connect" data-state="idle">

                <!-- connection visual -->
                <div class="atc-visual">
                    <div class="atc-glow" data-on="connecting" hidden></div>

                    <!-- WordPress tile -->
                    <div class="atc-tile atc-tile--wp">
                        <span class="atc-ping atc-ping--wp" data-on="connecting" hidden></span>
                        <span class="atc-ping atc-ping--done" data-on="connected" hidden></span>
                        <svg width="38" height="38" viewBox="0 0 32 31" fill="#21759B" aria-hidden="true"><path d="M16.234 16.746 13.558 24.312h-.008l-2.074 5.782c.145.039.286.07.434.109h.023c1.289.332 2.645.516 4.039.516.695 0 1.371-.04 2.035-.145.914-.109 1.793-.297 2.653-.558.211-.063.422-.137.636-.207-.23-.473-.718-1.527-.742-1.574Z"></path><path d="M1.688 9.563C.87 11.352.316 13.55.316 15.535c0 .496.023.996.075 1.485.562 5.632 4.316 10.363 9.476 12.488.211.086.434.176.652.254L2.93 9.57c-.653-.023-.778.016-1.242-.007Z"></path><path d="M30.21 9.16c-.35-.734-.769-1.437-1.234-2.101a15.13 15.13 0 0 0-.414-.571C26.804 4.211 24.414 2.422 21.629 1.379 19.883.715 17.973.352 15.98.352c-4.922 0-9.32 2.214-12.195 5.667-.531.633-1.004 1.313-1.43 2.028 1.16.007 2.598.007 2.762.007 1.476 0 3.754-.176 3.754-.176.769-.047.847 1.035.09 1.125 0 0-.766.086-1.618.125l5.137 14.789 3.086-8.961-2.188-5.82c-.769-.04-1.48-.125-1.48-.125-.766-.04-.668-1.172.082-1.125 0 0 2.328.176 3.715.176 1.476 0 3.758-.176 3.758-.176.757-.047.855 1.035.086 1.125 0 0-.758.086-1.606.125l5.086 14.68 1.41-4.559c.711-1.77 1.07-3.234 1.07-4.402 0-1.684-.629-2.856-1.168-3.766-.71-1.129-1.379-2.078-1.379-3.195 0-1.258.98-2.426 2.368-2.426.058 0 .12 0 .18.008 2.14-.055 2.839 2 2.929 3.398v.047c.035.57.008.988.008 1.488 0 1.375-.27 2.934-1.067 4.887l-3.183 8.922-1.82 5.195c.144-.062.285-.129.429-.199 4.629-2.164 8-6.484 8.711-11.601.106-.672.156-1.36.156-2.055 0-2.285-.523-4.453-1.453-6.39Z"></path></svg>
                    </div>

                    <!-- connector -->
                    <div class="atc-line">
                        <span class="atc-dash"></span>
                        <span class="atc-badge atc-badge--link" data-on="idle"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 17H7A5 5 0 0 1 7 7h2"></path><path d="M15 7h2a5 5 0 1 1 0 10h-2"></path><line x1="8" x2="16" y1="12" y2="12"></line></svg></span>
                        <span class="atc-badge atc-badge--check" data-on="connected" hidden><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg></span>
                        <span class="atc-dash"></span>

                        <!-- two-way traffic while connecting -->
                        <div class="atc-traffic" data-on="connecting" hidden>
                            <span class="atc-charge"></span>
                            <span class="atc-streak atc-streak--r"></span>
                            <span class="atc-streak atc-streak--l"></span>

                            <!-- WP -> Atarim (top lane) -->
                            <span class="atc-fly atc-fly--top atc-ic--ink" style="animation-delay:.3s"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.801 10A10 10 0 1 1 17 3.335"></path><path d="m9 11 3 3L22 4"></path></svg></span>
                            <span class="atc-fly atc-fly--av atc-fly--top" style="animation-delay:1.38s"><img src="<?php echo $url( $s3 . 'glitch.png' ); ?>" alt="Glitch"></span>
                            <span class="atc-fly atc-fly--top atc-ic--orange" style="animation-delay:2.46s"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"></path><path d="M9 18h6"></path><path d="M10 22h4"></path></svg></span>
                            <span class="atc-fly atc-fly--top atc-ic--pink" style="animation-delay:3.54s"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22a1 1 0 0 1 0-20 10 9 0 0 1 10 9 5 5 0 0 1-5 5h-2.25a1.75 1.75 0 0 0-1.4 2.8l.3.4a1.75 1.75 0 0 1-1.4 2.8z"></path><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"></circle><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"></circle><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"></circle><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"></circle></svg></span>
                            <span class="atc-fly atc-fly--av atc-fly--top" style="animation-delay:4.62s"><img src="<?php echo $url( $s3 . 'lexi.png' ); ?>" alt="Lexi"></span>

                            <!-- Atarim -> WP (bottom lane, returns start after first arrivals) -->
                            <span class="atc-fly atc-fly--bottom atc-ic--ink" style="animation-delay:2.75s"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 16 4-4-4-4"></path><path d="m6 8-4 4 4 4"></path><path d="m14.5 4-5 16"></path></svg></span>
                            <span class="atc-fly atc-fly--bottom atc-fly--claude" style="animation-delay:4.1s"><svg width="12" height="12" viewBox="0 0 24 24" fill="#ffffff" fill-rule="evenodd" aria-hidden="true"><path d="M4.709 15.955l4.72-2.647.08-.23-.08-.128H9.2l-.79-.048-2.698-.073-2.339-.097-2.266-.122-.571-.121L0 11.784l.055-.352.48-.321.686.06 1.52.103 2.278.158 1.652.097 2.449.255h.389l.055-.157-.134-.098-.103-.097-2.358-1.596-2.552-1.688-1.336-.972-.724-.491-.364-.462-.158-1.008.656-.722.881.06.225.061.893.686 1.908 1.476 2.491 1.833.365.304.145-.103.019-.073-.164-.274-1.355-2.446-1.446-2.49-.644-1.032-.17-.619a2.97 2.97 0 01-.104-.729L6.283.134 6.696 0l.996.134.42.364.62 1.414 1.002 2.229 1.555 3.03.456.898.243.832.091.255h.158V9.01l.128-1.706.237-2.095.23-2.695.08-.76.376-.91.747-.492.584.28.48.685-.067.444-.286 1.851-.559 2.903-.364 1.942h.212l.243-.242.985-1.306 1.652-2.064.73-.82.85-.904.547-.431h1.033l.76 1.129-.34 1.166-1.064 1.347-.881 1.142-1.264 1.7-.79 1.36.073.11.188-.02 2.856-.606 1.543-.28 1.841-.315.833.388.091.395-.328.807-1.969.486-2.309.462-3.439.813-.042.03.049.061 1.549.146.662.036h1.622l3.02.225.79.522.474.638-.079.485-1.215.62-1.64-.389-3.829-.91-1.312-.329h-.182v.11l1.093 1.068 2.006 1.81 2.509 2.33.127.578-.322.455-.34-.049-2.205-1.657-.851-.747-1.926-1.62h-.128v.17l.444.649 2.345 3.521.122 1.08-.17.353-.608.213-.668-.122-1.374-1.925-1.415-2.167-1.143-1.943-.14.08-.674 7.254-.316.37-.729.28-.607-.461-.322-.747.322-1.476.389-1.924.315-1.53.286-1.9.17-.632-.012-.042-.14.018-1.434 1.967-2.18 2.945-1.726 1.845-.414.164-.717-.37.067-.662.401-.589 2.388-3.036 1.44-1.882.93-1.086-.006-.158h-.055L4.132 18.56l-1.13.146-.487-.456.061-.746.231-.243 1.908-1.312-.006.006z"></path></svg></span>
                            <span class="atc-fly atc-fly--av atc-fly--bottom" style="animation-delay:5.45s"><img src="<?php echo $url( $s3 . 'pixel.png' ); ?>" alt="Pixel"></span>
                            <span class="atc-fly atc-fly--bottom atc-fly--codex" style="animation-delay:6.8s"><svg width="12" height="12" viewBox="0 0 24 24" fill="#ffffff" fill-rule="evenodd" aria-hidden="true"><path d="M9.205 8.658v-2.26c0-.19.072-.333.238-.428l4.543-2.616c.619-.357 1.356-.523 2.117-.523 2.854 0 4.662 2.212 4.662 4.566 0 .167 0 .357-.024.547l-4.71-2.759a.797.797 0 00-.856 0l-5.97 3.473zm10.609 8.8V12.06c0-.333-.143-.57-.429-.737l-5.97-3.473 1.95-1.118a.433.433 0 01.476 0l4.543 2.617c1.309.76 2.189 2.378 2.189 3.948 0 1.808-1.07 3.473-2.76 4.163zM7.802 12.703l-1.95-1.142c-.167-.095-.239-.238-.239-.428V5.899c0-2.545 1.95-4.472 4.591-4.472 1 0 1.927.333 2.712.928L8.23 5.067c-.285.166-.428.404-.428.737v6.898zM12 15.128l-2.795-1.57v-3.33L12 8.658l2.795 1.57v3.33L12 15.128zm1.796 7.23c-1 0-1.927-.332-2.712-.927l4.686-2.712c.285-.166.428-.404.428-.737v-6.898l1.974 1.142c.167.095.238.238.238.428v5.233c0 2.545-1.974 4.472-4.614 4.472zm-5.637-5.303l-4.544-2.617c-1.308-.761-2.188-2.378-2.188-3.948A4.482 4.482 0 014.21 6.327v5.423c0 .333.143.571.428.738l5.947 3.449-1.95 1.118a.432.432 0 01-.476 0zm-.262 3.9c-2.688 0-4.662-2.021-4.662-4.519 0-.19.024-.38.047-.57l4.686 2.71c.286.167.571.167.856 0l5.97-3.448v2.26c0 .19-.07.333-.237.428l-4.543 2.616c-.619.357-1.356.523-2.117.523zm5.899 2.83a5.947 5.947 0 005.827-4.756C22.287 18.339 24 15.84 24 13.296c0-1.665-.713-3.282-1.998-4.448.119-.5.19-.999.19-1.498 0-3.401-2.759-5.947-5.946-5.947-.642 0-1.26.095-1.88.31A5.962 5.962 0 0010.205 0a5.947 5.947 0 00-5.827 4.757C1.713 5.447 0 7.945 0 10.49c0 1.666.713 3.283 1.998 4.448-.119.5-.19 1-.19 1.499 0 3.401 2.759 5.946 5.946 5.946.642 0 1.26-.095 1.88-.309a5.96 5.96 0 004.162 1.713z"></path></svg></span>
                        </div>
                    </div>

                    <!-- Atarim tile: spinner mark (assets/atarim-spinner-light.svg baked with its animation) -->
                    <div class="atc-tile atc-tile--atarim atc-spinner">
                        <span class="atc-ping atc-ping--atarim" data-on="connecting" hidden></span>
                        <span class="atc-ping atc-ping--done" data-on="connected" hidden></span>
                        <svg viewBox="1 -5 36 34" width="56" height="53" role="img" aria-label="Atarim">
                            <path class="mountain" d="M16 3 L28 27 L20.5 27 L16 16.5 L11.5 27 L4 27 Z" fill="#16042E"></path>
                            <circle class="ring" cx="25.5" cy="6.3" r="5.2" fill="none" stroke="#6D5DF3" stroke-width="0.45"></circle>
                            <g class="starIn"><g class="starPulse"><g transform="translate(21.7 2.5) scale(0.2375)"><path d="M16 0 L20.62 11.38 L32 16 L20.62 20.62 L16 32 L11.38 20.62 L0 16 L11.38 11.38 Z" fill="#6D5DF3"></path></g></g></g>
                        </svg>
                    </div>
                </div>

                <!-- headline + description -->
                <div data-on="idle connecting" style="display:flex;flex-direction:column;align-items:center;width:100%">
                    <h1 class="atc-h1"><?php echo $t( 'Connect WordPress to Atarim' ); ?></h1>
                    <p class="atc-desc"><?php echo $t( 'Reviews, tasks and real edits land directly on your live site. Plus any AI your team uses, through the Atarim MCP.' ); ?></p>
                </div>
                <div data-on="connected" hidden style="display:flex;flex-direction:column;align-items:center;width:100%">
                    <h1 class="atc-h1"><?php echo $t( "You're connected" ); ?></h1>
                    <p class="atc-desc"><b><?php echo $esc( $site_host ); ?></b> <?php echo $t( 'is now linked to Atarim. You can close this window and head back to WordPress.' ); ?></p>
                </div>

                <!-- site row -->
                <div class="atc-site">
                    <div class="atc-site-row">
                        <span class="atc-site-ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path><path d="M2 12h20"></path></svg></span>
                        <div style="min-width:0">
                            <p class="atc-site-label"><?php echo $t( 'This site' ); ?></p>
                            <p class="atc-site-value"><?php echo $esc( $site_host ); ?></p>
                        </div>
                    </div>
                </div>

                <!-- CTA -->
                <div class="atc-cta">
                    <a class="atc-btn avc-trigger-activate" id="atc-connect-btn" data-on="idle" data-keep-label href="javascript:void(0)" target="_blank" rel="noopener" role="button">
                        <?php echo $t( 'Connect site' ); ?>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
                    </a>
                    <button type="button" class="atc-btn" data-on="connecting" hidden disabled>
                        <svg class="atc-spin" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
                        <?php echo $t( 'Connecting...' ); ?>
                    </button>
                    <button type="button" class="atc-btn atc-btn--secondary" id="atc-close-btn" data-on="connected" hidden>
                        <?php echo $t( 'Close window' ); ?>
                    </button>
                </div>

                <!-- footer -->
                <div class="atc-foot">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path><path d="m9 12 2 2 4-4"></path></svg>
                    <?php echo $t( 'Secured by Atarim. Disconnect anytime from your site settings.' ); ?>
                </div>

            </div>
        </div>

        <script>
            (function () {
                var overlay = document.getElementById('atarim-connect-overlay');
                if (!overlay) { return; }
                var root = document.getElementById('atarim-connect');

                // Toggle the three visual states (idle / connecting / connected).
                function setState(state) {
                    if (!root) { return; }
                    root.setAttribute('data-state', state);
                    var els = root.querySelectorAll('[data-on]');
                    for (var i = 0; i < els.length; i++) {
                        els[i].hidden = els[i].getAttribute('data-on').split(' ').indexOf(state) === -1;
                    }
                }

                // Exposed so the settings-page connect flow can drive the visual if desired.
                window.AtarimConnect = { setState: setState };

                function hideOverlay() { overlay.style.display = 'none'; }

                // Top-right dismiss and the connected-state "Close window" button both just
                // hide the overlay — the settings page is already underneath, no reload.
                var dismissBtn = document.getElementById('atc-dismiss');
                if (dismissBtn) { dismissBtn.addEventListener('click', hideOverlay); }

                var closeBtn = document.getElementById('atc-close-btn');
                if (closeBtn) { closeBtn.addEventListener('click', hideOverlay); }

                // The connect CTA (#atc-connect-btn) carries `avc-trigger-activate`; the
                // settings page's inline script sets its href + click behaviour, same as the
                // settings-page connect button. No extra handler needed here.

                setState('idle');
            })();
        </script>
        <?php
    }
}
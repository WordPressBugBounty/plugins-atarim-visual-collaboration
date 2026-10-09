<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Lets the Atarim app frame the front end even when the host adds
 * X-Frame-Options at the web server (Seravo's nginx, for one), which PHP
 * cannot remove. Browsers ignore X-Frame-Options when a Content-Security-Policy
 * response header carries frame-ancestors, so a policy allowing 'self' plus
 * the app origins is enough.
 *
 * The header is sent only while Atarim is the framer: on the stage's first
 * load (the app adds atarimStage=true) and on later in-frame navigations,
 * which carry a Partitioned cookie the browser only returns while this site is
 * framed under the app. Anyone else who frames the site keeps what they had.
 *
 * It cannot help when the host or a CDN sends its own frame-ancestors (policies
 * intersect), strips headers set by PHP, or serves the page from a cache that
 * PHP never sees. The app falls back to its proxy then.
 */
class AVCF_Frame_Ancestors {
    const COOKIE = 'avcf_atarim_frame';
    const COOKIE_TTL = 12 * HOUR_IN_SECONDS;

    /** The origins applied to this response, once it qualified. */
    private $applied_origins = [];

    public function __construct() {
        // send_headers only fires for front-end requests, so wp-admin and the
        // login screen keep the X-Frame-Options WordPress core sends there.
        // Late priority, so CSP headers other plugins set are already listed.
        add_action('send_headers', [$this, 'maybe_allow_atarim_framing'], PHP_INT_MAX);
        // Some plugins send their CSP later, from template_redirect. Merge again
        // there, or their frame-ancestors would intersect with ours and win.
        add_action('template_redirect', [$this, 'merge_late_policies'], PHP_INT_MAX);
    }

    public function maybe_allow_atarim_framing() {
        if (headers_sent() || ! $this->is_atarim_frame()) {
            return;
        }

        $origins = $this->allowed_origins();
        if (empty($origins) || ! $this->add_frame_ancestors($origins)) {
            return;
        }
        $this->applied_origins = $origins;

        // This response differs from what everyone else gets for the same URL,
        // so no shared cache may store it or hand it to anyone else.
        nocache_headers();
        header('Vary: Cookie, Sec-Fetch-Dest', false);

        if (! isset($_COOKIE[self::COOKIE]) && is_ssl()) {
            // Built by hand: setcookie() has no Partitioned attribute.
            header(
                'Set-Cookie: ' . self::COOKIE . '=1; Path=/; Max-Age=' . self::COOKIE_TTL
                . '; Secure; HttpOnly; SameSite=None; Partitioned',
                false
            );
        }
    }

    public function merge_late_policies() {
        if (empty($this->applied_origins) || headers_sent()) {
            return;
        }
        $this->add_frame_ancestors($this->applied_origins);
    }

    private function is_atarim_frame() {
        // Sec-Fetch-Dest is absent on older browsers; when present, only a
        // frame navigation can need frame-ancestors.
        $dest = isset($_SERVER['HTTP_SEC_FETCH_DEST'])
            ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_SEC_FETCH_DEST'])))
            : '';
        if ($dest !== '' && $dest !== 'iframe') {
            return false;
        }

        if (
            isset($_GET['atarimStage']) &&
            filter_var(wp_unslash($_GET['atarimStage']), FILTER_VALIDATE_BOOLEAN)
        ) {
            return true;
        }

        return isset($_COOKIE[self::COOKIE]);
    }

    /**
     * The app origin this build talks to plus the other Atarim app origins
     * (a policy naming only one would block the rest from sites that framed
     * fine before), named one by one: a *.atarim.io wildcard would also trust
     * subdomains served by third parties. More can be added through the
     * avcf_frame_ancestors filter. Only real origins survive, so a filter
     * cannot smuggle in extra directives or a bare wildcard.
     */
    private function allowed_origins() {
        $origins = ['https://app.atarim.io', 'https://alpha.atarim.io'];

        $app = wp_parse_url(AVCF_APP_SITE_URL);
        if (! empty($app['scheme']) && ! empty($app['host'])) {
            $origins[] = $app['scheme'] . '://' . $app['host'] . (isset($app['port']) ? ':' . $app['port'] : '');
        }

        $origins = (array) apply_filters('avcf_frame_ancestors', $origins);

        $origins = array_filter($origins, function ($origin) {
            return is_string($origin) && (
                preg_match('#^https?://(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(:\d+)?$#i', $origin)
                || preg_match('#^https?://localhost(:\d+)?$#i', $origin)
            );
        });

        return array_values(array_unique($origins));
    }

    /**
     * Adds the origins to the CSP this response already carries, or sends a
     * policy of its own. A second policy would not widen an existing
     * frame-ancestors (every policy must pass), so an existing directive is
     * extended in place. 'none' is a deliberate choice by the site and is left
     * alone. Returns whether framing by the app is now allowed.
     */
    private function add_frame_ancestors(array $origins) {
        $policies = [];
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Security-Policy:') === 0) {
                $policies[] = trim(substr($header, strlen('Content-Security-Policy:')));
            }
        }

        $extended = false;
        foreach ($policies as $i => $policy) {
            $directives = array_map('trim', explode(';', $policy));
            foreach ($directives as $j => $directive) {
                $parts = preg_split('/\s+/', $directive);
                if (strtolower($parts[0]) !== 'frame-ancestors') {
                    continue;
                }
                $sources = array_slice($parts, 1);
                if (in_array("'none'", array_map('strtolower', $sources), true)) {
                    return false;
                }
                $directives[$j] = implode(' ', array_merge(
                    ['frame-ancestors'],
                    $sources,
                    array_diff($origins, $sources)
                ));
                $extended = true;
            }
            $policies[$i] = implode('; ', array_filter($directives, 'strlen'));
        }

        if (! $extended) {
            header("Content-Security-Policy: frame-ancestors 'self' " . implode(' ', $origins), false);
            return true;
        }

        header_remove('Content-Security-Policy');
        foreach ($policies as $policy) {
            header('Content-Security-Policy: ' . $policy, false);
        }
        return true;
    }
}

new AVCF_Frame_Ancestors();

<?php

namespace Lotzwoo\Emails;

use Lotzwoo\Plugin;
use WC_Email;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lets shop owners replace baked-in WooCommerce email copy, per email.
 *
 * Master switch: Plugin option `emails_advanced_editing_enabled`, plus a
 * per-email opt-in. Settings live in WooCommerce's own
 * `woocommerce_{$id}_settings` option (added via the settings-api filter).
 *
 * - Order/POS emails: the greeting + intro block (between the header and
 *   order-details hooks) is captured via output buffering and replaced.
 * - Reset-password email: it has no hooks, so a plugin-owned template
 *   override exposes the greeting / intro / post-username fragments.
 * - Any other (hookless) email: the whole body between the header and
 *   footer hooks is captured via output buffering and replaced as one block.
 */
class Advanced_Editor
{
    private const FIELD_PREFIX = 'lotzwoo_adv_';

    /**
     * Emails whose HTML template calls `woocommerce_email_order_details` —
     * i.e. has the header → order-details → footer span that Strategy A needs
     * to do intro-only replacement. Every other email falls through to
     * Strategy C (full-body replace between header and footer), or Strategy B
     * (template override) for reset-password.
     *
     * This is an explicit whitelist, not a heuristic, because:
     *   - The natural runtime signal {`{order_number}` in $placeholders}
     *     is unreliable: WooCommerce's EmailPreview class adds
     *     {order_number} + {order_date} placeholders to EVERY email
     *     (incl. customer_new_account) so previews can show dummy values.
     *     That made our previous heuristic mis-classify non-order emails
     *     as Strategy A → intro buffer opened → never closed (no
     *     order_details hook) → safety net flushed raw original → user's
     *     custom text silently ignored.
     *   - Templates that look "order-flavoured" but lack the hook
     *     (fulfillment, SEPA mandate, renewal/switch subscriptions) get
     *     correctly routed to Strategy C without needing a separate
     *     blacklist.
     *
     * Add a new id here only if its bundled HTML template calls
     * `do_action('woocommerce_email_order_details', …)` between header and
     * footer. Audited against: WC core 10.x, Germanized 3.x, WC POS,
     * Subscriptions (wpswings + subscriptions-core).
     */
    private const INTRO_REPLACE_IDS = [
        // WC core order emails
        'new_order',
        'cancelled_order',
        'failed_order',
        'customer_cancelled_order',
        'customer_completed_order',
        'customer_failed_order',
        'customer_invoice',
        'customer_note',
        'customer_on_hold_order',
        'customer_processing_order',
        'customer_refunded_order',
        // WC POS variants (use woocommerce_pos_email_* header/footer but
        // still call the standard woocommerce_email_order_details).
        'customer_pos_completed_order',
        'customer_pos_refunded_order',
        // WooCommerce Germanized
        'customer_paid_for_order',
        // wpswings subscriptions-pro — only one template uses order_details
        'wps_wsp_renewal_subscription_invoice',
    ];

    /** Strategy B: greeting/intro block capture state. */
    private bool $intro_buffering = false;
    private ?WC_Email $intro_buffer_email = null;

    /** Generic full-body capture state (hookless non-order, non-reset emails). */
    private bool $body_buffering = false;
    private ?WC_Email $body_buffer_email = null;

    /** Strategy C: template-side access for plugin-owned email templates. */
    private static ?self $instance = null;

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function register(): void
    {
        self::$instance = $this;

        if (is_admin()) {
            add_action('woocommerce_init', [$this, 'register_email_field_filters']);
            // Warm the intro-default snapshot on the email's settings page so
            // the placeholder shows on first visit (no render-then-reload).
            add_action('admin_init', [$this, 'maybe_warm_intro_snapshot']);
        }

        // Strategy B: plugin-owned template overrides for emails where the
        // user-editable spots are individual fragments inside a baked-in
        // structure (greeting + intro + post-username text), not one big
        // body. Hooked on wc_get_template (not woocommerce_locate_template)
        // because WC caches the located path and skips wc_locate_template on
        // cache hits; wc_get_template runs every call. Still only substitutes
        // when no theme overrides the template.
        add_filter('wc_get_template', [$this, 'override_reset_password_template'], 10, 5);
        add_filter('wc_get_template', [$this, 'override_new_account_template'], 10, 5);

        // Strategy B: replace the baked-in greeting/intro block. It lives in the
        // template between the header and order-details hooks, so we buffer that
        // span and swap it when a per-email override is set. HTML emails only
        // (plain-text templates don't fire woocommerce_email_header).
        add_action('woocommerce_email_header', [$this, 'capture_intro_start'], 99999, 2);
        add_action('woocommerce_pos_email_header', [$this, 'capture_intro_start'], 99999, 2);
        add_action('woocommerce_email_order_details', [$this, 'capture_intro_end'], -99999, 4);
        // Safety net: never leave a buffer open if order-details never fires.
        add_action('woocommerce_email_footer', [$this, 'intro_buffer_safety'], -100000, 1);
        add_action('woocommerce_pos_email_footer', [$this, 'intro_buffer_safety'], -100000, 1);

        // Generic: replace the whole body (header -> footer span) for hookless
        // non-order, non-reset emails (new account, fulfillment, stock, …).
        add_action('woocommerce_email_header', [$this, 'capture_body_start'], 99998, 2);
        add_action('woocommerce_pos_email_header', [$this, 'capture_body_start'], 99998, 2);
        add_action('woocommerce_email_footer', [$this, 'capture_body_end'], -99998, 1);
        add_action('woocommerce_pos_email_footer', [$this, 'capture_body_end'], -99998, 1);
    }

    public function register_email_field_filters(): void
    {
        if (!$this->feature_enabled() || !function_exists('WC')) {
            return;
        }

        $mailer = WC()->mailer();
        if (!$mailer) {
            return;
        }

        foreach ($mailer->get_emails() as $email) {
            if ($email instanceof WC_Email && $email->id !== '') {
                $email_id = $email->id;
                $is_order = $this->is_order_email($email);
                // For the legend, "has order context" is the broader question
                // (placeholder advertising) — independent of which buffer strategy
                // we use for replacement.
                $has_order = array_key_exists('{order_number}', (array) $email->placeholders);
                add_filter(
                    'woocommerce_settings_api_form_fields_' . $email_id,
                    function (array $fields) use ($email_id, $is_order, $has_order): array {
                        return $this->inject_fields($fields, $email_id, $is_order, $has_order);
                    },
                    20
                );
            }
        }
    }

    /**
     * Whether this email gets the intro-replace strategy (Strategy A): its
     * HTML template has the header → order_details → footer span we can
     * buffer between. Driven by the explicit whitelist above, not by the
     * runtime placeholders (which EmailPreview tampers with).
     */
    private function is_order_email(WC_Email $email): bool
    {
        return in_array($email->id, self::INTRO_REPLACE_IDS, true);
    }

    /**
     * Emails that use the LotzApp template-override strategy (Strategy B):
     * the user-editable spots are individual fragments inside a baked-in
     * template structure, not one big body buffer.
     */
    private const TEMPLATE_OVERRIDE_IDS = [
        'customer_reset_password',
        'customer_new_account',
    ];

    /**
     * Hookless emails that get the generic full-body replace: everything that
     * is neither an intro-replace order email nor a template-override email.
     */
    private function is_body_replace_email(WC_Email $email): bool
    {
        if ($this->is_order_email($email)) {
            return false;
        }
        return !in_array($email->id, self::TEMPLATE_OVERRIDE_IDS, true);
    }

    /**
     * On a specific WooCommerce email settings page, if the default snapshot
     * (intro for order emails, full body for hookless ones) hasn't been
     * captured yet, render the email once via WooCommerce's own preview
     * generator (dummy order, no send). Our capture hooks fire during that
     * render and store the snapshot, so the field placeholder is available on
     * the very first visit. Defensive: any failure degrades to the previous
     * render-then-reload behaviour.
     */
    public function maybe_warm_intro_snapshot(): void
    {
        if (!$this->feature_enabled() || !function_exists('wc_get_container') || !function_exists('WC')) {
            return;
        }

        $page    = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';       // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab     = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';          // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $section = isset($_GET['section']) ? sanitize_title(wp_unslash($_GET['section'])) : '';        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($page !== 'wc-settings' || $tab !== 'email' || $section === '') {
            return;
        }

        $mailer = WC()->mailer();
        if (!$mailer) {
            return;
        }

        $target = null;
        foreach ($mailer->get_emails() as $email) {
            if ($email instanceof WC_Email
                && (strtolower(get_class($email)) === $section || $email->id === $section)) {
                $target = $email;
                break;
            }
        }

        if (!$target instanceof WC_Email || $target->id === 'customer_reset_password') {
            return; // reset-password uses derived defaults, no snapshot needed
        }

        $type = $this->is_order_email($target) ? 'intro' : 'body';
        if ($this->default_snapshot($target->id, $type) !== '') {
            return; // already captured
        }

        $preview_class = 'Automattic\\WooCommerce\\Internal\\Admin\\EmailPreview\\EmailPreview';
        if (!class_exists($preview_class)) {
            return;
        }

        $level = ob_get_level();
        try {
            $preview = wc_get_container()->get($preview_class);
            if (is_object($preview) && method_exists($preview, 'set_email_type') && method_exists($preview, 'render')) {
                $preview->set_email_type(get_class($target));
                ob_start();
                $preview->render(); // fires our capture hooks -> store_default_snapshot()
                ob_end_clean();
            }
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * Swap in our reset-password template, but ONLY when this email is opted in
     * and has at least one fragment override. Otherwise the original template
     * (incl. a theme override) is left untouched, so unconfigured emails keep
     * their original markup. This intentionally wins over a theme override for
     * configured emails — the only way to expose the fragments on a hookless
     * template.
     *
     * Hooked on the wc_get_template filter:
     * apply_filters('wc_get_template', $template, $template_name, $args, $template_path, $default_path).
     *
     * @param mixed $template
     * @param mixed $template_name
     * @param mixed $args
     * @param mixed $template_path
     * @param mixed $default_path
     * @return mixed
     */
    public function override_reset_password_template($template, $template_name, $args = array(), $template_path = '', $default_path = '')
    {
        if ($template_name !== 'emails/customer-reset-password.php' || !$this->feature_enabled()) {
            return $template;
        }

        $email = (is_array($args) && isset($args['email'])) ? $args['email'] : null;
        if (!$email instanceof WC_Email || !$this->email_active($email)) {
            return $template;
        }

        $configured = false;
        foreach (['reset_greeting', 'reset_intro', 'reset_after'] as $fragment) {
            $value = $email->get_option(self::FIELD_PREFIX . $fragment, '');
            if (is_string($value) && trim($value) !== '') {
                $configured = true;
                break;
            }
        }
        if (!$configured) {
            return $template;
        }

        $override = LOTZWOO_PLUGIN_DIR . 'includes/Emails/templates/customer-reset-password.php';
        return file_exists($override) ? $override : $template;
    }

    /**
     * Swap in our customer-new-account template, but ONLY when this email is
     * opted in and has at least one fragment override. Same semantics as the
     * reset-password override: only kicks in for configured emails so we
     * don't shadow theme overrides for users who haven't opted into LotzApp.
     *
     * Two fragments: account_greeting (Hi <user>, + "Thanks for creating
     * an account…") and account_after ("You can access your account area…").
     *
     * @param mixed $template
     * @param mixed $template_name
     * @param mixed $args
     * @param mixed $template_path
     * @param mixed $default_path
     * @return mixed
     */
    public function override_new_account_template($template, $template_name, $args = array(), $template_path = '', $default_path = '')
    {
        if ($template_name !== 'emails/customer-new-account.php' || !$this->feature_enabled()) {
            return $template;
        }

        $email = (is_array($args) && isset($args['email'])) ? $args['email'] : null;
        if (!$email instanceof WC_Email || !$this->email_active($email)) {
            return $template;
        }

        $configured = false;
        foreach (['account_greeting', 'account_after'] as $fragment) {
            $value = $email->get_option(self::FIELD_PREFIX . $fragment, '');
            if (is_string($value) && trim($value) !== '') {
                $configured = true;
                break;
            }
        }
        if (!$configured) {
            return $template;
        }

        $override = LOTZWOO_PLUGIN_DIR . 'includes/Emails/templates/customer-new-account.php';
        return file_exists($override) ? $override : $template;
    }

    /**
     * Template-side helper: per-email override for a hardcoded fragment, or the
     * WooCommerce default when empty / feature off / email not opted in.
     *
     * @param array<string, string> $extra extra placeholders (e.g. {username})
     */
    public function fragment(WC_Email $email, string $key, string $default_html, array $extra = []): string
    {
        if (!$this->feature_enabled() || !$this->email_active($email)) {
            return $default_html;
        }

        $raw = $email->get_option(self::FIELD_PREFIX . $key, '');
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return $default_html;
        }

        // Per-email content translation: __() returns the source string
        // unchanged if no translation .mo is loaded for this locale.
        $raw = $this->translate_email_content($email, $raw);

        $content = $this->format($email, $raw);
        if ($extra) {
            $content = strtr($content, $extra);
        }

        return wp_kses_post(wpautop(wptexturize($content)));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function inject_fields(array $fields, string $email_id = '', bool $is_order_email = true, bool $has_order_context = true): array
    {
        $is_reset       = ($email_id === 'customer_reset_password');
        $is_new_account = ($email_id === 'customer_new_account');
        // Everything that isn't an order email and doesn't have its own
        // fragment-based template override (reset password / new account)
        // gets the generic full-body replace (fulfillment, stock, …).
        $is_generic = (!$is_order_email && !$is_reset && !$is_new_account);

        $box = 'width:100%; min-height:120px; font-family:monospace;';

        $fields[self::FIELD_PREFIX . 'title'] = [
            'title'       => __('LotzApp – Erweiterte Inhalte', 'lotzapp-for-woocommerce'),
            'type'        => 'title',
            'description' => $this->legend_html($email_id, $has_order_context),
        ];
        $fields[self::FIELD_PREFIX . 'enabled'] = [
            'title'   => __('Aktivieren', 'lotzapp-for-woocommerce'),
            'type'    => 'checkbox',
            'label'   => __('LotzApp-Textersetzung für diese E-Mail aktivieren', 'lotzapp-for-woocommerce'),
            'default' => 'no',
        ];
        if ($is_order_email) {
            $intro_default = $this->default_snapshot($email_id, 'intro');
            $intro_desc    = __('Leer = WooCommerce-Standard bleibt unverändert (siehe Vorschautext im Feld). Ausgefüllt = ersetzt den kompletten Block zwischen Header und Bestelltabelle (Begrüßung + Einleitung). HTML & Platzhalter erlaubt. Nur HTML-E-Mails.', 'lotzapp-for-woocommerce');
            if ($intro_default === '') {
                $intro_desc .= ' ' . __('Hinweis: Lade die E-Mail-Vorschau oder sende eine Test-E-Mail und lade die Seite neu, dann erscheint der aktuelle WooCommerce-Standard hier als Vorschautext.', 'lotzapp-for-woocommerce');
            }
            $fields[self::FIELD_PREFIX . 'intro'] = [
                'title'       => __('Begrüßung/Einleitung ersetzen', 'lotzapp-for-woocommerce'),
                'type'        => 'textarea',
                'css'         => $box,
                'default'     => '',
                'placeholder' => $intro_default,
                'description' => $intro_desc,
            ];
        }
        if ($is_reset) {
            $improvements = class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')
                && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled('email_improvements');
            $after_default = $improvements
                ? __('If you didn’t make this request, just ignore this email. If you’d like to proceed, reset your password via the link below:', 'woocommerce')
                : __('If you didn\'t make this request, just ignore this email. If you\'d like to proceed:', 'woocommerce');
            $reset_desc = __('Leer = WooCommerce-Standard (im Feld als Vorschautext). Ausgefüllt = ersetzt diesen Abschnitt. HTML & Platzhalter erlaubt, zusätzlich {username} und {reset_password_url}. Nur HTML-E-Mails.', 'lotzapp-for-woocommerce');

            $fields[self::FIELD_PREFIX . 'reset_greeting'] = [
                'title'       => __('Begrüßung ersetzen', 'lotzapp-for-woocommerce'),
                'type'        => 'textarea',
                'css'         => $box,
                'default'     => '',
                'placeholder' => '<p>' . sprintf(__('Hi %s,', 'woocommerce'), '{username}') . '</p>',
                'description' => $reset_desc,
            ];
            $fields[self::FIELD_PREFIX . 'reset_intro'] = [
                'title'       => __('Einleitung ersetzen', 'lotzapp-for-woocommerce'),
                'type'        => 'textarea',
                'css'         => $box,
                'default'     => '',
                'placeholder' => '<p>' . sprintf(__('Someone has requested a new password for the following account on %s:', 'woocommerce'), '{site_title}') . '</p>',
                'description' => $reset_desc,
            ];
            $fields[self::FIELD_PREFIX . 'reset_after'] = [
                'title'       => __('Text nach dem Benutzernamen ersetzen', 'lotzapp-for-woocommerce'),
                'type'        => 'textarea',
                'css'         => $box,
                'default'     => '',
                'placeholder' => '<p>' . $after_default . '</p>',
                'description' => $reset_desc,
            ];
        }
        if ($is_new_account) {
            $improvements = class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')
                && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled('email_improvements');
            $account_desc = __('Leer = WooCommerce-Standard (im Feld als Vorschautext). Ausgefüllt = ersetzt diesen Abschnitt der „Neuer Account"-E-Mail. HTML & Platzhalter erlaubt, zusätzlich {username}. Nur HTML-E-Mails.', 'lotzapp-for-woocommerce');

            // Greeting fragment: in improvements mode this covers both the
            // "Hi <user>," line and the "Thanks for creating an account…"
            // paragraph as one editable block; in legacy mode it's just the
            // "Hi <user>," line (the rest is folded into the after fragment).
            if ($improvements) {
                $greeting_placeholder = '<p>' . sprintf(__('Hi %s,', 'woocommerce'), '{username}') . '</p>'
                    . '<p>' . sprintf(__('Thanks for creating an account on %s. Here&rsquo;s a copy of your user details.', 'woocommerce'), '{site_title}') . '</p>';
                $after_placeholder    = '<p>' . __('You can access your account area to view orders, change your password, and more via the link below:', 'woocommerce') . '</p>';
            } else {
                $greeting_placeholder = '<p>' . sprintf(__('Hi %s,', 'woocommerce'), '{username}') . '</p>';
                $after_placeholder    = '<p>' . __('Your username is {username}. You can access your account area to view orders, change your password, and more.', 'lotzapp-for-woocommerce') . '</p>';
            }

            $fields[self::FIELD_PREFIX . 'account_greeting'] = [
                'title'       => __('Begrüßung + Einleitung ersetzen', 'lotzapp-for-woocommerce'),
                'type'        => 'textarea',
                'css'         => $box,
                'default'     => '',
                'placeholder' => $greeting_placeholder,
                'description' => $account_desc,
            ];
            $fields[self::FIELD_PREFIX . 'account_after'] = [
                'title'       => __('Text nach dem Benutzernamen ersetzen', 'lotzapp-for-woocommerce'),
                'type'        => 'textarea',
                'css'         => $box,
                'default'     => '',
                'placeholder' => $after_placeholder,
                'description' => $account_desc,
            ];
        }
        if ($is_generic) {
            $body_default = $this->default_snapshot($email_id, 'body');
            $body_desc    = __('Leer = WooCommerce-Standard bleibt unverändert (siehe Vorschautext im Feld). Ausgefüllt = ersetzt den kompletten Inhalt zwischen Header und Footer dieser E-Mail. HTML & Platzhalter erlaubt. Nur HTML-E-Mails.', 'lotzapp-for-woocommerce');
            if ($body_default === '') {
                $body_desc .= ' ' . __('Hinweis: Lade die E-Mail-Vorschau oder sende eine Test-E-Mail und lade die Seite neu, dann erscheint der aktuelle WooCommerce-Standard hier als Vorschautext.', 'lotzapp-for-woocommerce');
            }
            $fields[self::FIELD_PREFIX . 'body'] = [
                'title'       => __('Ganze E-Mail ersetzen', 'lotzapp-for-woocommerce'),
                'type'        => 'textarea',
                'css'         => 'width:100%; min-height:220px; font-family:monospace;',
                'default'     => '',
                'placeholder' => $body_default,
                'description' => $body_desc,
            ];
        }

        return $fields;
    }

    private function legend_html(string $email_id, bool $has_order_context): string
    {
        $tags = ['{site_title}', '{site_address}', '{site_url}', '{store_email}'];

        if ($has_order_context) {
            $tags = array_merge($tags, ['{order_number}', '{order_date}', '{customer_first_name}', '{customer_full_name}']);
        }

        if ($email_id === 'customer_reset_password') {
            $tags = array_merge($tags, ['{username}', '{reset_password_url}']);
        }

        if ($email_id === 'customer_new_account') {
            $tags = array_merge($tags, ['{username}']);
        }

        $codes = array_map(
            static function (string $tag): string {
                return '<code>' . esc_html($tag) . '</code>';
            },
            $tags
        );

        return wp_kses_post(
            esc_html__('Verfügbare Platzhalter für diese E-Mail:', 'lotzapp-for-woocommerce')
            . ' ' . implode(', ', $codes) . '. '
            . esc_html__('Zusätzlich gelten die Standard-Platzhalter dieser WooCommerce-E-Mail.', 'lotzapp-for-woocommerce')
        );
    }

    private function zone_content(WC_Email $email, string $zone): string
    {
        $value = $email->get_option(self::FIELD_PREFIX . $zone, '');
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Resolve placeholders. WC's own format_string() only knows the tags the
     * specific email defines (e.g. the admin "new order" email has no customer
     * tags), so we additionally resolve the tags we advertise in the legend
     * straight from the order.
     *
     * @param mixed $order
     */
    private function format(WC_Email $email, string $content, $order = null): string
    {
        if (method_exists($email, 'format_string')) {
            $content = (string) $email->format_string($content);
        }

        if (!$order instanceof WC_Order && isset($email->object) && $email->object instanceof WC_Order) {
            $order = $email->object;
        }

        $map = [
            '{site_title}'   => $this->site_title(),
            '{site_address}' => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            '{site_url}'     => home_url(),
            '{store_email}'  => $this->store_email(),
        ];

        if ($order instanceof WC_Order) {
            $full_name = trim($order->get_formatted_billing_full_name());
            if ($full_name === '') {
                $full_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            }
            $created = $order->get_date_created();

            $map['{order_number}']        = $order->get_order_number();
            $map['{order_date}']          = $created ? wc_format_datetime($created) : '';
            $map['{customer_first_name}'] = $order->get_billing_first_name();
            $map['{customer_full_name}']  = $full_name;
        }

        return strtr($content, $map);
    }

    private function site_title(): string
    {
        return wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
    }

    private function store_email(): string
    {
        $email = get_option('woocommerce_email_from_address');
        if (!is_string($email) || $email === '') {
            $email = (string) get_option('admin_email');
        }

        return $email;
    }

    /**
     * Per-email content translation via gettext. The text domain
     * "lotzapp-email-<id>" is loaded in the main plugin file (init +
     * change_locale) from wp-content/languages/plugins/lotzapp-email-<id>-<locale>.mo
     * if such a file exists. When no .mo is loaded for the current locale, __()
     * returns the source string unchanged — i.e. behaviour is identical to
     * before for emails without a translation file.
     */
    private function translate_email_content(WC_Email $email, string $text): string
    {
        if ($text === '' || $email->id === '') {
            return $text;
        }
        return (string) __($text, 'lotzapp-email-' . $email->id);
    }

    private function email_active(WC_Email $email): bool
    {
        return $this->feature_enabled()
            && $email->get_option(self::FIELD_PREFIX . 'enabled') === 'yes';
    }

    private function feature_enabled(): bool
    {
        return (bool) Plugin::opt('emails_advanced_editing_enabled', 0);
    }

    // ---- Strategy B: greeting/intro replacement ----

    /**
     * @param mixed $email_heading
     * @param mixed $email
     */
    public function capture_intro_start($email_heading = '', $email = null): void
    {
        if (!$email instanceof WC_Email || !$this->feature_enabled() || $this->intro_buffering) {
            return;
        }

        // Intro replace only applies to order emails (they have the
        // header -> order-details span). Non-order emails (password reset,
        // new account) have no hook boundary isolating the greeting/intro.
        if (!$this->is_order_email($email)) {
            return;
        }

        // Buffer the greeting/intro span whenever the feature is on: needed to
        // (a) snapshot the real default once for the field placeholder and
        // (b) replace it when a per-email override is set.
        $this->intro_buffering    = true;
        $this->intro_buffer_email = $email;
        ob_start();
    }

    /**
     * @param mixed $order
     * @param mixed $email
     */
    public function capture_intro_end($order = null, $sent_to_admin = false, $plain_text = false, $email = null): void
    {
        if (!$this->intro_buffering) {
            return;
        }

        $original              = (string) ob_get_clean();
        $this->intro_buffering = false;
        $captured              = $this->intro_buffer_email;
        $this->intro_buffer_email = null;

        if (!$captured instanceof WC_Email) {
            echo $original; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        $this->store_default_snapshot($captured, $original, 'intro');

        if ($this->email_active($captured)) {
            $custom = $this->zone_content($captured, 'intro');
            if ($custom !== '') {
                $custom = $this->translate_email_content($captured, $custom);
                echo wp_kses_post(wpautop(wptexturize($this->format($captured, $custom, $order)))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return;
            }
        }

        echo $original; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param mixed $email
     */
    public function intro_buffer_safety($email = null): void
    {
        if (!$this->intro_buffering) {
            return;
        }

        $this->intro_buffering    = false;
        $this->intro_buffer_email = null;
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    // ---- Generic: full-body replacement (hookless emails) ----

    /**
     * @param mixed $email_heading
     * @param mixed $email
     */
    public function capture_body_start($email_heading = '', $email = null): void
    {
        if (!$email instanceof WC_Email || !$this->feature_enabled() || $this->body_buffering) {
            return;
        }

        if (!$this->is_body_replace_email($email)) {
            return;
        }

        $this->body_buffering    = true;
        $this->body_buffer_email = $email;
        ob_start();
    }

    /**
     * @param mixed $email
     */
    public function capture_body_end($email = null): void
    {
        if (!$this->body_buffering) {
            return;
        }

        $original             = (string) ob_get_clean();
        $this->body_buffering = false;
        $captured             = $this->body_buffer_email;
        $this->body_buffer_email = null;

        if (!$captured instanceof WC_Email) {
            echo $original; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        $this->store_default_snapshot($captured, $original, 'body');

        if ($this->email_active($captured)) {
            $custom = $this->zone_content($captured, 'body');
            if ($custom !== '') {
                $custom = $this->translate_email_content($captured, $custom);
                echo wp_kses_post(wpautop(wptexturize($this->format($captured, $custom)))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return;
            }
        }

        echo $original; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function store_default_snapshot(WC_Email $email, string $original, string $type = 'intro'): void
    {
        $value = trim($original);
        if ($value === '') {
            return;
        }

        // First capture wins: store once per email so live sends don't churn
        // the option with per-customer data (the rendered name) on every send.
        $key      = 'lotzwoo_adv_' . $type . '_default_' . $email->id;
        $existing = get_option($key, '');
        if (is_string($existing) && $existing !== '') {
            return;
        }

        update_option($key, $value, false);
    }

    private function default_snapshot(string $email_id, string $type = 'intro'): string
    {
        if ($email_id === '') {
            return '';
        }

        $value = get_option('lotzwoo_adv_' . $type . '_default_' . $email_id, '');
        return is_string($value) ? trim($value) : '';
    }
}

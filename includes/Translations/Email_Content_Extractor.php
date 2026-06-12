<?php

namespace Lotzwoo\Translations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads the user-entered replace-field values out of a specific WooCommerce
 * email's settings and shapes them into the same entry structure that
 * String_Scanner returns — so PO_File / MO_Writer can consume both without
 * caring whether the source is plugin gettext strings or per-email content.
 *
 * Each email's content lives under its own text domain ("lotzapp-email-{id}")
 * so per-email .po files don't collide and Loco can list them separately.
 */
class Email_Content_Extractor
{
    /** field key (matches lotzwoo_adv_<field>) => human label */
    public const FIELDS = [
        'intro'            => 'Begrüßung/Einleitung ersetzen',
        'body'             => 'Ganze E-Mail ersetzen',
        'reset_greeting'   => 'Begrüßung (Passwort-zurücksetzen) ersetzen',
        'reset_intro'      => 'Einleitung (Passwort-zurücksetzen) ersetzen',
        'reset_after'      => 'Text nach Benutzername (Passwort-zurücksetzen) ersetzen',
        'account_greeting' => 'Begrüßung + Einleitung (Neuer Account) ersetzen',
        'account_after'    => 'Text nach Benutzername (Neuer Account) ersetzen',
    ];

    /**
     * @return array<string, array{msgid:string, msgid_plural:?string, msgctxt:?string, refs:string[]}>
     *         keyed by field name (intro / body / reset_*); empty when the email has no LotzApp content.
     */
    public function extract(string $email_id): array
    {
        $settings = (array) get_option('woocommerce_' . $email_id . '_settings', []);
        $entries  = [];
        foreach (self::FIELDS as $field => $label) {
            $value = $settings['lotzwoo_adv_' . $field] ?? '';
            if (!is_string($value)) continue;
            $value = trim($value);
            if ($value === '') continue;
            $entries[$field] = [
                'msgid'        => $value,
                'msgid_plural' => null,
                'msgctxt'      => null,
                'refs'         => ['WooCommerce email ' . $email_id . ': lotzwoo_adv_' . $field . ' (' . $label . ')'],
            ];
        }
        return $entries;
    }

    /**
     * List all WooCommerce emails that currently have at least one non-empty
     * LotzApp custom field, for populating the Übersetzungen-page selector.
     *
     * @return array<string, string>  email_id => human title
     */
    public function emails_with_content(): array
    {
        if (!function_exists('WC')) return [];
        $mailer = WC()->mailer();
        if (!$mailer) return [];

        $out = [];
        foreach ($mailer->get_emails() as $email) {
            if (!($email instanceof \WC_Email) || $email->id === '') continue;
            if (!empty($this->extract($email->id))) {
                $title = property_exists($email, 'title') && is_string($email->title) && $email->title !== ''
                    ? $email->title
                    : $email->id;
                $out[$email->id] = $title;
            }
        }
        asort($out, SORT_STRING | SORT_FLAG_CASE);
        return $out;
    }

    /** Stable text-domain string for a given email id. */
    public static function domain_for(string $email_id): string
    {
        return 'lotzapp-email-' . $email_id;
    }
}

<?php

namespace Lotzwoo\Translations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Engine-agnostic wrapper around TranslatePress's machine-translator component.
 *
 * TP stores the user's API key + chosen engine (Google / DeepL) in its own
 * settings. We never see or own that key — we just locate TP's MT component
 * and delegate. Every call is wrapped in class/method-exists checks and
 * try/catch so TP refactors degrade us to "no translation" rather than fatal.
 *
 * When MT isn't available (TP missing, no engine configured, or any internal
 * error), translate() returns an array of `null`s and the caller writes empty
 * msgstrs — Loco can then fill them manually.
 */
class TP_Bridge
{
    public function is_available(): bool
    {
        return $this->get_component() !== null;
    }

    /**
     * Translate strings via TP's MT engine.
     *
     * @param array<string,string> $strings  key => source string (preserves keys)
     * @param string $source_locale          e.g. de_DE
     * @param string $target_locale          e.g. en_GB
     * @return array<string, ?string>        same keys; null = not translated
     */
    public function translate(array $strings, string $source_locale, string $target_locale): array
    {
        $result = [];
        foreach ($strings as $k => $_) {
            $result[$k] = null;
        }
        if (empty($strings)) return $result;

        $mt = $this->get_component();
        if ($mt === null) return $result;

        $source_lang = $this->locale_to_lang($source_locale);
        $target_lang = $this->locale_to_lang($target_locale);
        $values      = array_values($strings);
        $keys        = array_keys($strings);

        try {
            $translated = $this->call_translator($mt, $values, $source_lang, $target_lang);
        } catch (\Throwable $e) {
            return $result; // degrade silently to "untranslated"
        }
        if (!is_array($translated)) return $result;

        // Map back to keys preserving order.
        $tvals = array_values($translated);
        foreach ($keys as $i => $k) {
            $t = $tvals[$i] ?? null;
            if (is_string($t) && $t !== '' && $t !== $values[$i]) {
                $result[$k] = $t;
            }
        }
        return $result;
    }

    /**
     * Locate TP's machine_translator component via its public singleton.
     */
    private function get_component(): ?object
    {
        try {
            if (!class_exists('TRP_Translate_Press')) return null;
            if (!method_exists('TRP_Translate_Press', 'get_trp_instance')) return null;
            $trp = \TRP_Translate_Press::get_trp_instance();
            if (!is_object($trp) || !method_exists($trp, 'get_component')) return null;
            $mt = $trp->get_component('machine_translator');
            return is_object($mt) ? $mt : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Call whichever translate method this version of TP exposes.
     *
     * @param string[] $values
     * @return array|null
     */
    private function call_translator(object $mt, array $values, string $source_lang, string $target_lang): ?array
    {
        // Newer TP: translate_array($strings, $target, $source)
        if (method_exists($mt, 'translate_array')) {
            $out = $mt->translate_array($values, $target_lang, $source_lang);
            if (is_array($out)) return $out;
        }
        // Older / alt: translate_strings($strings, $target_lang)
        if (method_exists($mt, 'translate_strings')) {
            $out = $mt->translate_strings($values, $target_lang);
            if (is_array($out)) return $out;
        }
        // Last-resort: a single-string translate() if exposed.
        if (method_exists($mt, 'translate')) {
            $out = [];
            foreach ($values as $v) {
                $res = $mt->translate($v, $source_lang, $target_lang);
                $out[] = is_string($res) ? $res : '';
            }
            return $out;
        }
        return null;
    }

    /**
     * TranslatePress typically uses WP locale codes directly (en_US, fr_FR).
     * Keep identity for now; override here if a future TP version diverges.
     */
    private function locale_to_lang(string $locale): string
    {
        return $locale;
    }
}

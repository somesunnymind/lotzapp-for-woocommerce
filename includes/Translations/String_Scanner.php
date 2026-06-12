<?php

namespace Lotzwoo\Translations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Token-based extractor for gettext calls in the plugin's PHP source.
 *
 * Walks `token_get_all` output, finds the supported translation-function
 * names, and captures their string-literal arguments when the LAST positional
 * arg is the literal text domain. Non-literal arguments (variables,
 * concatenations, function calls) are skipped — same behaviour as WP-CLI's
 * make-pot, just narrower in scope.
 */
class String_Scanner
{
    public const TEXT_DOMAIN = 'lotzapp-for-woocommerce';

    /** name => true: single-msgid functions */
    private const SINGLE_FUNCS = [
        '__'          => true,
        '_e'          => true,
        'esc_html__'  => true,
        'esc_attr__'  => true,
        'esc_html_e'  => true,
        'esc_attr_e'  => true,
    ];

    /** name => true: msgid + context */
    private const CONTEXT_FUNCS = [
        '_x'          => true,
        '_ex'         => true,
        'esc_html_x'  => true,
        'esc_attr_x'  => true,
    ];

    /** name => true: msgid + msgid_plural (number arg ignored) */
    private const PLURAL_FUNCS = [
        '_n'        => true,
        '_n_noop'   => true,
        '_nx'       => true, // context lives in args[3]; ignored for MVP
        '_nx_noop'  => true,
    ];

    /**
     * @return array<string, array{msgid:string, msgid_plural:?string, msgctxt:?string, refs:string[]}>
     *         Keyed by msgctxt\x04msgid (or msgid alone).
     */
    public function scan(string $plugin_dir): array
    {
        $results = [];
        foreach ($this->find_php_files($plugin_dir) as $file) {
            $this->scan_file($file, $plugin_dir, $results);
        }
        ksort($results);
        return $results;
    }

    /** @return string[] */
    private function find_php_files(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $info) {
            if (!$info->isFile()) continue;
            $path = $info->getPathname();
            if (substr($path, -4) !== '.php') continue;
            // Skip vendor / tests / node_modules.
            if (preg_match('#[\\\\/](vendor|node_modules|tests?)[\\\\/]#i', $path)) continue;
            $out[] = $path;
        }
        sort($out);
        return $out;
    }

    /** @param array<string,mixed> $results */
    private function scan_file(string $file, string $plugin_dir, array &$results): void
    {
        $src = @file_get_contents($file);
        if ($src === false) return;
        try {
            $tokens = token_get_all($src);
        } catch (\Throwable $e) {
            return;
        }
        $relative = ltrim(str_replace(['\\', $plugin_dir], ['/', ''], $file), '/');
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_STRING) continue;

            $name = $tok[1];
            $line = $tok[2];
            $kind = $this->classify($name);
            if ($kind === null) continue;

            // Ensure it's a real call: optional whitespace then '('.
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;
            }
            if ($j >= $count || $tokens[$j] !== '(') continue;

            $args = $this->collect_top_level_args($tokens, $j, $count);
            if ($args === null || count($args) < 2) continue;

            $last = $args[count($args) - 1];
            if ($last !== self::TEXT_DOMAIN) continue;

            $msgid       = $args[0] ?? null;
            $msgid_plural = null;
            $msgctxt     = null;

            if ($kind === 'context') {
                // _x($msgid, $context, $domain) => args = [msgid, ctxt, domain]
                $msgctxt = $args[1] ?? null;
            } elseif ($kind === 'plural') {
                // _n($single, $plural, $count, $domain) => args = [s, p, ?, domain]
                $msgid_plural = $args[1] ?? null;
            }

            if (!is_string($msgid) || $msgid === '') continue;

            $this->record($results, $msgid, $msgid_plural, $msgctxt, $relative, $line);
        }
    }

    private function classify(string $name): ?string
    {
        if (isset(self::SINGLE_FUNCS[$name]))  return 'single';
        if (isset(self::CONTEXT_FUNCS[$name])) return 'context';
        if (isset(self::PLURAL_FUNCS[$name]))  return 'plural';
        return null;
    }

    /**
     * Walk forward from the opening '(' and return positional args as a flat
     * array of values: literal string args become strings; non-literal slots
     * become null. Returns null if the call is malformed.
     *
     * @return array<int, string|null>|null
     */
    private function collect_top_level_args(array $tokens, int $open_idx, int $count): ?array
    {
        if ($tokens[$open_idx] !== '(') return null;
        $depth = 1;
        $i = $open_idx + 1;

        $args = [];
        $current = null;        // captured literal string for this slot
        $seen_anything = false; // anything (incl. ws-non) appeared in this slot

        while ($i < $count && $depth > 0) {
            $t = $tokens[$i];
            if (is_array($t)) {
                $type = $t[0];
                if (in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $i++;
                    continue;
                }
                if ($depth === 1) {
                    if (!$seen_anything && $type === T_CONSTANT_ENCAPSED_STRING) {
                        $current = $this->unquote_php_string($t[1]);
                    }
                    $seen_anything = true;
                }
            } else {
                if ($t === '(') {
                    $depth++;
                    $seen_anything = true;
                } elseif ($t === ')') {
                    $depth--;
                    if ($depth === 0) {
                        if ($seen_anything) $args[] = $current;
                        return $args;
                    }
                } elseif ($t === ',' && $depth === 1) {
                    $args[] = $current;
                    $current = null;
                    $seen_anything = false;
                } else {
                    $seen_anything = true;
                }
            }
            $i++;
        }
        return null;
    }

    private function unquote_php_string(string $s): string
    {
        if (strlen($s) < 2) return '';
        $q = $s[0];
        $body = substr($s, 1, -1);
        if ($q === "'") {
            return strtr($body, ["\\'" => "'", '\\\\' => '\\']);
        }
        return stripcslashes($body);
    }

    /** @param array<string,mixed> $results */
    private function record(array &$results, string $msgid, ?string $msgid_plural, ?string $msgctxt, string $file, int $line): void
    {
        $key = ($msgctxt !== null ? $msgctxt . "\x04" : '') . $msgid;
        if (!isset($results[$key])) {
            $results[$key] = [
                'msgid'        => $msgid,
                'msgid_plural' => $msgid_plural,
                'msgctxt'      => $msgctxt,
                'refs'         => [],
            ];
        }
        if ($msgid_plural !== null && $results[$key]['msgid_plural'] === null) {
            $results[$key]['msgid_plural'] = $msgid_plural;
        }
        $ref = $file . ':' . $line;
        if (!in_array($ref, $results[$key]['refs'], true)) {
            $results[$key]['refs'][] = $ref;
        }
    }
}

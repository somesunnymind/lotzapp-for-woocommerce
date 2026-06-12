<?php

namespace Lotzwoo\Translations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure-PHP writer for binary .mo files (GNU gettext format).
 *
 * Reference: https://www.gnu.org/software/gettext/manual/html_node/MO-Files.html
 *  - 32-bit little-endian magic 0x950412de
 *  - Revision 0
 *  - Two parallel arrays of (length, offset) for originals and translations
 *  - Strings are null-terminated; entries must be sorted by msgid
 *  - First entry has empty msgid; its msgstr holds the header metadata
 *  - Context entries:  msgctxt + "\x04" + msgid  (originals side)
 *  - Plural entries:   msgid + "\x00" + msgid_plural   (originals)
 *                      msgstr[0] + "\x00" + msgstr[1]  (translations)
 */
class MO_Writer
{
    /**
     * Build a .mo from scan entries + translations.
     *
     * @param array<string, array{msgid:string, msgid_plural:?string, msgctxt:?string, refs:string[]}> $entries
     * @param array<string, string> $translations  key (matches entry key) => msgstr; plural form at "{key}__plural"
     */
    public function build_from_entries(array $entries, array $translations, string $locale): string
    {
        $messages = [];
        foreach ($entries as $key => $entry) {
            $orig = $entry['msgctxt'] !== null
                ? $entry['msgctxt'] . "\x04" . $entry['msgid']
                : $entry['msgid'];

            if ($entry['msgid_plural'] !== null) {
                $orig .= "\x00" . $entry['msgid_plural'];
                $tr0 = (string) ($translations[$key] ?? '');
                $tr1 = (string) ($translations[$key . '__plural'] ?? '');
                if ($tr0 === '' && $tr1 === '') continue; // untranslated -> omit from .mo
                $msgstr = $tr0 . "\x00" . $tr1;
            } else {
                $msgstr = (string) ($translations[$key] ?? '');
                if ($msgstr === '') continue; // untranslated -> omit
            }
            $messages[$orig] = $msgstr;
        }

        $headers = "Project-Id-Version: LotzApp for WooCommerce\n"
            . "Language: {$locale}\n"
            . "MIME-Version: 1.0\n"
            . "Content-Type: text/plain; charset=UTF-8\n"
            . "Content-Transfer-Encoding: 8bit\n"
            . "Plural-Forms: nplurals=2; plural=n != 1;\n";

        return $this->build($messages, $headers);
    }

    /**
     * Parse an existing .mo and return its translations indexed by the encoded
     * original string (msgctxt\x04msgid plus optional \x00msgid_plural). Used
     * by the Generate flow to preserve manual translations across re-generates.
     *
     * @return array<string, string>  empty if the file is missing/invalid
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false || strlen($bytes) < 28) {
            return [];
        }
        $hdr = @unpack('Vmagic/Vrev/Vcount/Voff_orig/Voff_trans/Vhash_sz/Vhash_off', substr($bytes, 0, 28));
        if (!is_array($hdr) || (int) $hdr['magic'] !== 0x950412de) {
            return [];
        }
        $count = (int) $hdr['count'];
        $out   = [];
        for ($i = 0; $i < $count; $i++) {
            $om = @unpack('Vlen/Voff', substr($bytes, (int) $hdr['off_orig']  + $i * 8, 8));
            $tm = @unpack('Vlen/Voff', substr($bytes, (int) $hdr['off_trans'] + $i * 8, 8));
            if (!is_array($om) || !is_array($tm)) continue;
            $orig  = substr($bytes, (int) $om['off'], (int) $om['len']);
            $trans = substr($bytes, (int) $tm['off'], (int) $tm['len']);
            if ($orig === '') continue; // skip header entry
            $out[$orig] = $trans;
        }
        return $out;
    }

    /**
     * @param array<string, string> $messages  msgid => msgstr (already context/plural-encoded)
     * @param string $headers  metadata block stored as the empty-msgid translation
     */
    private function build(array $messages, string $headers): string
    {
        // Prepend the empty-msgid header entry and sort.
        $entries = ['' => $headers] + $messages;
        ksort($entries);

        $count        = count($entries);
        $header_size  = 28;
        $table_size   = 8 * $count;
        $strings_off  = $header_size + 2 * $table_size;

        $orig_blob  = '';
        $trans_blob = '';
        $orig_meta  = [];
        $trans_meta = [];

        foreach (array_keys($entries) as $msgid) {
            $orig_meta[] = ['len' => strlen($msgid), 'off' => $strings_off + strlen($orig_blob)];
            $orig_blob .= $msgid . "\0";
        }
        $trans_off_base = $strings_off + strlen($orig_blob);
        foreach (array_values($entries) as $msgstr) {
            $trans_meta[] = ['len' => strlen($msgstr), 'off' => $trans_off_base + strlen($trans_blob)];
            $trans_blob .= $msgstr . "\0";
        }

        $orig_table  = '';
        $trans_table = '';
        foreach ($orig_meta as $m)  { $orig_table  .= pack('VV', $m['len'], $m['off']); }
        foreach ($trans_meta as $m) { $trans_table .= pack('VV', $m['len'], $m['off']); }

        $header = pack(
            'VVVVVVV',
            0x950412de,                       // magic
            0,                                 // revision
            $count,                            // number of entries
            $header_size,                      // offset: originals table
            $header_size + $table_size,        // offset: translations table
            0,                                 // hash size
            0                                  // hash offset
        );

        return $header . $orig_table . $trans_table . $orig_blob . $trans_blob;
    }
}

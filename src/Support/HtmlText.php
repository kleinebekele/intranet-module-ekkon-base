<?php

namespace Intranet\Modules\Ekkon\Support;

/**
 * HTML in etwas verwandeln, das ein anderer Kanal versteht:
 *  - zuText():     reiner Text für die Textfassung einer Mail,
 *  - zuMarkdown(): das kleine Markdown, das Teams-Adaptive-Cards rendern
 *                  (fett, kursiv, Listen, Links – KEIN HTML, keine Tabellen).
 *
 * Bewusst ohne DOM-Parser: Es geht um Zusammenfassungen (Überschriften,
 * Absätze, Listen), nicht um beliebige Webseiten.
 */
final class HtmlText
{
    public static function zuText(string $html): string
    {
        $s = self::vorbereiten($html);

        // Überschrift als eigene Zeile in Großbuchstaben, Link mit URL dahinter.
        $s = preg_replace_callback('~<h[1-6][^>]*>(.*?)</h[1-6]>~is', fn ($m) => "\n\n".mb_strtoupper(trim(strip_tags($m[1])))."\n\n", $s);
        $s = preg_replace_callback('~<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is', function ($m) {
            $text = trim(strip_tags($m[2]));

            return $text === '' || $text === $m[1] ? $m[1] : $text.' ('.$m[1].')';
        }, $s);

        return self::abschluss(self::bloecke($s));
    }

    public static function zuMarkdown(string $html): string
    {
        $s = self::vorbereiten($html);

        $s = preg_replace_callback('~<h[1-6][^>]*>(.*?)</h[1-6]>~is', fn ($m) => "\n\n**".trim(strip_tags($m[1]))."**\n\n", $s);
        $s = preg_replace('~<(strong|b)\b[^>]*>(.*?)</\1>~is', '**$2**', $s);
        $s = preg_replace('~<(em|i)\b[^>]*>(.*?)</\1>~is', '_$2_', $s);
        $s = preg_replace_callback('~<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is', function ($m) {
            $text = trim(strip_tags($m[2]));

            return '['.($text === '' ? $m[1] : $text).']('.$m[1].')';
        }, $s);

        return self::abschluss(self::bloecke($s));
    }

    /** Skripte/Styles raus, Whitespace des Quelltexts glätten. */
    private static function vorbereiten(string $html): string
    {
        $s = preg_replace('~<(script|style)\b.*?</\1>~is', '', $html);

        return preg_replace('~\s+~', ' ', $s);
    }

    /** Blockelemente in Zeilenumbrüche übersetzen, Listenpunkte mit Strich. */
    private static function bloecke(string $s): string
    {
        $s = preg_replace('~<br\s*/?>~i', "\n", $s);
        $s = preg_replace('~<li[^>]*>~i', "\n- ", $s);
        $s = preg_replace('~</li>~i', '', $s);
        $s = preg_replace('~</(p|div|tr|blockquote)>~i', "\n\n", $s);
        $s = preg_replace('~<(p|div|blockquote)\b[^>]*>~i', "\n", $s);
        $s = preg_replace('~<(ul|ol)\b[^>]*>~i', "\n", $s);
        $s = preg_replace('~</(ul|ol)>~i', "\n\n", $s);

        return $s;
    }

    private static function abschluss(string $s): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('~[ \t]+\n~', "\n", $s);
        $s = preg_replace('~\n[ \t]+~', "\n", $s);
        $s = preg_replace('~\n{3,}~', "\n\n", $s);

        return trim($s);
    }
}

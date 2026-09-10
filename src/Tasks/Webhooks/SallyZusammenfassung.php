<?php

namespace Intranet\Modules\Ekkon\Tasks\Webhooks;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Intranet\Modules\Ekkon\Models\WebhookEingang;
use Intranet\Modules\Ekkon\Support\HtmlText;
use Intranet\Modules\Ekkon\Tasks\EkkonTask;
use Throwable;

/**
 * Sally.io schickt nach jedem Meeting eine Zusammenfassung an den
 * Webhook-Eingang. Dieser Task macht daraus Benachrichtigungen – und zwar
 * **je Termin-Titel eine eigene Meldungsart** (`sally-<titel>`), damit man
 * in der Routing-Maske je Termin entscheidet, wohin die Zusammenfassung geht
 * (Teams-Kanal des Teams, Mail an die Runde, beides).
 *
 * Mail bekommt HTML + Klartext, Teams die Markdown-Fassung (SendNotifications).
 *
 * Erkannt wird ein Sally-Eingang am Inhalt (recordingSummaryId +
 * appointmentSubject), nicht an der Quelle – so ist es egal, wie die Quelle
 * heißt. Andere Eingänge lässt der Task in Ruhe.
 *
 * Die Meldungsarten entstehen aus den bisher gesehenen Titeln (Datenbank),
 * denn im Voraus kennt niemand alle Termine. Ein neuer Titel landet also
 * beim ersten Mal auf `ohne_ziel` (Glocke!), danach lässt sich die Route anlegen.
 */
class SallyZusammenfassung extends EkkonTask
{
    public string $category = 'Webhooks';

    public string $description = 'Sally.io-Meeting-Zusammenfassungen je Termin-Titel als Benachrichtigung routen (Teams/Mail).';

    public const PRAEFIX = 'sally-';

    public function __construct()
    {
        // Meldungsarten aus den bekannten Titeln – vor der Migration gibt es die
        // Tabelle nicht, dann eben noch keine.
        try {
            $this->meldungsarten = $this->meldungsartenAusEingaengen();
        } catch (Throwable) {
            $this->meldungsarten = [];
        }
    }

    public function schedule(): string
    {
        return '*/5 * * * *';
    }

    public function run(): array
    {
        $offen = WebhookEingang::query()
            ->whereNull('verarbeitet_am')
            ->orderBy('id')
            ->limit(50)
            ->get();

        $ergebnis = ['geprueft' => $offen->count(), 'zusammenfassungen' => 0, 'fremd' => 0, 'ohne_ziel' => 0, 'angelegt' => 0];

        foreach ($offen as $eingang) {
            $sally = $this->sallyDaten($eingang);
            if ($sally === null) {
                // Kein Sally-Format: nicht unser Eingang, aber als gesehen markieren,
                // damit er nicht in jedem Lauf wieder durchläuft.
                $eingang->update(['verarbeitet_am' => now(), 'verarbeitung' => 'kein Sally-Format – übersprungen']);
                $ergebnis['fremd']++;

                continue;
            }

            $art = self::meldungsart($sally['titel']);
            $this->meldungsarten[$art] = 'Sally: '.$sally['titel'];

            $termin = $sally['datum']?->format('d.m.Y H:i');
            $html = $sally['html'];
            $text = $html !== '' ? HtmlText::zuText($html) : $sally['text'];

            $res = $this->benachrichtige(
                $art,
                'Zusammenfassung: '.$sally['titel'].($termin ? ' ('.$termin.')' : ''),
                $text,
                array_filter(['Termin' => $termin, 'Sally' => $sally['url']]),
                'sally:'.$sally['id'],
                $html !== '' ? $html : null,
            );

            $eingang->update([
                'verarbeitet_am' => now(),
                'verarbeitung' => ($res['ohne_ziel'] ? 'ohne Route: ' : 'weitergeleitet: ').$art,
            ]);

            $ergebnis['zusammenfassungen']++;
            $ergebnis['angelegt'] += $res['angelegt'];
            if ($res['ohne_ziel']) {
                $ergebnis['ohne_ziel']++;
                $this->msg('„'.$sally['titel'].'" hat noch keine Route (Meldungsart '.$art.') – unter Benachrichtigungen anlegen.');
            }
        }

        return $ergebnis;
    }

    /**
     * Payload lesen; null, wenn es kein Sally-Eingang ist.
     *
     * @return array{id: string, titel: string, datum: ?Carbon, url: string, text: string, html: string}|null
     */
    private function sallyDaten(WebhookEingang $eingang): ?array
    {
        $json = json_decode((string) $eingang->body, true);
        if (! is_array($json) || ! isset($json['recordingSummaryId'], $json['appointmentSubject'])) {
            return null;
        }

        $datum = null;
        try {
            $datum = filled($json['appointmentDate'] ?? null) ? Carbon::parse((string) $json['appointmentDate'])->setTimezone(config('app.timezone')) : null;
        } catch (Throwable) {
        }

        return [
            'id' => (string) $json['recordingSummaryId'],
            'titel' => self::titelBereinigen((string) $json['appointmentSubject']),
            'datum' => $datum,
            'url' => (string) ($json['appointmentUrl'] ?? ''),
            'text' => trim((string) ($json['summary'] ?? '')),
            'html' => trim((string) ($json['combinedFullSummaryHTML'] ?? '')),
        ];
    }

    /** „WG: AW: Daily: …" → „Daily: …" – Weiterleitungs-/Antwort-Präfixe sind kein Titel. */
    public static function titelBereinigen(string $titel): string
    {
        $t = trim($titel);
        while (preg_match('/^(wg|aw|re|fw|fwd)\s*:\s*/i', $t)) {
            $t = (string) preg_replace('/^(wg|aw|re|fw|fwd)\s*:\s*/i', '', $t);
        }

        return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    }

    /** Meldungsart aus dem Titel: sally-daily-emanuel-x-mena … (stabil, dateiname-tauglich). */
    public static function meldungsart(string $titel): string
    {
        return self::PRAEFIX.mb_substr(Str::slug(self::titelBereinigen($titel)), 0, 80);
    }

    /** @return array<string,string> */
    private function meldungsartenAusEingaengen(): array
    {
        $arten = [];

        // Nur Eingänge, die dieser Task schon verarbeitet hat, tragen die Art im
        // Feld `verarbeitung` ("weitergeleitet: sally-…" / "ohne Route: sally-…").
        $zeilen = WebhookEingang::query()
            ->where('verarbeitung', 'like', '%'.self::PRAEFIX.'%')
            ->latest('id')
            ->limit(500)
            ->get(['body', 'verarbeitung']);

        foreach ($zeilen as $z) {
            $json = json_decode((string) $z->body, true);
            $titel = self::titelBereinigen((string) ($json['appointmentSubject'] ?? ''));
            if ($titel === '') {
                continue;
            }
            $arten[self::meldungsart($titel)] = 'Sally: '.$titel;
        }

        ksort($arten);

        return $arten;
    }
}

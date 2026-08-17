<?php

namespace Intranet\Modules\Ekkon\Tasks\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Intranet\Modules\Ekkon\Models\Notification;
use Intranet\Modules\Ekkon\Models\TeamsChannel;
use Intranet\Modules\Ekkon\Services\TeamsWebhookClient;
use Intranet\Modules\Ekkon\Tasks\EkkonTask;
use Throwable;

/**
 * Arbeitet die Benachrichtigungs-Warteschlange ab.
 *
 * ⚠️ DIESER TASK IST ABSICHTLICH DUMM. Er liest 'pending', schaut auf `typ`,
 * ruft Mail- oder Teams-Versand und schreibt 'sent'/'failed'. Er kennt WEDER
 * Meldungsarten NOCH Empfänger – die Ziele hat der Benachrichtiger beim Anlegen
 * aufgelöst.
 *
 * Das ist der ganze Punkt: Solche Versand-Tasks wuchern, wenn Routing,
 * Formatierung und Versand in einer Datei liegen und jede neue Meldungsart ein
 * weiteres `if` bekommt. Solange dieser Task nichts über Meldungsarten weiß,
 * kann er nicht wuchern.
 *
 * Wer hier ein `if ($meldungsart === …)` einbauen möchte: Das gehört in die
 * Routing-Tabelle, nicht hierher.
 */
class SendNotifications extends EkkonTask
{
    public string $category = 'Notifications';

    public string $description = 'Versendet offene Benachrichtigungen (Teams/Mail) und räumt Zugestelltes nach 14 Tagen weg.';

    /**
     * ⚠️ DIESER TASK PAUSIERT SICH NIE SELBST.
     *
     * Er ist der Weg, auf dem Warnungen überhaupt herauskommen - auch die
     * Warnung "ein Task lief zu lange". Würde er sich nach einem langen Lauf
     * stilllegen, bliebe genau die Meldung liegen, die darüber informiert:
     * Das System wäre still und würde sein Stillsein nicht melden können.
     *
     * Ist er selbst zu langsam, muss das über die Laufzeit-Warnung anderer
     * Tasks oder das Dashboard auffallen.
     */
    public bool $automatischPausieren = false;

    /** Pro Lauf, damit ein Rückstau die Minute nicht sprengt. */
    private const PRO_LAUF = 25;

    private const PRUNE_TAGE = 14;

    /** Merker, damit die „ohne Route"-Warnung nicht jede Minute im Protokoll steht. */
    private const WARN_MERKER = 'ekkon-benachrichtigungen-ohne-route';

    public function schedule(): string
    {
        return '* * * * *';
    }

    public function run(): array
    {
        $offen = Notification::query()
            ->where('status', 'pending')
            ->where('versuche', '<', Notification::MAX_VERSUCHE)
            ->orderBy('id')
            ->limit(self::PRO_LAUF)
            ->get();

        $gesendet = 0;
        $fehlgeschlagen = 0;

        foreach ($offen as $n) {
            $fehler = $this->zustellen($n);

            $n->versuche++;

            if ($fehler === null) {
                $n->status = 'sent';
                $n->gesendet_am = now();
                $n->letzter_fehler = null;
                $gesendet++;
            } else {
                $n->letzter_fehler = $fehler;

                // Nach 3 Versuchen liegen lassen statt ewig weiterprobieren –
                // sonst hämmert ein kaputter Channel jede Minute gegen die Wand.
                if ($n->versuche >= Notification::MAX_VERSUCHE) {
                    $n->status = 'failed';
                    $fehlgeschlagen++;
                    $this->msg('Benachrichtigung #'.$n->id.' endgültig fehlgeschlagen ('.$n->typ.'): '.$fehler);
                }
            }

            $n->save();
        }

        $ergebnis = [
            'verarbeitet' => $offen->count(),
            'gesendet' => $gesendet,
            'fehlgeschlagen' => $fehlgeschlagen,
        ];

        // Was tatsächlich rausgegangen ist, gehört ins Protokoll – das ist die
        // Nachricht. Läufe ohne Arbeit bleiben stumm; die Historie zeigt dann
        // nur noch die Minuten, in denen wirklich etwas passiert ist.
        if ($gesendet > 0) {
            $this->msg($gesendet.' Benachrichtigung(en) ausgeliefert.');
        }

        // Aufräumen VOR der Zählung: sonst warnt der Lauf noch über Zeilen,
        // die er im selben Atemzug selbst wegräumt.
        $ergebnis += $this->pruneAlte();

        if (($ergebnis['gepruned_altbestand'] ?? 0) > 0) {
            $this->msg($ergebnis['gepruned_altbestand'].' Meldung(en) ohne Meldungsart entfernt (Altbestand von vor dem 20.07.2026 – bei ihnen war nicht erkennbar, welche Route fehlte).');
        }

        // Meldungen ohne Route sind ein Konfigurations-Loch: Irgendein Task
        // meldet etwas, das niemanden erreicht. Sichtbar machen, nicht zählen
        // und vergessen.
        $ohneZiel = Notification::query()
            ->where('status', 'ohne_ziel')
            ->selectRaw('meldungsart, count(*) as anzahl')
            ->groupBy('meldungsart')
            ->pluck('anzahl', 'meldungsart')
            ->map(fn ($n): int => (int) $n)
            ->all();

        ksort($ohneZiel);
        $gesamt = array_sum($ohneZiel);

        if ($gesamt > 0) {
            $ergebnis['ohne_ziel'] = $gesamt;

            if ($this->warnungFaellig($ohneZiel)) {
                $this->msg($gesamt.' Meldung(en) ohne passende Route – niemand wird informiert ('
                    .$this->artenKlartext($ohneZiel).'). Route in der Benachrichtigungs-Maske anlegen.');
            }
        }

        return $ergebnis;
    }

    /**
     * Darf die „ohne Route"-Warnung ins Protokoll?
     *
     * Dieser Task läuft JEDE MINUTE. Eine Warnung, die bei jedem Lauf mit-
     * geschrieben wird, steht nach 14 Tagen Historie gut 20.000 Mal in
     * ekkon_task_runs – mehrere MB derselben Zeile, und eine Lauf-Liste, in
     * der man nichts anderes mehr sieht. Ein Dauerzustand ist keine Nachricht.
     *
     * Deshalb: nur, wenn sich der Stand ändert (neue Meldungsart, andere
     * Anzahl) – und sonst einmal am Tag als Erinnerung, damit das Loch nicht
     * lautlos liegen bleibt.
     *
     * @param  array<string, int>  $stand  Meldungsart => Anzahl
     */
    private function warnungFaellig(array $stand): bool
    {
        $merker = Cache::get(self::WARN_MERKER);
        $heute = now()->toDateString();

        if (is_array($merker)
            && ($merker['stand'] ?? null) === $stand
            && ($merker['tag'] ?? null) === $heute) {
            return false;
        }

        Cache::put(self::WARN_MERKER, ['stand' => $stand, 'tag' => $heute], now()->addDays(30));

        return true;
    }

    /**
     * "termin-eskalation: 222, task-laufzeit: 3" – ohne die Meldungsart weiß
     * niemand, WELCHE Route fehlt.
     *
     * @param  array<string, int>  $stand
     */
    private function artenKlartext(array $stand): string
    {
        $teile = [];

        foreach ($stand as $art => $anzahl) {
            $teile[] = ($art !== '' ? $art : 'ohne Meldungsart').': '.$anzahl;
        }

        return implode(', ', $teile);
    }

    /** @return string|null null = zugestellt, sonst Fehlertext */
    private function zustellen(Notification $n): ?string
    {
        return match ($n->typ) {
            'teams' => $this->teams($n),
            'mail' => $this->mail($n),
            default => 'Unbekannter Typ: '.$n->typ,
        };
    }

    private function teams(Notification $n): ?string
    {
        $channel = TeamsChannel::find($n->ziel);

        if ($channel === null) {
            return 'Teams-Channel #'.$n->ziel.' existiert nicht (mehr).';
        }

        if (! $channel->aktiv) {
            return 'Teams-Channel "'.$channel->name.'" ist deaktiviert.';
        }

        return (new TeamsWebhookClient())->sende(
            (string) $channel->webhook_url,
            (string) $n->titel,
            (string) $n->text,
            (array) ($n->daten ?? []),
        );
    }

    private function mail(Notification $n): ?string
    {
        try {
            // Bevorzugt über die bearbeitbare Vorlage `ekkon:<meldungsart>`
            // (HTML + Text, gerahmt). Der Versand läuft über den Mail-Ausgangs-
            // korb – dort greift auch die Zustellbarkeitsprüfung.
            if ($this->vorlageVorhanden($n)) {
                app(\App\Mail\Vorlagen\VorlagenMailer::class)->senden(
                    'ekkon:'.$n->meldungsart,
                    (string) $n->ziel,
                    [
                        'ueberschrift' => (string) $n->titel,
                        'text' => (string) $n->text,
                        'quelle' => (string) ($n->quelle ?: '—'),
                    ],
                );

                return null;
            }

            // Rückfall: roher Text (Core ohne Mailvorlagen-System oder Altzeile
            // ohne Meldungsart).
            $text = (string) $n->text;

            if (($n->daten ?? []) !== []) {
                $text .= "\n\n".json_encode($n->daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($n->quelle) {
                $text .= "\n\nAusgelöst von: ".$n->quelle;
            }

            Mail::raw($text, function ($m) use ($n): void {
                $m->to((string) $n->ziel)->subject((string) $n->titel);
            });

            return null;
        } catch (Throwable $e) {
            // Wie beim Teams-Client: Ein Versandproblem darf den Task nicht
            // abstürzen lassen.
            return mb_substr($e->getMessage(), 0, 300);
        }
    }

    /** Gibt es für diese Meldungsart eine registrierte Mailvorlage? */
    private function vorlageVorhanden(Notification $n): bool
    {
        return $n->meldungsart
            && class_exists(\App\Mail\Vorlagen\VorlagenRegister::class)
            && app(\App\Mail\Vorlagen\VorlagenRegister::class)->finden('ekkon:'.$n->meldungsart) !== null;
    }

    /**
     * Zugestelltes nach 14 Tagen wegräumen – wie bei ekkon_task_runs.
     *
     * Dazu der Altbestand ohne Meldungsart: Diese Zeilen entstanden vor dem
     * 20.07.2026, als die Warteschlange die Meldungsart noch nicht mitschrieb.
     * Bei ihnen ist nicht erkennbar, WELCHE Route fehlte – als Hinweis sind sie
     * also wertlos, und nachwachsen können sie nicht (heute wird die
     * Meldungsart immer gesetzt). `ohne_ziel` MIT Meldungsart bleibt weiter
     * unangetastet: Das ist der Hinweis auf ein offenes Konfigurations-Loch.
     *
     * @return array{gepruned: int, gepruned_altbestand: int}
     */
    private function pruneAlte(): array
    {
        $zugestellt = Notification::query()
            ->where('status', 'sent')
            ->where('gesendet_am', '<', now()->subDays(self::PRUNE_TAGE))
            ->delete();

        $altbestand = Notification::query()
            ->where('status', 'ohne_ziel')
            ->where(fn ($q) => $q->whereNull('meldungsart')->orWhere('meldungsart', ''))
            ->where('created_at', '<', now()->subDays(self::PRUNE_TAGE))
            ->delete();

        return ['gepruned' => $zugestellt, 'gepruned_altbestand' => $altbestand];
    }
}

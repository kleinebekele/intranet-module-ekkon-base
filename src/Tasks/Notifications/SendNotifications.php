<?php

namespace Intranet\Modules\Ekkon\Tasks\Notifications;

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

    /** Pro Lauf, damit ein Rückstau die Minute nicht sprengt. */
    private const PRO_LAUF = 25;

    private const PRUNE_TAGE = 14;

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

        // Meldungen ohne Route sind ein Konfigurations-Loch: Irgendein Task
        // meldet etwas, das niemanden erreicht. Sichtbar machen, nicht zählen
        // und vergessen.
        $ohneZiel = Notification::query()->where('status', 'ohne_ziel')->count();

        if ($ohneZiel > 0) {
            $ergebnis['ohne_ziel'] = $ohneZiel;
            $this->msg($ohneZiel.' Meldung(en) ohne passende Route – niemand wird informiert. Route in der Benachrichtigungs-Maske anlegen.');
        }

        $ergebnis['gepruned'] = $this->pruneAlte();

        return $ergebnis;
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

    /** Zugestelltes nach 14 Tagen wegräumen – wie bei ekkon_task_runs. */
    private function pruneAlte(): int
    {
        return Notification::query()
            ->where('status', 'sent')
            ->where('gesendet_am', '<', now()->subDays(self::PRUNE_TAGE))
            ->delete();
    }
}

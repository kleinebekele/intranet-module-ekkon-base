<?php

namespace Intranet\Modules\Ekkon\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Postet eine Nachricht in einen Teams-Channel (Workflow-Webhook).
 *
 * ── Der klassische Webhook ist tot ──────────────────────────────────────
 * Microsoft hat die Office-365-Connectors ("Incoming Webhook") abgekündigt,
 * Endtermin Ende 2025. Alte outlook.office.com-URLs mit MessageCard
 * ("@type": "MessageCard") funktionieren nicht mehr. Ersatz: Teams-Channel →
 * ⋯ → Workflows → "Post to a channel when a webhook request is received";
 * die URL zeigt dann auf …logic.azure.com…
 *
 * ⚠️ DIE GEMEINSTE FALLE: Bei falschem Payload-Format antwortet der Workflow
 * mit 2xx und postet TROTZDEM NICHTS.
 * Ein grüner HTTP-Status beweist hier also gar nichts. Zwei Konsequenzen:
 *  1. Der "message"-Umschlag ist hier FEST VERDRAHTET und wird nicht dem
 *     Aufrufer überlassen – der könnte ihn falsch bauen und würde es nie
 *     erfahren.
 *  2. Der "Test senden"-Knopf in der Maske ist Pflicht, kein Luxus: Nur ein
 *     Blick in den Channel beweist, dass es wirklich ankommt.
 *
 * ⚠️ Diese Klasse WIRFT NIE. Ein Task hat seine Arbeit getan; er darf nicht
 * nachträglich an einer Benachrichtigung scheitern (Teams-Ausfall, abgelaufener
 * Flow, Netzproblem). Fehler werden geloggt und als Fehlertext zurückgegeben.
 */
class TeamsWebhookClient
{
    private const TIMEOUT = 15;

    /**
     * @return string|null null = erfolgreich, sonst der Fehlertext
     */
    public function sende(string $webhookUrl, string $titel, string $text, array $daten = []): ?string
    {
        if (trim($webhookUrl) === '') {
            return 'Keine Webhook-URL hinterlegt.';
        }

        try {
            $res = Http::timeout(self::TIMEOUT)
                ->asJson()
                ->post($webhookUrl, $this->umschlag($titel, $text, $daten));

            if (! $res->successful()) {
                $fehler = 'HTTP '.$res->status().': '.mb_substr($res->body(), 0, 300);
                Log::warning('Teams-Webhook fehlgeschlagen', ['fehler' => $fehler]);

                return $fehler;
            }

            // Kein Erfolgs-Beweis möglich – siehe Klassen-Kommentar.
            return null;
        } catch (Throwable $e) {
            Log::warning('Teams-Webhook Ausnahme', ['fehler' => $e->getMessage()]);

            return mb_substr($e->getMessage(), 0, 300);
        }
    }

    /**
     * Adaptive Card im "message"-Umschlag. Genau dieses Format erwartet der
     * Workflow-Trigger; jede Abweichung = 2xx ohne Post.
     */
    private function umschlag(string $titel, string $text, array $daten): array
    {
        $body = [
            [
                'type' => 'TextBlock',
                'text' => $titel,
                'weight' => 'Bolder',
                'size' => 'Medium',
                'wrap' => true,
            ],
            [
                'type' => 'TextBlock',
                'text' => $text,
                'wrap' => true,
            ],
        ];

        // Strukturiertes Beiwerk als Faktenliste – hilft beim Einordnen, ohne
        // den Text zuzumüllen. Werte werden gekürzt, damit eine Karte nicht
        // wegen eines riesigen Debug-Arrays platzt.
        $fakten = [];

        foreach ($daten as $name => $wert) {
            $fakten[] = [
                'title' => (string) $name,
                'value' => mb_substr(is_scalar($wert) ? (string) $wert : json_encode($wert, JSON_UNESCAPED_UNICODE), 0, 300),
            ];
        }

        if ($fakten !== []) {
            $body[] = ['type' => 'FactSet', 'facts' => $fakten];
        }

        return [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => [
                        'type' => 'AdaptiveCard',
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'version' => '1.4',
                        'body' => $body,
                    ],
                ],
            ],
        ];
    }
}

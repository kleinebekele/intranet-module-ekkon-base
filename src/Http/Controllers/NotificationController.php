<?php

namespace Intranet\Modules\Ekkon\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Intranet\Modules\Ekkon\Models\Notification;
use Intranet\Modules\Ekkon\Models\NotificationRoute;
use Intranet\Modules\Ekkon\Models\TeamsChannel;
use Intranet\Modules\Ekkon\Services\TeamsWebhookClient;
use Intranet\Modules\Ekkon\Support\TaskRegistry;

/**
 * Verwaltung der Benachrichtigungen: Teams-Channels, Routen, Warteschlange.
 *
 * Liegt unter den Task-Routen und erbt damit deren hartes EnsureUserIsAdmin –
 * das passt: Webhook-URLs sind Passwörter, und wer routet, entscheidet, wer
 * Betriebsmeldungen sieht.
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly TaskRegistry $registry,
    ) {
    }

    public function index(): View
    {
        return view('ekkon::notifications.index', [
            'channels' => TeamsChannel::query()->orderBy('name')->get(),
            'routes' => NotificationRoute::query()->with(['channel', 'mailUser'])->orderBy('meldungsart')->get(),
            // Auswahl für „Mail an einen bestimmten Administrator".
            'admins' => \App\Models\User::query()->where('is_admin', true)
                ->orderBy('name')->get(['id', 'name', 'email']),
            // Dropdown-Quelle: nur Meldungsarten, die ein Task auch wirklich
            // deklariert. Freitext wäre eine lautlose Fehlerquelle.
            'meldungsarten' => $this->registry->meldungsarten(),
            'offene' => Notification::query()
                ->whereIn('status', ['pending', 'failed', 'ohne_ziel'])
                ->latest('id')
                ->limit(50)
                ->get(),
            'letzte' => Notification::query()
                ->where('status', 'sent')
                ->latest('gesendet_am')
                ->limit(10)
                ->get(),
        ]);
    }

    // ── Teams-Channels ──────────────────────────────────────────────────

    public function channelStore(Request $request): RedirectResponse
    {
        $daten = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Nur die Workflow-URL akzeptieren: Der klassische Connector
            // (outlook.office.com) ist seit Ende 2025 tot und würde still
            // scheitern. Lieber hier hart ablehnen als später rätseln.
            'webhook_url' => ['required', 'url', 'starts_with:https://', 'max:2000'],
            'notiz' => ['nullable', 'string', 'max:255'],
        ]);

        if (str_contains($daten['webhook_url'], 'outlook.office.com')) {
            return back()->withInput()->withErrors([
                'webhook_url' => 'Das ist eine klassische Connector-URL (outlook.office.com). Die hat Microsoft Ende 2025 abgeschaltet – bitte einen Teams-Workflow anlegen (URL auf logic.azure.com).',
            ]);
        }

        TeamsChannel::create($daten + ['aktiv' => true]);

        return back()->with('status', 'Channel angelegt. Jetzt bitte "Test senden" – nur ein Blick in den Channel beweist, dass es ankommt.');
    }

    public function channelToggle(TeamsChannel $channel): RedirectResponse
    {
        $channel->update(['aktiv' => ! $channel->aktiv]);

        return back();
    }

    public function channelDestroy(TeamsChannel $channel): RedirectResponse
    {
        $channel->delete();

        return back()->with('status', 'Channel gelöscht.');
    }

    /**
     * "Test senden" – laut Konzept Pflicht, kein Luxus.
     *
     * Grund: Bei falschem Payload-Format antwortet der Teams-Workflow mit 2xx
     * und postet TROTZDEM NICHTS. Ein grüner HTTP-Status beweist hier gar
     * nichts – nur ein Blick in den Channel beweist es. Deshalb synchron, mit
     * sofortiger Rückmeldung, statt über die Warteschlange.
     */
    public function channelTest(TeamsChannel $channel): RedirectResponse
    {
        $fehler = (new TeamsWebhookClient())->sende(
            (string) $channel->webhook_url,
            'Testnachricht aus dem Intranet',
            'Wenn du das hier liest, funktioniert der Channel "'.$channel->name.'".',
            ['Ausgelöst' => now()->format('d.m.Y H:i'), 'Channel' => $channel->name],
        );

        if ($fehler !== null) {
            return back()->withErrors(['test' => 'Test fehlgeschlagen: '.$fehler]);
        }

        return back()->with('status', 'Test abgeschickt (HTTP ok). ⚠ Bitte im Teams-Channel nachsehen: Bei falschem Format meldet der Workflow trotzdem Erfolg und postet nichts.');
    }

    // ── Routen ──────────────────────────────────────────────────────────

    public function routeStore(Request $request): RedirectResponse
    {
        // Bei Mail eines von drei Zielen: alle Admins, ein bestimmter Benutzer,
        // oder eine feste Adresse.
        $mailZiel = $request->input('typ') === 'mail' ? $request->input('mail_ziel', 'admins') : null;

        $daten = $request->validate([
            'meldungsart' => ['required', 'string', Rule::in(array_keys($this->registry->meldungsarten()))],
            'typ' => ['required', Rule::in(['mail', 'teams'])],
            'teams_channel_id' => ['nullable', 'exists:ekkon_teams_channels,id', 'required_if:typ,teams'],
            'mail_ziel' => ['nullable', Rule::in(['admins', 'benutzer', 'adresse'])],
            // Feste Adresse nur nötig, wenn Mail-Ziel „feste Adresse" ist.
            'mail_empfaenger' => ['nullable', 'email', Rule::requiredIf(fn () => $mailZiel === 'adresse')],
            // Benutzer nur nötig, wenn Mail-Ziel „bestimmter Benutzer" ist.
            'mail_user_id' => ['nullable', 'exists:users,id', Rule::requiredIf(fn () => $mailZiel === 'benutzer')],
        ]);

        // Sauber halten: nur das Feld des gewählten Ziels behalten, der Rest null.
        $werte = [
            'meldungsart' => $daten['meldungsart'],
            'typ' => $daten['typ'],
            'teams_channel_id' => null,
            'mail_empfaenger' => null,
            'mail_an_admins' => false,
            'mail_user_id' => null,
            'aktiv' => true,
        ];

        if ($daten['typ'] === 'teams') {
            $werte['teams_channel_id'] = $daten['teams_channel_id'];
        } elseif ($mailZiel === 'admins') {
            $werte['mail_an_admins'] = true;
        } elseif ($mailZiel === 'benutzer') {
            $werte['mail_user_id'] = $daten['mail_user_id'];
        } else {
            $werte['mail_empfaenger'] = $daten['mail_empfaenger'];
        }

        NotificationRoute::create($werte);

        return back()->with('status', 'Route angelegt.');
    }

    public function routeToggle(NotificationRoute $route): RedirectResponse
    {
        $route->update(['aktiv' => ! $route->aktiv]);

        return back();
    }

    public function routeDestroy(NotificationRoute $route): RedirectResponse
    {
        $route->delete();

        return back()->with('status', 'Route gelöscht.');
    }

    // ── Warteschlange ───────────────────────────────────────────────────

    /** Fehlgeschlagene Meldung erneut in die Schlange stellen. */
    public function retry(Notification $notification): RedirectResponse
    {
        $notification->update([
            'status' => 'pending',
            'versuche' => 0,
            'letzter_fehler' => null,
        ]);

        return back()->with('status', 'Benachrichtigung wird beim nächsten Lauf erneut versucht.');
    }
}

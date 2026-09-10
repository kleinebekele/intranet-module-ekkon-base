<?php

namespace Intranet\Modules\Ekkon\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Intranet\Modules\Ekkon\Models\WebhookEingang;
use Intranet\Modules\Ekkon\Models\WebhookQuelle;

/**
 * Webhook-Eingang: fremde Dienste schicken uns Daten, wir speichern sie erst
 * einmal roh. Die Admin-Seite zeigt, was ankam; daraus entsteht später die
 * Fachlogik (z. B. Sally.io-Zusammenfassungen nach Titel an Teams-Kanäle).
 */
class WebhookController extends Controller
{
    /** Größer wird nicht gespeichert – ein Webhook ist eine Nachricht, keine Datei. */
    private const MAX_BYTES = 1_000_000;

    // ── Öffentlicher Empfang ────────────────────────────────────────────

    /**
     * POST /webhooks/ekkon/{schluessel} – ohne Session, ohne CSRF, nur der
     * Schlüssel in der URL entscheidet. Unbekannter/inaktiver Schlüssel → 404,
     * damit man von außen nicht erkennt, ob es die Quelle gibt.
     */
    public function empfangen(Request $request, string $schluessel): JsonResponse
    {
        $quelle = WebhookQuelle::query()->where('schluessel', $schluessel)->where('aktiv', true)->first();
        if ($quelle === null) {
            abort(404);
        }

        $body = (string) $request->getContent();
        if (strlen($body) > self::MAX_BYTES) {
            return response()->json(['ok' => false, 'fehler' => 'Payload zu groß'], 413);
        }

        // Header ohne Geheimnisse: Authorization & Cookies bleiben draußen.
        $headers = collect($request->headers->all())
            ->except(['authorization', 'cookie', 'x-csrf-token'])
            ->map(fn (array $werte): string => implode(', ', $werte))
            ->all();

        $eingang = $quelle->eingaenge()->create([
            'methode' => $request->method(),
            'content_type' => mb_substr((string) $request->header('Content-Type', ''), 0, 255) ?: null,
            'ip' => $this->anonymeIp((string) $request->ip()),
            'headers' => $headers,
            'body' => $body,
            'groesse' => strlen($body),
        ]);

        return response()->json(['ok' => true, 'id' => $eingang->id]);
    }

    // ── Admin-Seite ─────────────────────────────────────────────────────

    public function index(): View
    {
        return view('ekkon::webhooks.index', [
            'quellen' => WebhookQuelle::query()->withCount('eingaenge')->orderBy('name')->get(),
            'eingaenge' => WebhookEingang::query()->with('quelle')->latest('id')->limit(100)->get(),
        ]);
    }

    public function quelleStore(Request $request): RedirectResponse
    {
        $daten = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'notiz' => ['nullable', 'string', 'max:255'],
        ]);

        WebhookQuelle::create($daten + ['schluessel' => WebhookQuelle::neuerSchluessel(), 'aktiv' => true]);

        return back()->with('status', 'Quelle angelegt – die URL steht in der Liste, bitte beim Absender eintragen.');
    }

    public function quelleToggle(WebhookQuelle $quelle): RedirectResponse
    {
        $quelle->update(['aktiv' => ! $quelle->aktiv]);

        return back();
    }

    public function quelleDestroy(WebhookQuelle $quelle): RedirectResponse
    {
        $quelle->delete(); // Eingänge hängen per FK dran und gehen mit

        return back()->with('status', 'Quelle samt Eingängen gelöscht.');
    }

    public function eingangDestroy(Request $request, WebhookEingang $eingang): RedirectResponse|JsonResponse
    {
        $eingang->delete();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    /** IPv4: letztes Oktett weg; IPv6: auf /48 kürzen. */
    private function anonymeIp(string $ip): ?string
    {
        if ($ip === '') {
            return null;
        }
        if (str_contains($ip, ':')) {
            $teile = explode(':', $ip);

            return implode(':', array_slice($teile, 0, 3)).'::';
        }
        $teile = explode('.', $ip);
        if (count($teile) === 4) {
            $teile[3] = '0';
        }

        return implode('.', $teile);
    }
}

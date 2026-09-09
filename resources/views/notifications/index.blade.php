<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Ekkon · Benachrichtigungen</h2>
    </x-slot>

    <div class="py-6">
        {{-- Der aktive Tab steht in der URL (#routen/#channels/#meldungen) und
             überlebt so jedes Speichern/Löschen (die Formulare hängen ihn an
             die Action). Löschen in der Meldungsliste geht per Fetch, ohne
             Seitenwechsel – man bleibt, wo man ist. --}}
        <div class="w-full mx-auto sm:px-6 lg:px-8"
             x-data="{
                tab: ['routen', 'channels', 'meldungen'].includes(location.hash.slice(1)) ? location.hash.slice(1) : 'routen',
                bearbeite: null,
                meldung: null,
                geloescht: [],
                loeschen(id, url, titel) {
                    if (! confirm('Meldung „' + titel + '“ wirklich löschen? Sie wird dann nie zugestellt.')) return;
                    fetch(url, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                    }).then(r => { if (r.ok) { this.geloescht.push(id); } else { alert('Löschen fehlgeschlagen (HTTP ' + r.status + ').'); } })
                      .catch(() => alert('Löschen fehlgeschlagen.'));
                }
             }"
             x-init="$watch('tab', t => history.replaceState(null, '', '#' + t)); document.querySelectorAll('form[method=POST]').forEach(f => f.addEventListener('submit', () => { f.action = f.action.split('#')[0] + '#' + tab; }))">

            {{-- Fehler aus Validierung/Test. Erfolg rendert das Core-Layout selbst. --}}
            @if ($errors->any())
                <div class="mb-6 rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $fehler)
                            <li>{{ $fehler }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ── Tab-Leiste ───────────────────────────────────────────── --}}
            <nav class="mb-6 flex flex-wrap gap-1 border-b border-gray-200">
                @php
                    $tabs = [
                        'routen' => 'Routen',
                        'channels' => 'Teams-Channels',
                        'meldungen' => 'Offene Meldungen',
                    ];
                @endphp
                @foreach ($tabs as $key => $label)
                    <button type="button" @click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}'
                                ? 'border-indigo-600 text-indigo-700'
                                : 'border-transparent text-gray-500 hover:text-gray-700'"
                            class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition">{{ $label }}</button>
                @endforeach
            </nav>

            {{-- ── Routen ───────────────────────────────────────────────── --}}
            <div x-show="tab === 'routen'" class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <h3 class="font-semibold text-gray-700 mb-1">Routen</h3>
                <p class="text-sm text-gray-500 mb-4">
                    Eine Zeile = <b>ein Ziel</b> für <b>eine Meldungsart</b>. Für Teams <i>und</i> Mail
                    einfach zwei Zeilen anlegen. Bei Mail führt die Meldungsart zur <b>Mailvorlage</b>,
                    die verschickt wird.
                </p>

                @if ($routes->isEmpty())
                    <p class="text-sm text-gray-500 mb-4 italic">Noch keine Route – gemeldet wird also noch nichts.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm mb-4">
                            <thead class="text-left text-gray-500 border-b">
                                <tr>
                                    <th class="py-2 pr-4">Meldungsart</th>
                                    <th class="py-2 pr-4">Typ</th>
                                    <th class="py-2 pr-4">Ziel</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2 pr-4">Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($routes as $route)
                                    <tr class="border-b last:border-0">
                                        <td class="py-2 pr-4 font-mono text-xs">
                                            @if ($route->typ === 'mail' && \Illuminate\Support\Facades\Route::has('admin.mailvorlagen.edit'))
                                                <a href="{{ route('admin.mailvorlagen.edit', 'ekkon:'.$route->meldungsart) }}{{ $route->vorschauMail() ? '?testmail='.urlencode($route->vorschauMail()) : '' }}"
                                                   class="text-indigo-600 hover:underline" title="Mailvorlage bearbeiten">{{ $route->meldungsart }}</a>
                                            @else
                                                {{ $route->meldungsart }}
                                            @endif
                                            @unless (array_key_exists($route->meldungsart, $meldungsarten))
                                                {{-- Task umbenannt/entfernt: Die Route läuft ins Leere. --}}
                                                <span class="ml-1 text-xs font-semibold text-red-700 bg-red-100 rounded px-2 py-0.5"
                                                      title="Kein Task deklariert diese Meldungsart (mehr).">verwaist</span>
                                            @endunless
                                        </td>
                                        <td class="py-2 pr-4">{{ $route->typ }}</td>
                                        <td class="py-2 pr-4">{{ $route->zielText() }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($route->aktiv)
                                                <span class="text-xs font-semibold text-green-700 bg-green-100 rounded px-2 py-0.5">aktiv</span>
                                            @else
                                                <span class="text-xs font-semibold text-gray-500 bg-gray-100 rounded px-2 py-0.5">aus</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4">
                                            <div class="flex flex-wrap gap-2">
                                                <form method="POST" action="{{ route('module.ekkon.notifications.route.toggle', $route) }}">
                                                    @csrf
                                                    <button class="text-gray-600 hover:underline">{{ $route->aktiv ? 'deaktivieren' : 'aktivieren' }}</button>
                                                </form>
                                                <form method="POST" action="{{ route('module.ekkon.notifications.route.destroy', $route) }}"
                                                      onsubmit="return confirm('Route wirklich löschen?')">
                                                    @csrf @method('DELETE')
                                                    <button class="text-red-700 hover:underline">löschen</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if (empty($meldungsarten))
                    <p class="text-sm text-gray-500 italic border-t pt-4">
                        Kein Task deklariert bisher Meldungsarten (<code>$meldungsarten</code>).
                    </p>
                @else
                    <form method="POST" action="{{ route('module.ekkon.notifications.route.store') }}"
                          x-data="{ typ: '{{ old('typ', 'mail') }}', mailZiel: '{{ old('mail_ziel', 'admins') }}' }"
                          class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end border-t pt-4">
                        @csrf
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Meldungsart</label>
                            <select name="meldungsart" class="w-full rounded-md border-gray-300 text-sm">
                                @foreach ($meldungsarten as $art => $klartext)
                                    <option value="{{ $art }}" @selected(old('meldungsart') === $art)>{{ $klartext }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Typ</label>
                            <select name="typ" x-model="typ" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="mail">Mail</option>
                                <option value="teams">Teams</option>
                            </select>
                        </div>
                        <div x-show="typ === 'mail'" x-cloak>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Mail an</label>
                            <select name="mail_ziel" x-model="mailZiel" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="admins">alle System-Admins</option>
                                <option value="benutzer">bestimmten Administrator</option>
                                <option value="adresse">feste Adresse</option>
                            </select>
                        </div>
                        <div x-show="typ === 'mail' && mailZiel === 'benutzer'" x-cloak>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Administrator</label>
                            <select name="mail_user_id" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="">– bitte wählen –</option>
                                @foreach ($admins as $admin)
                                    <option value="{{ $admin->id }}" @selected(old('mail_user_id') == $admin->id)>{{ $admin->name }} ({{ $admin->email }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div x-show="typ === 'mail' && mailZiel === 'adresse'" x-cloak>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Adresse</label>
                            <input name="mail_empfaenger" type="email" value="{{ old('mail_empfaenger') }}"
                                   class="w-full rounded-md border-gray-300 text-sm" placeholder="name@firma.de">
                        </div>
                        <div x-show="typ === 'teams'" x-cloak>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Channel</label>
                            <select name="teams_channel_id" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="">– bitte wählen –</option>
                                @foreach ($channels as $channel)
                                    <option value="{{ $channel->id }}" @selected(old('teams_channel_id') == $channel->id)>{{ $channel->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-4">
                            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                Route anlegen
                            </button>
                        </div>
                    </form>
                @endif
            </div>

            {{-- ── Teams-Channels ───────────────────────────────────────── --}}
            <div x-show="tab === 'channels'" x-cloak class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <h3 class="font-semibold text-gray-700 mb-1">Teams-Channels</h3>
                <p class="text-sm text-gray-500 mb-4">
                    Der klassische „Incoming Webhook" ist seit Ende 2025 abgeschaltet.
                    Neuen Channel anlegen über: Teams-Channel → ⋯ → <b>Workflows</b> →
                    „Post to a channel when a webhook request is received" → URL auf
                    <code>logic.azure.com</code>.
                    <br>
                    ⚠ Der Flow gehört dem, der ihn anlegt – wird das Konto deaktiviert, sind alle
                    Meldungen weg. Möglichst einen technischen Benutzer verwenden.
                </p>

                @if ($channels->isEmpty())
                    <p class="text-sm text-gray-500 mb-4 italic">Noch kein Channel angelegt.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm mb-4">
                            <thead class="text-left text-gray-500 border-b">
                                <tr>
                                    <th class="py-2 pr-4">Name</th>
                                    <th class="py-2 pr-4">Notiz</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2 pr-4">Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($channels as $channel)
                                    <tr class="border-b last:border-0">
                                        <td class="py-2 pr-4 font-medium">{{ $channel->name }}</td>
                                        <td class="py-2 pr-4 text-gray-500">{{ $channel->notiz }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($channel->aktiv)
                                                <span class="text-xs font-semibold text-green-700 bg-green-100 rounded px-2 py-0.5">aktiv</span>
                                            @else
                                                <span class="text-xs font-semibold text-gray-500 bg-gray-100 rounded px-2 py-0.5">aus</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4">
                                            <div class="flex flex-wrap gap-2">
                                                <form method="POST" action="{{ route('module.ekkon.notifications.channel.test', $channel) }}">
                                                    @csrf
                                                    <button class="text-indigo-700 hover:underline">Test senden</button>
                                                </form>
                                                <button type="button" @click="bearbeite = (bearbeite === {{ $channel->id }} ? null : {{ $channel->id }})"
                                                        class="text-gray-600 hover:underline">bearbeiten</button>
                                                <form method="POST" action="{{ route('module.ekkon.notifications.channel.toggle', $channel) }}">
                                                    @csrf
                                                    <button class="text-gray-600 hover:underline">{{ $channel->aktiv ? 'deaktivieren' : 'aktivieren' }}</button>
                                                </form>
                                                <form method="POST" action="{{ route('module.ekkon.notifications.channel.destroy', $channel) }}"
                                                      onsubmit="return confirm('Channel „{{ $channel->name }}“ wirklich löschen? Routen darauf verlieren ihr Ziel.')">
                                                    @csrf @method('DELETE')
                                                    <button class="text-red-700 hover:underline">löschen</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr x-show="bearbeite === {{ $channel->id }}" x-cloak class="border-b last:border-0 bg-gray-50">
                                        <td colspan="4" class="py-3 pr-4">
                                            <form method="POST" action="{{ route('module.ekkon.notifications.channel.update', $channel) }}"
                                                  class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                                                @csrf @method('PUT')
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                                                    <input name="name" value="{{ $channel->name }}" required
                                                           class="w-full rounded-md border-gray-300 text-sm">
                                                </div>
                                                <div class="md:col-span-2">
                                                    <label class="block text-xs font-medium text-gray-600 mb-1">
                                                        Neue Webhook-URL <span class="text-gray-400">(leer lassen = bestehende behalten; sie wird nie angezeigt)</span>
                                                    </label>
                                                    <input name="webhook_url" value=""
                                                           class="w-full rounded-md border-gray-300 text-sm" placeholder="https://…logic.azure.com/…">
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-600 mb-1">Notiz</label>
                                                    <input name="notiz" value="{{ $channel->notiz }}"
                                                           class="w-full rounded-md border-gray-300 text-sm" placeholder="optional">
                                                </div>
                                                <div class="md:col-span-4 flex gap-3 items-center">
                                                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                                        Speichern
                                                    </button>
                                                    <button type="button" @click="bearbeite = null" class="text-sm text-gray-600 hover:underline">abbrechen</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <form method="POST" action="{{ route('module.ekkon.notifications.channel.store') }}"
                      class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end border-t pt-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                        <input name="name" value="{{ old('name') }}" required
                               class="w-full rounded-md border-gray-300 text-sm" placeholder="z. B. Betrieb">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">
                            Webhook-URL <span class="text-gray-400">(wird verschlüsselt gespeichert)</span>
                        </label>
                        <input name="webhook_url" value="{{ old('webhook_url') }}" required
                               class="w-full rounded-md border-gray-300 text-sm" placeholder="https://…logic.azure.com/…">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Notiz</label>
                        <input name="notiz" value="{{ old('notiz') }}"
                               class="w-full rounded-md border-gray-300 text-sm" placeholder="optional">
                    </div>
                    <div class="md:col-span-4">
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Channel anlegen
                        </button>
                    </div>
                </form>
            </div>

            {{-- ── Warteschlange ────────────────────────────────────────── --}}
            <div x-show="tab === 'meldungen'" x-cloak class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <h3 class="font-semibold text-gray-700 mb-1">Offene &amp; auffällige Meldungen</h3>
                <p class="text-sm text-gray-500 mb-4">
                    <b>ohne_ziel</b> heißt: Ein Task hat gemeldet, aber keine Route passte –
                    <b>niemand wurde informiert</b>. Meldungen verschwinden hier nie stillschweigend.
                </p>

                {{-- Das Loch zuerst: WELCHE Meldungsart hat keine Route? Die Liste
                     unten zeigt nur die letzten 50 Zeilen – bei hunderten gleichen
                     Meldungen sieht man darin nicht, woran es liegt. --}}
                @if ($ohneRoute->isNotEmpty() || $ohneRouteAlt->isNotEmpty())
                    <div class="mb-6 rounded-lg border border-yellow-300 bg-yellow-50 p-4">
                        <h4 class="font-semibold text-yellow-900 mb-2">Meldungsarten ohne Route</h4>
                        <table class="text-sm w-full">
                            <thead class="text-left text-yellow-900/70 border-b border-yellow-200">
                                <tr>
                                    <th class="py-1 pr-4">Meldungsart</th>
                                    <th class="py-1 pr-4">liegen gelassen</th>
                                    <th class="py-1 pr-4">zuletzt</th>
                                    <th class="py-1 pr-4">Klartext</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ohneRoute as $zeile)
                                    <tr class="border-b border-yellow-200 last:border-0">
                                        <td class="py-1 pr-4 font-mono text-xs">{{ $zeile->meldungsart ?: '—' }}</td>
                                        <td class="py-1 pr-4 font-medium">{{ $zeile->anzahl }}</td>
                                        <td class="py-1 pr-4 text-gray-600">
                                            {{ $zeile->zuletzt ? \Illuminate\Support\Carbon::parse($zeile->zuletzt)->format('d.m.Y H:i') : '–' }}
                                        </td>
                                        <td class="py-1 pr-4 text-gray-600">
                                            {{ $meldungsarten[$zeile->meldungsart] ?? 'kein Task deklariert diese Meldungsart mehr' }}
                                        </td>
                                    </tr>
                                @endforeach

                                {{-- Altbestand ohne Meldungsart: als "—" unerklaerlich,
                                     deshalb hier mit Task und Zeitraum. --}}
                                @foreach ($ohneRouteAlt as $alt)
                                    <tr class="border-b border-yellow-200 last:border-0 text-gray-500">
                                        <td class="py-1 pr-4 font-mono text-xs">— (Altbestand)</td>
                                        <td class="py-1 pr-4 font-medium">{{ $alt->anzahl }}</td>
                                        <td class="py-1 pr-4">
                                            {{ $alt->zuletzt ? \Illuminate\Support\Carbon::parse($alt->zuletzt)->format('d.m.Y H:i') : '–' }}
                                        </td>
                                        <td class="py-1 pr-4">
                                            aus <b>{{ $alt->quelle ?: 'unbekannter Quelle' }}</b>, angelegt vor dem 20.07.2026 –
                                            damals speicherte die Warteschlange noch keine Meldungsart
                                            @if ($alt->seit)
                                                (ab {{ \Illuminate\Support\Carbon::parse($alt->seit)->format('d.m.Y') }})
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <button type="button" @click="tab = 'routen'"
                                class="mt-3 rounded-md bg-yellow-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-yellow-700">
                            Route anlegen
                        </button>
                    </div>
                @endif

                @if ($offene->isEmpty())
                    <p class="text-sm text-gray-500 italic">Nichts offen.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500 border-b">
                                <tr>
                                    <th class="py-2 pr-4">Datum</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2 pr-4">Typ</th>
                                    <th class="py-2 pr-4">Titel</th>
                                    <th class="py-2 pr-4">Quelle</th>
                                    <th class="py-2 pr-4">Versuche</th>
                                    <th class="py-2 pr-4">Letzter Fehler</th>
                                    <th class="py-2 pr-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($offene as $n)
                                    @php
                                        $details = [
                                            'datum' => $n->created_at?->format('d.m.Y H:i:s'),
                                            'status' => $n->status,
                                            'typ' => $n->typ,
                                            'ziel' => $n->ziel,
                                            'meldungsart' => $n->meldungsart,
                                            'quelle' => $n->quelle,
                                            'versuche' => $n->versuche,
                                            'titel' => $n->titel,
                                            'text' => $n->text,
                                            'daten' => $n->daten ? json_encode($n->daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                                            'fehler' => $n->letzter_fehler,
                                        ];
                                    @endphp
                                    <tr x-show="! geloescht.includes({{ $n->id }})" class="border-b last:border-0 {{ $n->status === 'failed' ? 'bg-red-50' : ($n->status === 'ohne_ziel' ? 'bg-yellow-50' : '') }}">
                                        <td class="py-2 pr-4 whitespace-nowrap text-gray-600">{{ $n->created_at?->format('d.m.Y H:i') }}</td>
                                        <td class="py-2 pr-4 font-medium">{{ $n->status }}</td>
                                        <td class="py-2 pr-4">{{ $n->typ }}</td>
                                        <td class="py-2 pr-4">
                                            <button type="button" @click="meldung = {{ \Illuminate\Support\Js::from($details) }}"
                                                    class="text-left text-indigo-700 hover:underline" title="Ganze Meldung anzeigen">{{ $n->titel }}</button>
                                        </td>
                                        <td class="py-2 pr-4 font-mono text-xs text-gray-500">{{ $n->quelle }}</td>
                                        <td class="py-2 pr-4">{{ $n->versuche }}</td>
                                        <td class="py-2 pr-4 text-red-700 text-xs max-w-md truncate" title="{{ $n->letzter_fehler }}">{{ $n->letzter_fehler }}</td>
                                        <td class="py-2 pr-4">
                                            <div class="flex flex-wrap gap-2 whitespace-nowrap">
                                                @if ($n->status === 'failed')
                                                    <form method="POST" action="{{ route('module.ekkon.notifications.retry', $n) }}">
                                                        @csrf
                                                        <button class="text-indigo-700 hover:underline">erneut</button>
                                                    </form>
                                                @endif
                                                <button type="button" class="text-red-700 hover:underline"
                                                        @click="loeschen({{ $n->id }}, '{{ route('module.ekkon.notifications.destroy', $n) }}', {{ \Illuminate\Support\Js::from($n->titel) }})">löschen</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Modal: die ganze Meldung, so wie sie zugestellt worden wäre --}}
                <div x-show="meldung !== null" x-cloak @keydown.escape.window="meldung = null"
                     class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-900/50">
                    <div @click.outside="meldung = null" class="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-lg bg-white shadow-xl">
                        <div class="flex items-start justify-between gap-4 border-b px-5 py-3">
                            <div>
                                <h4 class="font-semibold text-gray-800" x-text="meldung?.titel"></h4>
                                <p class="text-xs text-gray-500">
                                    <span x-text="meldung?.datum"></span> ·
                                    <span x-text="meldung?.status"></span> ·
                                    <span x-text="meldung?.typ"></span>
                                    <template x-if="meldung?.ziel"><span> → <span x-text="meldung.ziel"></span></span></template>
                                </p>
                            </div>
                            <button type="button" @click="meldung = null" class="text-gray-400 hover:text-gray-700 text-xl leading-none" aria-label="Schließen">&times;</button>
                        </div>
                        <div class="px-5 py-4 space-y-4 text-sm">
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-xs">
                                <div><dt class="text-gray-500">Meldungsart</dt><dd class="font-mono" x-text="meldung?.meldungsart || '—'"></dd></div>
                                <div><dt class="text-gray-500">Quelle</dt><dd class="font-mono" x-text="meldung?.quelle || '—'"></dd></div>
                                <div><dt class="text-gray-500">Versuche</dt><dd x-text="meldung?.versuche"></dd></div>
                            </dl>
                            <div>
                                <div class="text-xs text-gray-500 mb-1">Text</div>
                                <pre class="whitespace-pre-wrap font-sans rounded bg-gray-50 p-3" x-text="meldung?.text"></pre>
                            </div>
                            <template x-if="meldung?.daten">
                                <div>
                                    <div class="text-xs text-gray-500 mb-1">Daten</div>
                                    <pre class="whitespace-pre-wrap text-xs rounded bg-gray-50 p-3 overflow-x-auto" x-text="meldung.daten"></pre>
                                </div>
                            </template>
                            <template x-if="meldung?.fehler">
                                <div>
                                    <div class="text-xs text-gray-500 mb-1">Letzter Fehler</div>
                                    <pre class="whitespace-pre-wrap text-xs rounded bg-red-50 text-red-800 p-3 overflow-x-auto" x-text="meldung.fehler"></pre>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                @if ($letzte->isNotEmpty())
                    <h4 class="font-medium text-gray-600 mt-6 mb-2 text-sm">Zuletzt versendet</h4>
                    <ul class="text-sm text-gray-500 space-y-1">
                        @foreach ($letzte as $n)
                            <li>
                                <span class="text-gray-400">{{ $n->gesendet_am?->format('d.m. H:i') }}</span>
                                · {{ $n->typ }} · {{ $n->titel }}
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>

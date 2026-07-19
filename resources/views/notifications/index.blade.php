<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Ekkon · Benachrichtigungen</h2>
    </x-slot>

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- Fehler aus Validierung/Test. Erfolg rendert das Core-Layout selbst. --}}
            @if ($errors->any())
                <div class="rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $fehler)
                            <li>{{ $fehler }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ── Routen ───────────────────────────────────────────────── --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <h3 class="font-semibold text-gray-700 mb-1">Routen</h3>
                <p class="text-sm text-gray-500 mb-4">
                    Eine Zeile = <b>ein Ziel</b> für <b>eine Meldungsart</b>. Für Teams <i>und</i> Mail
                    einfach zwei Zeilen anlegen. Die Meldungsarten kommen aus den Tasks selbst –
                    darum ein Dropdown statt Freitext: Ein Tippfehler würde die Meldung lautlos
                    ins Nichts routen.
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
                                            {{ $route->meldungsart }}
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
            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
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
            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <h3 class="font-semibold text-gray-700 mb-1">Offene &amp; auffällige Meldungen</h3>
                <p class="text-sm text-gray-500 mb-4">
                    <b>ohne_ziel</b> heißt: Ein Task hat gemeldet, aber keine Route passte –
                    <b>niemand wurde informiert</b>. Meldungen verschwinden hier nie stillschweigend.
                </p>

                @if ($offene->isEmpty())
                    <p class="text-sm text-gray-500 italic">Nichts offen.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500 border-b">
                                <tr>
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
                                    <tr class="border-b last:border-0 {{ $n->status === 'failed' ? 'bg-red-50' : ($n->status === 'ohne_ziel' ? 'bg-yellow-50' : '') }}">
                                        <td class="py-2 pr-4 font-medium">{{ $n->status }}</td>
                                        <td class="py-2 pr-4">{{ $n->typ }}</td>
                                        <td class="py-2 pr-4">{{ $n->titel }}</td>
                                        <td class="py-2 pr-4 font-mono text-xs text-gray-500">{{ $n->quelle }}</td>
                                        <td class="py-2 pr-4">{{ $n->versuche }}</td>
                                        <td class="py-2 pr-4 text-red-700 text-xs">{{ $n->letzter_fehler }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($n->status === 'failed')
                                                <form method="POST" action="{{ route('module.ekkon.notifications.retry', $n) }}">
                                                    @csrf
                                                    <button class="text-indigo-700 hover:underline">erneut</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

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

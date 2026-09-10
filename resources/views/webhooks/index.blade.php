<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Ekkon · Webhook-Eingang</h2>
    </x-slot>

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-8"
             x-data="{
                eingang: null,
                geloescht: [],
                loeschen(id, url) {
                    fetch(url, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                    }).then(r => { if (r.ok) { this.geloescht.push(id); } else { (window.hinweis ?? alert)('Löschen fehlgeschlagen (HTTP ' + r.status + ').'); } })
                      .catch(() => (window.hinweis ?? alert)('Löschen fehlgeschlagen.'));
                },
                kopiere(text) {
                    navigator.clipboard?.writeText(text).then(() => (window.hinweis ?? alert)('URL kopiert.'));
                }
             }">

            @if ($errors->any())
                <div class="rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $fehler)
                            <li>{{ $fehler }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ── Quellen ──────────────────────────────────────────────── --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <h3 class="font-semibold text-gray-700 mb-1">Quellen</h3>
                <p class="text-sm text-gray-500 mb-4">
                    Je Absender eine Quelle. Die URL enthält einen geheimen Schlüssel – sie ist ein
                    Passwort und gehört nur beim Absender eingetragen. Alles, was dort per POST ankommt,
                    landet unten roh in der Liste; erst danach entscheiden wir, was damit passiert.
                </p>

                @if ($quellen->isEmpty())
                    <p class="text-sm text-gray-500 mb-4 italic">Noch keine Quelle angelegt.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm mb-4">
                            <thead class="text-left text-gray-500 border-b">
                                <tr>
                                    <th class="py-2 pr-4">Name</th>
                                    <th class="py-2 pr-4">URL</th>
                                    <th class="py-2 pr-4">Eingänge</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2 pr-4">Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($quellen as $quelle)
                                    <tr class="border-b last:border-0">
                                        <td class="py-2 pr-4 font-medium">
                                            {{ $quelle->name }}
                                            @if ($quelle->notiz)
                                                <span class="block text-xs text-gray-500">{{ $quelle->notiz }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4">
                                            <div class="flex items-center gap-2">
                                                <code class="text-xs bg-gray-50 rounded px-2 py-1 break-all">{{ $quelle->url() }}</code>
                                                <button type="button" @click="kopiere({{ \Illuminate\Support\Js::from($quelle->url()) }})"
                                                        class="text-indigo-700 hover:underline text-xs whitespace-nowrap">kopieren</button>
                                            </div>
                                        </td>
                                        <td class="py-2 pr-4">{{ $quelle->eingaenge_count }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($quelle->aktiv)
                                                <span class="text-xs font-semibold text-green-700 bg-green-100 rounded px-2 py-0.5">aktiv</span>
                                            @else
                                                <span class="text-xs font-semibold text-gray-500 bg-gray-100 rounded px-2 py-0.5">aus</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4">
                                            <div class="flex flex-wrap gap-2 whitespace-nowrap">
                                                <form method="POST" action="{{ route('module.ekkon.webhooks.quelle.toggle', $quelle) }}">
                                                    @csrf
                                                    <button class="text-gray-600 hover:underline">{{ $quelle->aktiv ? 'deaktivieren' : 'aktivieren' }}</button>
                                                </form>
                                                <form method="POST" action="{{ route('module.ekkon.webhooks.quelle.destroy', $quelle) }}"
                                                      data-bestaetigen="Quelle „{{ $quelle->name }}“ samt allen Eingängen löschen? Die URL wird damit ungültig."
                                                      data-knopf="Löschen">
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

                <form method="POST" action="{{ route('module.ekkon.webhooks.quelle.store') }}"
                      class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end border-t pt-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                        <input name="name" value="{{ old('name') }}" required
                               class="w-full rounded-md border-gray-300 text-sm" placeholder="z. B. Sally.io">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Notiz</label>
                        <input name="notiz" value="{{ old('notiz') }}"
                               class="w-full rounded-md border-gray-300 text-sm" placeholder="optional">
                    </div>
                    <div>
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Quelle anlegen
                        </button>
                    </div>
                </form>
            </div>

            {{-- ── Eingänge ─────────────────────────────────────────────── --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <h3 class="font-semibold text-gray-700 mb-1">Eingänge <span class="text-gray-400 font-normal text-sm">(letzte 100)</span></h3>
                <p class="text-sm text-gray-500 mb-4">Klick auf die Zeile zeigt Header und Body vollständig.</p>

                @if ($eingaenge->isEmpty())
                    <p class="text-sm text-gray-500 italic">Noch nichts angekommen.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500 border-b">
                                <tr>
                                    <th class="py-2 pr-4">Datum</th>
                                    <th class="py-2 pr-4">Quelle</th>
                                    <th class="py-2 pr-4">Typ</th>
                                    <th class="py-2 pr-4">Größe</th>
                                    <th class="py-2 pr-4">Anfang</th>
                                    <th class="py-2 pr-4">Verarbeitung</th>
                                    <th class="py-2 pr-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($eingaenge as $e)
                                    @php
                                        $details = [
                                            'datum' => $e->created_at?->format('d.m.Y H:i:s'),
                                            'quelle' => $e->quelle?->name,
                                            'methode' => $e->methode,
                                            'content_type' => $e->content_type,
                                            'ip' => $e->ip,
                                            'groesse' => $e->groesse,
                                            'headers' => json_encode($e->headers ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                            'body' => $e->bodyLesbar(),
                                        ];
                                    @endphp
                                    <tr x-show="! geloescht.includes({{ $e->id }})" class="border-b last:border-0 hover:bg-gray-50 cursor-pointer"
                                        @click="eingang = {{ \Illuminate\Support\Js::from($details) }}">
                                        <td class="py-2 pr-4 whitespace-nowrap text-gray-600">{{ $e->created_at?->format('d.m.Y H:i:s') }}</td>
                                        <td class="py-2 pr-4">{{ $e->quelle?->name }}</td>
                                        <td class="py-2 pr-4 text-xs text-gray-500">{{ $e->content_type }}</td>
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ number_format($e->groesse / 1024, 1, ',', '.') }} KB</td>
                                        <td class="py-2 pr-4 font-mono text-xs text-gray-500 max-w-md truncate">{{ mb_substr((string) $e->body, 0, 120) }}</td>
                                        <td class="py-2 pr-4 text-xs whitespace-nowrap">
                                            @if ($e->verarbeitet_am)
                                                <span class="text-gray-600" title="{{ $e->verarbeitet_am->format('d.m.Y H:i:s') }}">{{ $e->verarbeitung ?: 'verarbeitet' }}</span>
                                            @else
                                                <span class="text-yellow-700 bg-yellow-100 rounded px-2 py-0.5 font-semibold">wartet</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4">
                                            <div class="flex flex-wrap gap-2 whitespace-nowrap">
                                                @if ($e->verarbeitet_am)
                                                    <form method="POST" action="{{ route('module.ekkon.webhooks.eingang.erneut', $e) }}" @click.stop @submit.stop>
                                                        @csrf
                                                        <button class="text-indigo-700 hover:underline" title="Verarbeitungsmarke löschen – der Task nimmt den Eingang beim nächsten Lauf wieder mit">erneut verarbeiten</button>
                                                    </form>
                                                @endif
                                                <button type="button" class="text-red-700 hover:underline"
                                                        @click.stop="loeschen({{ $e->id }}, '{{ route('module.ekkon.webhooks.eingang.destroy', $e) }}')">löschen</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Modal: ein Eingang komplett --}}
            <div x-show="eingang !== null" x-cloak @keydown.escape.window="eingang = null"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-900/50">
                <div @click.outside="eingang = null" class="w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-lg bg-white shadow-xl">
                    <div class="flex items-start justify-between gap-4 border-b px-5 py-3">
                        <div>
                            <h4 class="font-semibold text-gray-800"><span x-text="eingang?.quelle"></span> · <span x-text="eingang?.datum"></span></h4>
                            <p class="text-xs text-gray-500">
                                <span x-text="eingang?.methode"></span> ·
                                <span x-text="eingang?.content_type || '—'"></span> ·
                                <span x-text="eingang?.groesse + ' Byte'"></span> ·
                                IP <span x-text="eingang?.ip || '—'"></span>
                            </p>
                        </div>
                        <button type="button" @click="eingang = null" class="text-gray-400 hover:text-gray-700 text-xl leading-none" aria-label="Schließen">&times;</button>
                    </div>
                    <div class="px-5 py-4 space-y-4 text-sm">
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Body</div>
                            <pre class="whitespace-pre-wrap text-xs rounded bg-gray-50 p-3 overflow-x-auto" x-text="eingang?.body"></pre>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Header</div>
                            <pre class="whitespace-pre-wrap text-xs rounded bg-gray-50 p-3 overflow-x-auto" x-text="eingang?.headers"></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

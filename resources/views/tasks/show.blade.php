<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Ekkon · {{ $task->key() }}
            </h2>
            <a href="{{ route('module.ekkon.index') }}" class="text-sm text-indigo-700 hover:underline">← alle Aufgaben</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Beschreibung</dt>
                        <dd class="font-medium text-gray-800">{{ $task->description ?: '–' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Zeitplan</dt>
                        <dd class="font-medium text-gray-800">
                            <span class="font-mono">{{ $task->schedule() }}</span>
                            @if ($state?->next_run_at)
                                <span class="block text-xs text-gray-500 mt-0.5">steuert sich selbst (setInterval)</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Nächster Lauf</dt>
                        <dd class="font-medium text-gray-800">
                            @if ($state && ! $state->enabled)
                                <span class="text-xs font-semibold text-gray-500 bg-gray-50 border border-gray-300 rounded px-2 py-0.5">pausiert</span>
                            @elseif ($state?->next_run_at?->isFuture())
                                {{ $state->next_run_at->format('d.m.Y H:i') }}
                            @else
                                {{ \Illuminate\Support\Carbon::instance($task->nextRunDate())->format('d.m.Y H:i') }}
                            @endif
                        </dd>
                    </div>
                </dl>

                <div class="mt-4 flex gap-2">
                    <form method="POST" action="{{ route('module.ekkon.task.run', explode('/', $task->key(), 2)) }}">
                        @csrf
                        <button type="submit"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white font-semibold hover:bg-indigo-500">
                            jetzt ausführen
                        </button>
                    </form>
                    <form method="POST" action="{{ route('module.ekkon.task.toggle', explode('/', $task->key(), 2)) }}">
                        @csrf
                        <button type="submit"
                                class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">
                            {{ $state && ! $state->enabled ? '▶ geplante Läufe aktivieren' : '⏸ geplante Läufe pausieren' }}
                        </button>
                    </form>
                </div>
            </div>

            @if ($highlightRun)
                <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 border-l-4
                            {{ $highlightRun->status === 'ok' ? 'border-emerald-500' : 'border-red-500' }}">
                    <h3 class="font-semibold text-gray-700 mb-2">
                        Ergebnis des manuellen Laufs
                        <span class="text-gray-400 font-normal">
                            ({{ $highlightRun->status }} · {{ \Intranet\Modules\Ekkon\Models\TaskRun::seconds($highlightRun->duration_ms) }} s)
                        </span>
                    </h3>

                    @if ($highlightRun->messages)
                        <ul class="text-sm text-gray-800 space-y-1 mb-3">
                            @foreach ($highlightRun->messages as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <details class="mb-2" open>
                        <summary class="cursor-pointer text-sm text-indigo-700 font-medium">Ergebnis (JSON)</summary>
                        <pre class="text-xs bg-gray-50 rounded p-3 mt-1 overflow-x-auto">{{ json_encode($highlightRun->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>

                    @if ($highlightRun->debug)
                        <details>
                            <summary class="cursor-pointer text-sm text-indigo-700 font-medium">Debug</summary>
                            <pre class="text-xs bg-gray-900 text-green-200 rounded p-3 mt-1 overflow-x-auto max-h-96 overflow-y-auto">{{ json_encode($highlightRun->debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    @endif
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <h3 class="font-semibold text-gray-700 p-4 sm:px-6">Lauf-Historie</h3>
                @if ($runs->hasPages())
                    <div class="px-4 sm:px-6 pb-3 border-b border-gray-100">{{ $runs->links() }}</div>
                @endif
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left text-gray-500 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-4 sm:px-6 py-2">#</th>
                                <th class="px-4 py-2">Zeit</th>
                                <th class="px-4 py-2 text-right">Dauer (s)</th>
                                <th class="px-4 py-2 w-full">Nachricht</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white">
                            @forelse ($runs as $run)
                                <tr class="{{ $run->status === 'error' ? 'bg-red-100 text-red-900' : $task->durationClasses($run->status === 'skipped' ? null : $run->duration_ms) }} align-top">
                                    <td class="px-4 sm:px-6 py-2 whitespace-nowrap font-mono text-xs">{{ $run->id }}</td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        {{ $run->started_at->format('d.m.Y H:i:s') }}
                                        @if ($run->trigger === 'manual')
                                            <span class="text-xs opacity-70">(manuell)</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-right font-mono">
                                        {{ \Intranet\Modules\Ekkon\Models\TaskRun::seconds($run->status === 'skipped' ? null : $run->duration_ms) }}
                                    </td>
                                    @php
                                        // Lange Nachrichten kleiner setzen – aber nur diese Spalte
                                        // und erst ab ~150 Zeichen.
                                        $msgText = $run->status === 'error'
                                            ? (string) ($run->output['error'] ?? '')
                                            : ($run->messages ? implode(' ', $run->messages) : (string) ($run->output['skipped'] ?? ''));
                                    @endphp
                                    <td class="px-4 py-2"@if (mb_strlen($msgText) > 150) style="font-size:.7rem;line-height:1.05rem"@endif>
                                        @if ($run->status === 'error')
                                            <span class="font-semibold">Fehler:</span> {{ $run->output['error'] ?? 'unbekannt' }}
                                        @elseif ($run->messages)
                                            {!! implode('<br>', array_map('e', $run->messages)) !!}
                                        @elseif ($run->status === 'skipped')
                                            <span class="opacity-70">{{ $run->output['skipped'] ?? 'übersprungen' }}</span>
                                        @else
                                            <span class="opacity-60">–</span>
                                        @endif

                                        @if ($run->output && $run->status !== 'error')
                                            <details class="mt-1">
                                                <summary class="cursor-pointer text-xs opacity-70 hover:opacity-100">Details{{ $run->debug ? ' + Debug' : '' }}</summary>
                                                <pre class="text-xs bg-white/70 rounded p-2 mt-1 overflow-x-auto">{{ json_encode($run->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                                @if ($run->debug)
                                                    <pre class="text-xs bg-gray-900 text-green-200 rounded p-2 mt-1 overflow-x-auto max-h-96 overflow-y-auto">{{ json_encode($run->debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                                @endif
                                            </details>
                                        @elseif ($run->debug)
                                            <details class="mt-1">
                                                <summary class="cursor-pointer text-xs opacity-70 hover:opacity-100">Debug</summary>
                                                <pre class="text-xs bg-gray-900 text-green-200 rounded p-2 mt-1 overflow-x-auto max-h-96 overflow-y-auto">{{ json_encode($run->debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </details>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 sm:px-6 py-4 text-gray-500">Noch keine Läufe.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 sm:px-6">{{ $runs->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>

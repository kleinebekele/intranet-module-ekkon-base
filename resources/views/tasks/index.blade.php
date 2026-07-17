<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Ekkon · Aufgaben</h2>
    </x-slot>

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-8">
            @unless (config('ekkon.tasks_enabled'))
                <div class="rounded-lg bg-yellow-100 text-yellow-800 px-4 py-3 text-sm font-medium">
                    ⚠ Ekkon-Tasks sind auf dieser Umgebung <b>deaktiviert</b> (EKKON_TASKS_ENABLED).
                    Weder Scheduler noch „jetzt ausführen" starten hier echte Läufe.
                </div>
            @endunless

            @if (! empty($kollisionen))
                <div class="rounded-lg bg-red-100 text-red-900 px-4 py-3 text-sm">
                    <p class="font-semibold">⚠ Doppelt vergebene Task-Keys – diese Tasks laufen NICHT:</p>
                    <ul class="mt-2 space-y-1">
                        @foreach ($kollisionen as $k)
                            <li>
                                <span class="font-mono font-semibold">{{ $k['key'] }}</span> —
                                verworfen: <span class="font-mono">{{ $k['verworfen'] }}</span>
                                (aus <span class="font-mono">{{ $k['paket'] }}</span>),
                                behalten: <span class="font-mono">{{ $k['behalten'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2">
                        Ein Task-Key darf nur einmal existieren – er ist zugleich Lauf-Historie,
                        Sperre und Pause-Schalter. Konvention: Jede Gruppe gehört genau einem Paket.
                    </p>
                </div>
            @endif
            @forelse ($categories as $category => $tasks)
                <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                    <h3 class="font-semibold text-gray-700 mb-4">
                        {{ $category }}
                        <span class="text-gray-400 font-normal">({{ count($tasks) }})</span>
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        @foreach ($tasks as $key => $task)
                            @php
                                [$group, $name] = explode('/', $key, 2);
                                $stat = $stats[$key] ?? null;
                                $last = $lastRuns[$key] ?? null;
                                $failed = $last && $last->status === 'error';
                                $state = $states->get($key);
                                $paused = $state && ! $state->enabled;
                            @endphp

                            <div class="border rounded-lg p-4 flex flex-col gap-2
                                        {{ $failed ? 'border-red-400 bg-red-50' : 'border-gray-200' }}">
                                <div class="flex items-start justify-between gap-2">
                                    <a href="{{ route('module.ekkon.task.show', [$group, $name]) }}"
                                       class="font-medium text-indigo-700 hover:underline break-all"
                                       @if ($task->description) title="{{ $task->description }}" @endif>
                                        {{ $key }}
                                    </a>
                                    @if ($failed)
                                        <span class="shrink-0 text-xs font-semibold text-red-700 bg-red-100 rounded px-2 py-0.5">Fehler</span>
                                    @endif
                                    @if ($paused)
                                        <span class="shrink-0 text-xs font-semibold text-gray-500 bg-gray-50 border border-gray-300 rounded px-2 py-0.5">pausiert</span>
                                    @endif
                                </div>

                                <dl class="text-sm text-gray-600 space-y-1">
                                    <div class="flex justify-between gap-2">
                                        <dt>letzter:</dt>
                                        <dd class="font-medium text-right">
                                            {{ $stat?->last_run ? \Illuminate\Support\Carbon::parse($stat->last_run)->format('d.m.Y H:i') : '–' }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <dt>nächster:</dt>
                                        <dd class="font-medium text-right">
                                            @if ($paused)
                                                –
                                            @elseif ($state?->next_run_at?->isFuture())
                                                {{ $state->next_run_at->format('d.m.Y H:i') }}
                                            @else
                                                {{ \Illuminate\Support\Carbon::instance($task->nextRunDate())->format('d.m.Y H:i') }}
                                            @endif
                                        </dd>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <dt>Anzahl:</dt>
                                        <dd class="font-medium">{{ $stat?->runs ?? 0 }}</dd>
                                    </div>
                                    <div class="flex justify-between items-center gap-2">
                                        <dt>⌀-Dauer:</dt>
                                        <dd>
                                            <span class="rounded px-2 py-0.5 text-xs font-semibold
                                                         {{ $task->durationClasses($stat?->avg_ms) }}">
                                                @if ($stat && $stat->avg_ms !== null)
                                                    {{ round($stat->avg_ms / 1000) }} Sek.
                                                    ({{ round($stat->min_ms / 1000) }}/{{ round($stat->max_ms / 1000) }})
                                                @else
                                                    –
                                                @endif
                                            </span>
                                        </dd>
                                    </div>
                                </dl>

                                <div class="mt-auto pt-2 flex gap-2">
                                    <form method="POST" action="{{ route('module.ekkon.task.run', [$group, $name]) }}" class="flex-1">
                                        @csrf
                                        <button type="submit"
                                                class="w-full text-sm rounded-md bg-indigo-600 px-3 py-1.5 text-white font-semibold hover:bg-indigo-500">
                                            jetzt ausführen
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('module.ekkon.task.toggle', [$group, $name]) }}">
                                        @csrf
                                        <button type="submit" title="{{ $paused ? 'geplante Läufe aktivieren' : 'geplante Läufe pausieren' }}"
                                                class="text-sm rounded-md border border-gray-300 bg-white px-3 py-1.5 font-semibold text-gray-700">
                                            {{ $paused ? '▶' : '⏸' }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-gray-500">
                    Keine Tasks vorhanden. Neue Tasks entstehen als Klasse unter
                    <code class="text-sm">src/Tasks/&lt;Gruppe&gt;/&lt;Name&gt;.php</code>.
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>

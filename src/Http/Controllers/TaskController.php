<?php

namespace Intranet\Modules\Ekkon\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Intranet\Modules\Ekkon\Models\TaskRun;
use Intranet\Modules\Ekkon\Models\TaskSetting;
use Intranet\Modules\Ekkon\Models\TaskState;
use Intranet\Modules\Ekkon\Support\TaskRegistry;
use Intranet\Modules\Ekkon\Support\TaskRunner;
use Intranet\Modules\Ekkon\Tasks\EkkonTask;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskRegistry $registry,
    ) {
    }

    /** Dashboard: alle Tasks als Kacheln, gruppiert nach Kategorie. */
    public function index(): View
    {
        $stats = TaskRun::query()
            ->select('task_key')
            ->selectRaw('count(*) as runs')
            ->selectRaw('avg(duration_ms) as avg_ms')
            ->selectRaw('min(duration_ms) as min_ms')
            ->selectRaw('max(duration_ms) as max_ms')
            ->selectRaw('max(started_at) as last_run')
            ->whereNotIn('status', ['running', 'skipped'])
            ->groupBy('task_key')
            ->get()
            ->keyBy('task_key');

        // Belegter Platz je Task: Länge der drei Datenspalten über ALLE Zeilen
        // (auch übersprungene) – Näherung der Nutzdaten, ohne Zeilen-Overhead
        // und Indizes. So sieht man, welcher Task die Historie vollschreibt.
        $laenge = fn (string $spalte): string => "coalesce(length({$spalte}), 0)";

        $speicher = TaskRun::query()
            ->select('task_key')
            ->selectRaw('sum('.$laenge('output').' + '.$laenge('messages').' + '.$laenge('debug').') as bytes')
            ->groupBy('task_key')
            ->pluck('bytes', 'task_key');

        // Letzter abgeschlossener Lauf je Task (für Fehler-Markierung).
        $lastRuns = TaskRun::query()
            ->whereIn('id', TaskRun::query()
                ->select(DB::raw('max(id)'))
                ->whereNotIn('status', ['running', 'skipped'])
                ->groupBy('task_key'))
            ->get()
            ->keyBy('task_key');

        $states = TaskState::query()->get()->keyBy('task_key');

        return view('ekkon::tasks.index', [
            'categories' => $this->pausierteAnsEnde($this->registry->byCategory(), $states),
            'stats' => $stats,
            'speicher' => $speicher,
            'lastRuns' => $lastRuns,
            'states' => $states,
            // Doppelt vergebene Task-Keys: die verworfenen Tasks laufen NICHT.
            // Das muss man sehen – sonst fehlt lautlos ein Task.
            'kollisionen' => $this->registry->kollisionen(),
        ]);
    }

    /**
     * Pausierte Tasks innerhalb ihrer Kategorie ans Ende stellen.
     *
     * Ein pausierter Task läuft nicht – zwischen den aktiven stehend sieht er
     * aber aus wie einer, der gerade nur nichts zu tun hatte. Unten gesammelt
     * (und farblich abgesetzt, s. View) ist auf einen Blick klar, was arbeitet
     * und was ruht.
     *
     * Innerhalb beider Blöcke bleibt die alphabetische Reihenfolge der Registry
     * erhalten.
     *
     * @param  array<string, array<string, \Intranet\Modules\Ekkon\Tasks\EkkonTask>>  $categories
     * @param  Collection<string, TaskState>  $states
     * @return array<string, array<string, \Intranet\Modules\Ekkon\Tasks\EkkonTask>>
     */
    private function pausierteAnsEnde(array $categories, Collection $states): array
    {
        foreach ($categories as $category => $tasks) {
            $aktiv = [];
            $pausiert = [];

            foreach ($tasks as $key => $task) {
                $state = $states->get($key);

                if ($state && ! $state->enabled) {
                    $pausiert[$key] = $task;
                } else {
                    $aktiv[$key] = $task;
                }
            }

            $categories[$category] = $aktiv + $pausiert;
        }

        return $categories;
    }

    /** Detail: Beschreibung, Zeitplan, Lauf-Historie. */
    public function show(string $group, string $name, Request $request): View
    {
        $task = $this->findOrAbort($group, $name);

        // Ein Minuten-Task erzeugt in 14 Tagen 20.000 Läufe, von denen die
        // allermeisten nichts zu sagen haben. Wer die Historie öffnet, will
        // wissen, WAS passiert ist – deshalb standardmäßig nur Läufe mit
        // Nachricht (und alles, was nicht glatt lief). Clientseitig ausblenden
        // hilft nicht: dann wäre Seite 1 von 206 einfach leer.
        $alle = $request->boolean('alle');

        $runs = TaskRun::query()
            ->where('task_key', $task->key())
            ->when(! $alle, fn ($q) => $q->where(fn ($f) => $f
                ->where(fn ($m) => $m->whereNotNull('messages')->where('messages', '<>', '[]'))
                ->orWhere('status', '<>', 'ok')))
            ->latest('id')
            ->paginate(100)
            ->withQueryString();

        $gesamt = TaskRun::query()->where('task_key', $task->key())->count();

        return view('ekkon::tasks.show', [
            'task' => $task,
            'state' => TaskState::firstWhere('task_key', $task->key()),
            'runs' => $runs,
            'alle' => $alle,
            'stille' => max(0, $gesamt - $runs->total()),
            'highlightRun' => session('ekkon_run_id')
                ? TaskRun::find(session('ekkon_run_id'))
                : null,
            // Nur die abweichenden Werte; für alles andere gilt der Standard
            // aus der Deklaration (die View fällt selbst darauf zurück).
            'einstellungen' => $this->gespeicherteEinstellungen($task),
        ]);
    }

    /** "Jetzt ausführen": Task synchron starten, Ergebnis auf der Detailseite zeigen. */
    public function run(string $group, string $name, TaskRunner $runner): RedirectResponse
    {
        $task = $this->findOrAbort($group, $name);

        $run = $runner->run($task, 'manual');

        return redirect()
            ->route('module.ekkon.task.show', ['group' => $group, 'name' => $name])
            ->with('ekkon_run_id', $run->id);
    }

    /** Pause-Schalter: geplante Läufe an/aus (manuell bleibt immer möglich). */
    public function toggle(string $group, string $name): RedirectResponse
    {
        $task = $this->findOrAbort($group, $name);

        $state = TaskState::firstOrCreate(['task_key' => $task->key()]);
        $state->update(['enabled' => ! $state->enabled]);

        return redirect()->back(fallback: route('module.ekkon.index'));
    }

    /**
     * Die im Backend gepflegten Einstellungen eines Tasks speichern.
     *
     * Gespeichert wird nur, was vom Standard abweicht: Stellt jemand den
     * Standardwert wieder her, verschwindet die Zeile. Dadurch wirkt ein
     * später im Code geänderter Standard auch für bestehende Instanzen.
     */
    public function einstellungen(Request $request, string $group, string $name): RedirectResponse
    {
        $task = $this->findOrAbort($group, $name);

        foreach ($task->einstellungen as $schluessel => $deklaration) {
            $wert = match ($deklaration['typ'] ?? 'text') {
                // Ein nicht gesetztes Häkchen schickt der Browser gar nicht mit.
                'ja_nein' => $request->boolean("einstellungen.{$schluessel}") ? '1' : '0',
                default => (string) $request->input("einstellungen.{$schluessel}", ''),
            };

            $standard = $deklaration['standard'] ?? null;
            $istStandard = match ($deklaration['typ'] ?? 'text') {
                'ja_nein' => ($wert === '1') === (bool) $standard,
                'zahl' => (int) $wert === (int) $standard,
                default => $wert === (string) $standard,
            };

            if ($istStandard) {
                TaskSetting::where('task_key', $task->key())->where('schluessel', $schluessel)->delete();

                continue;
            }

            // Query-Builder statt Eloquent-updateOrCreate: ekkon_task_settings hat
            // einen zusammengesetzten Schlüssel (task_key, schluessel) und keine
            // id-Spalte. Eloquent würde beim UPDATE auf „where id = …" ausweichen
            // und scheitern; updateOrInsert nimmt die Attribute als WHERE.
            \Illuminate\Support\Facades\DB::table('ekkon_task_settings')->updateOrInsert(
                ['task_key' => $task->key(), 'schluessel' => $schluessel],
                ['wert' => $wert, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        return redirect()
            ->route('module.ekkon.task.show', ['group' => $group, 'name' => $name])
            ->with('status', 'Einstellungen gespeichert.');
    }

    /**
     * Gespeicherte Werte in den Typ der Deklaration gewandelt, damit die Maske
     * ein Häkchen als Häkchen zeigt und nicht als "1".
     *
     * @return array<string, mixed>
     */
    private function gespeicherteEinstellungen(EkkonTask $task): array
    {
        $roh = TaskSetting::fuer($task->key());
        $werte = [];

        foreach ($task->einstellungen as $schluessel => $deklaration) {
            if (! array_key_exists($schluessel, $roh)) {
                continue;
            }

            $werte[$schluessel] = match ($deklaration['typ'] ?? 'text') {
                'ja_nein' => $roh[$schluessel] === '1',
                'zahl' => (int) $roh[$schluessel],
                default => (string) $roh[$schluessel],
            };
        }

        return $werte;
    }

    private function findOrAbort(string $group, string $name): EkkonTask
    {
        return $this->registry->find("{$group}/{$name}") ?? abort(404);
    }
}

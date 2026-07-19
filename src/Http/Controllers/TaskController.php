<?php

namespace Intranet\Modules\Ekkon\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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

        // Letzter abgeschlossener Lauf je Task (für Fehler-Markierung).
        $lastRuns = TaskRun::query()
            ->whereIn('id', TaskRun::query()
                ->select(DB::raw('max(id)'))
                ->whereNotIn('status', ['running', 'skipped'])
                ->groupBy('task_key'))
            ->get()
            ->keyBy('task_key');

        return view('ekkon::tasks.index', [
            'categories' => $this->registry->byCategory(),
            'stats' => $stats,
            'lastRuns' => $lastRuns,
            'states' => TaskState::query()->get()->keyBy('task_key'),
            // Doppelt vergebene Task-Keys: die verworfenen Tasks laufen NICHT.
            // Das muss man sehen – sonst fehlt lautlos ein Task.
            'kollisionen' => $this->registry->kollisionen(),
        ]);
    }

    /** Detail: Beschreibung, Zeitplan, Lauf-Historie. */
    public function show(string $group, string $name): View
    {
        $task = $this->findOrAbort($group, $name);

        return view('ekkon::tasks.show', [
            'task' => $task,
            'state' => TaskState::firstWhere('task_key', $task->key()),
            'runs' => TaskRun::query()
                ->where('task_key', $task->key())
                ->latest('id')
                ->paginate(100),
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

            TaskSetting::updateOrCreate(
                ['task_key' => $task->key(), 'schluessel' => $schluessel],
                ['wert' => $wert],
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

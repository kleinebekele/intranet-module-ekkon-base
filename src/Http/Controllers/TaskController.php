<?php

namespace Intranet\Modules\Ekkon\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Intranet\Modules\Ekkon\Models\TaskRun;
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

    private function findOrAbort(string $group, string $name): EkkonTask
    {
        return $this->registry->find("{$group}/{$name}") ?? abort(404);
    }
}

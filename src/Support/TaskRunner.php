<?php

namespace Intranet\Modules\Ekkon\Support;

use Illuminate\Support\Facades\Cache;
use Intranet\Modules\Ekkon\Models\TaskRun;
use Intranet\Modules\Ekkon\Models\TaskState;
use Intranet\Modules\Ekkon\Tasks\EkkonTask;
use Throwable;

/**
 * Führt einen Task aus: Überlappungsschutz per Cache-Lock, Lauf-Historie
 * mit Dauer/Status/Nachrichten/Debug/JSON-Ergebnis in ekkon_task_runs.
 *
 * Selbststeuerung: Hat der Task per setInterval() einen nächsten Zeitpunkt
 * bestimmt (ekkon_task_states), werden geplante Läufe davor lautlos
 * übersprungen – run() liefert dann null und es entsteht KEIN Eintrag.
 */
class TaskRunner
{
    /** Debug-Daten nur für die jüngsten N Läufe je Task behalten. */
    private const KEEP_DEBUG_RUNS = 10;

    public function run(EkkonTask $task, string $trigger = 'scheduled'): ?TaskRun
    {
        // Sicherheitsschalter: Auf Umgebungen ohne EKKON_TASKS_ENABLED=true
        // (z. B. lokale Entwicklung) läuft NIE ein Task – auch nicht manuell.
        if (! config('ekkon.tasks_enabled')) {
            return TaskRun::create([
                'task_key' => $task->key(),
                'trigger' => $trigger,
                'status' => 'skipped',
                'started_at' => now(),
                'finished_at' => now(),
                'duration_ms' => 0,
                'output' => ['skipped' => 'Ekkon-Tasks sind auf dieser Umgebung deaktiviert (EKKON_TASKS_ENABLED).'],
            ]);
        }

        // Pausiert (Dashboard) oder schlummernd (setInterval)? Geplante Läufe
        // werden lautlos übersprungen; nur manuelle Läufe dürfen durch.
        $state = TaskState::firstWhere('task_key', $task->key());

        if ($trigger === 'scheduled' && $state !== null && ! $state->enabled) {
            return null;
        }

        if ($trigger === 'scheduled' && $state?->next_run_at?->isFuture()) {
            return null;
        }

        $lock = Cache::lock('ekkon-task-'.$task->key(), $task->lockSeconds());

        if (! $lock->get()) {
            return TaskRun::create([
                'task_key' => $task->key(),
                'trigger' => $trigger,
                'status' => 'skipped',
                'started_at' => now(),
                'finished_at' => now(),
                'duration_ms' => 0,
                'output' => ['skipped' => 'Task läuft bereits (Überlappungsschutz).'],
            ]);
        }

        $run = TaskRun::create([
            'task_key' => $task->key(),
            'trigger' => $trigger,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $task->resetChannels();
        $start = hrtime(true);

        try {
            $output = $task->run();
            $status = 'ok';
        } catch (Throwable $e) {
            $status = 'error';
            $output = [
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'at' => $e->getFile().':'.$e->getLine(),
            ];
            report($e);
        } finally {
            $lock->release();
        }

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => intdiv(hrtime(true) - $start, 1_000_000),
            'output' => $output,
            'messages' => $task->messages() ?: null,
            'debug' => $task->debugData() ?: null,
        ]);

        // Vom Task bestimmten nächsten Lauf merken (auch nach manuellen Läufen).
        if ($task->interval() !== null) {
            TaskState::updateOrCreate(
                ['task_key' => $task->key()],
                ['next_run_at' => $task->interval()],
            );
        }

        $this->pruneDebug($task->key());

        return $run;
    }

    /** Debug-Spalte älterer Läufe leeren – die Zeilen selbst bleiben erhalten. */
    private function pruneDebug(string $taskKey): void
    {
        $keepIds = TaskRun::query()
            ->where('task_key', $taskKey)
            ->whereNotNull('debug')
            ->latest('id')
            ->limit(self::KEEP_DEBUG_RUNS)
            ->pluck('id');

        TaskRun::query()
            ->where('task_key', $taskKey)
            ->whereNotNull('debug')
            ->whereNotIn('id', $keepIds)
            ->update(['debug' => null]);
    }
}

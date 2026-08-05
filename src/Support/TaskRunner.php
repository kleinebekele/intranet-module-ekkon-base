<?php

namespace Intranet\Modules\Ekkon\Support;

use Illuminate\Support\Facades\Cache;
use Intranet\Modules\Ekkon\Models\TaskRun;
use Intranet\Modules\Ekkon\Services\Benachrichtiger;
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

        $dauerMs = intdiv(hrtime(true) - $start, 1_000_000);

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => $dauerMs,
            'output' => $output,
            'messages' => $task->messages() ?: null,
            'debug' => $task->debugData() ?: null,
        ]);

        $this->laufzeitPruefen($task, $dauerMs, $status);

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

    /**
     * Hat der Lauf ungewöhnlich lange gedauert? Dann sagt das System Bescheid.
     *
     * ── Warum das nötig wurde (2026-08-05) ──────────────────────────────
     * Aftersales/JourneyUpdate lief über Stunden. Das Dashboard hat die Dauer
     * brav rot eingefärbt – gemerkt hat es trotzdem niemand, weil dort nur
     * hinschaut, wer ohnehin schon einen Verdacht hat. Aufgefallen ist es erst,
     * als die produktive Wawi lahm wurde und der SQL-Dienst neu gestartet
     * werden musste. Eine Überwachung, die nicht von selbst meldet, ist keine.
     *
     * ⚠️ Diese Prüfung darf den Lauf niemals kippen. Der Task ist an dieser
     * Stelle fertig, sein Ergebnis steht schon in der Datenbank – eine
     * scheiternde Benachrichtigung würde daraus nachträglich einen Fehlschlag
     * machen und im schlimmsten Fall den nächsten Lauf gleich mit verhindern.
     */
    private function laufzeitPruefen(EkkonTask $task, int $dauerMs, string $status): void
    {
        $grenzeSekunden = $task->warnungAbSekunden;

        if ($grenzeSekunden <= 0 || $dauerMs < $grenzeSekunden * 1000) {
            return;
        }

        try {
            (new Benachrichtiger())->benachrichtige(
                TaskRegistry::MELDUNG_LAUFZEIT,
                'Task lief ungewöhnlich lange: '.$task->key(),
                'Der Lauf hat '.round($dauerMs / 60000, 1).' Minuten gebraucht (Warnschwelle: '
                    .round($grenzeSekunden / 60, 1)." Minuten, Status: {$status}).\n\n"
                    ."Bitte nachsehen, bevor daraus ein Dauerzustand wird: Ein Lauf, der länger "
                    ."braucht als sein Abstand zum nächsten, staut sich auf – und wenn er die "
                    ."Überlappungssperre überdauert, laufen mehrere Läufe gleichzeitig auf "
                    ."derselben Datenbank.\n\n"
                    .'Die Zeitmessung je Abschnitt steht in den Lauf-Details unter "tempo".',
                [
                    'task' => $task->key(),
                    'dauer_ms' => $dauerMs,
                    'grenze_s' => $grenzeSekunden,
                    'status' => $status,
                ],
                // Einmal je Task und Stunde: Ein 10-Minuten-Task würde sonst
                // dieselbe Meldung sechsmal pro Stunde absetzen. Stündlich
                // bleibt sichtbar, dass es weitergeht, ohne zu fluten.
                'task-laufzeit:'.$task->key().':'.now()->format('Y-m-d-H'),
                $task->key(),
            );
        } catch (Throwable $e) {
            report($e);
        }
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

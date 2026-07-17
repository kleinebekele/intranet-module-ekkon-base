<?php

namespace Intranet\Modules\Ekkon\Console;

use Illuminate\Console\Command;
use Intranet\Modules\Ekkon\Support\TaskRegistry;
use Intranet\Modules\Ekkon\Support\TaskRunner;

class RunTaskCommand extends Command
{
    protected $signature = 'ekkon:task {key? : Task-Key, z. B. Demo/Ping} {--trigger=scheduled}';

    protected $description = 'Führt einen Ekkon-Task aus (ohne Key: alle Tasks auflisten)';

    public function handle(TaskRegistry $registry, TaskRunner $runner): int
    {
        $key = $this->argument('key');

        if ($key === null) {
            $this->table(
                ['Key', 'Kategorie', 'Cron', 'Beschreibung'],
                collect($registry->all())->map(fn ($t) => [
                    $t->key(), $t->category, $t->schedule(), $t->description,
                ]),
            );

            // Doppelte Keys: hier – im Deploy – soll es auffallen, nicht später
            // dadurch, dass ein Task unbemerkt nie wieder läuft.
            $kollisionen = $registry->kollisionen();

            if ($kollisionen !== []) {
                $this->newLine();
                $this->error('Doppelt vergebene Task-Keys – diese Tasks laufen NICHT:');
                foreach ($kollisionen as $k) {
                    $this->warn("  {$k['key']}: verworfen {$k['verworfen']} (aus {$k['paket']}), behalten {$k['behalten']}");
                }

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $task = $registry->find($key);

        if ($task === null) {
            $this->error("Unbekannter Task: {$key} (Liste: php artisan ekkon:task)");

            return self::FAILURE;
        }

        $run = $runner->run($task, (string) $this->option('trigger'));

        if ($run === null) {
            // Task schlummert noch (setInterval) – lautlos, kein Historie-Eintrag.
            return self::SUCCESS;
        }

        $this->line(json_encode($run->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach ($run->messages ?? [] as $message) {
            $this->line('· '.$message);
        }

        $this->info("Status: {$run->status} · Dauer: {$run->duration_ms} ms");

        return $run->status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}

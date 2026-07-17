<?php

namespace Intranet\Modules\Ekkon\Tasks\Demo;

use Intranet\Modules\Ekkon\Tasks\EkkonTask;

/**
 * Beispiel-Task: prüft, dass das Framework funktioniert (Scheduler,
 * Lauf-Historie, Überlappungsschutz, JSON-Ergebnis im Dashboard).
 * Kann gelöscht werden, sobald echte Tasks existieren.
 */
class Ping extends EkkonTask
{
    public string $category = 'Demo';

    public string $description = 'Framework-Selbsttest – antwortet mit pong und ein paar Kennzahlen.';

    public function schedule(): string
    {
        return '*/5 * * * *';
    }

    public function run(): array
    {
        usleep(300_000); // simuliert etwas Arbeit (~0,3 s)

        return [
            'pong' => true,
            'php' => PHP_VERSION,
            'memory_mb' => round(memory_get_usage(true) / 1_048_576, 1),
            'time' => now()->toDateTimeString(),
        ];
    }
}

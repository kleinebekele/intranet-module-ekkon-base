<?php

namespace Intranet\Modules\Ekkon\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Zustand eines Tasks:
 * - next_run_at: Selbststeuerung per setInterval() — Scheduler-Ticks davor
 *   werden lautlos übersprungen (kein Historie-Eintrag).
 * - enabled: Pause-Schalter aus dem Dashboard — false stoppt geplante Läufe
 *   (lautlos), manuelles Ausführen bleibt möglich. Keine Zeile = aktiv.
 */
class TaskState extends Model
{
    protected $table = 'ekkon_task_states';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime',
            'enabled' => 'boolean',
        ];
    }
}

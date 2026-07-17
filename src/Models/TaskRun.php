<?php

namespace Intranet\Modules\Ekkon\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein einzelner Lauf eines Ekkon-Tasks (Historie + Statistikbasis).
 */
class TaskRun extends Model
{
    protected $table = 'ekkon_task_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'output' => 'array',
            'messages' => 'array',
            'debug' => 'array',
        ];
    }

    /** Laufdauer in Sekunden, deutsch formatiert ("33,04"). */
    public static function seconds(?float $ms): string
    {
        return $ms === null ? '–' : number_format($ms / 1000, 2, ',', '.');
    }
}

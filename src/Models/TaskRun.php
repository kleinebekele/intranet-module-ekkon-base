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

    /** Belegter Platz deutsch formatiert ("1,2 MB"). */
    public static function bytes(?float $bytes): string
    {
        $b = (float) $bytes;

        if ($b < 1024) {
            return number_format($b, 0, ',', '.').' B';
        }

        if ($b < 1024 ** 2) {
            return number_format($b / 1024, 0, ',', '.').' KB';
        }

        if ($b < 1024 ** 3) {
            return number_format($b / 1024 ** 2, 1, ',', '.').' MB';
        }

        return number_format($b / 1024 ** 3, 1, ',', '.').' GB';
    }
}

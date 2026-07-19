<?php

namespace Intranet\Modules\Ekkon\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein im Backend eingestellter Wert eines Tasks.
 *
 * Gelesen wird üblicherweise nicht hierüber, sondern über
 * {@see \Intranet\Modules\Ekkon\Tasks\EkkonTask::einstellung()} – das kennt die
 * Deklaration des Tasks und liefert den richtigen Typ samt Standardwert.
 */
class TaskSetting extends Model
{
    protected $table = 'ekkon_task_settings';

    public $incrementing = false;

    protected $fillable = ['task_key', 'schluessel', 'wert'];

    /**
     * Alle gespeicherten Werte eines Tasks als schluessel => wert.
     *
     * @return array<string, string|null>
     */
    public static function fuer(string $taskKey): array
    {
        return static::where('task_key', $taskKey)
            ->pluck('wert', 'schluessel')
            ->all();
    }
}

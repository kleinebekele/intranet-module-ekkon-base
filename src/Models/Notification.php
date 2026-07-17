<?php

namespace Intranet\Modules\Ekkon\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine Zeile der Benachrichtigungs-Warteschlange – bereits auf EIN Ziel
 * aufgelöst. Der Task Notifications/SendNotifications arbeitet sie ab.
 *
 * Bewusst eigene Tabelle statt Laravel-Queue: Die bräuchte einen dauerhaft
 * laufenden queue:work-Daemon – hier genügt der Minuten-Cron, der ohnehin für
 * schedule:run läuft. Nebeneffekt: Die Warteschlange ist im Dashboard sichtbar
 * und wiederholbar.
 *
 * Status:
 *  - pending    – wartet auf Zustellung
 *  - sent       – raus (wird nach 14 Tagen gepruned, wie ekkon_task_runs)
 *  - failed     – 3 Versuche erfolglos, bleibt liegen (kein ewiges Probieren)
 *  - ohne_ziel  – es gab KEINE aktive Route für die Meldungsart. Die Meldung
 *                 verschwindet trotzdem nicht, sondern bleibt sichtbar. Sonst
 *                 vergisst man die Route und merkt monatelang nicht, dass
 *                 niemand informiert wird.
 */
class Notification extends Model
{
    protected $table = 'ekkon_notifications';

    protected $guarded = [];

    /** Maximale Zustellversuche, danach 'failed'. */
    public const MAX_VERSUCHE = 3;

    protected function casts(): array
    {
        return [
            'daten' => 'array',
            'gesendet_am' => 'datetime',
        ];
    }
}

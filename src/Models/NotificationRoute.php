<?php

namespace Intranet\Modules\Ekkon\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Route = EIN Ziel für EINE Meldungsart.
 *
 * Zwei Zeilen für dieselbe Meldungsart = Teams UND Mail. Stumpf, aber man sieht
 * auf einen Blick, was wohin geht. Die Alternative – Routing, Formatierung und
 * Versand in einer Datei – lässt jede neue Meldungsart zu einem weiteren `if`
 * werden, bis niemand mehr sagen kann, wer eigentlich was bekommt.
 *
 * Die Meldungsarten selbst deklarieren die Tasks im Code
 * (EkkonTask::$meldungsarten) – die Maske bietet ein Dropdown daraus an. Grund:
 * Eine frei eingetippte Meldungsart mit Tippfehler würde die Meldung LAUTLOS
 * ins Nichts routen.
 */
class NotificationRoute extends Model
{
    protected $table = 'ekkon_notification_routes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'aktiv' => 'boolean',
            'mail_an_admins' => 'boolean',
        ];
    }

    /** @return BelongsTo<TeamsChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(TeamsChannel::class, 'teams_channel_id');
    }

    /** Der einzelne Ziel-Benutzer (Mail an einen bestimmten Administrator). */
    public function mailUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'mail_user_id');
    }

    /** Kurzbeschreibung des Ziels für die Übersicht. */
    public function zielText(): string
    {
        if ($this->typ === 'teams') {
            return $this->channel?->name ?? '⚠ Channel gelöscht';
        }

        if ($this->mail_an_admins) {
            return 'System-Admins';
        }

        if ($this->mail_user_id) {
            $user = $this->mailUser;

            return $user ? $user->name.' ('.$user->email.')' : '⚠ Benutzer gelöscht';
        }

        return (string) $this->mail_empfaenger;
    }
}

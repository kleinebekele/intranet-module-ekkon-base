<?php

namespace Intranet\Modules\Ekkon\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Teams-Channel, in den benachrichtigt werden kann.
 *
 * ⚠️ Die webhook_url ist ein PASSWORT: Die Workflow-URL trägt den Token im
 * Query-String. Darum Cast 'encrypted' (kein Klartext in DB-Dumps/Backups) und
 * niemals ins Repo. Preis des Casts: Bei APP_KEY-Verlust sind die URLs futsch –
 * bei einer Handvoll Channels verschmerzbar, dann neu eintragen.
 *
 * Hintergrund: Der klassische Office-365-Connector ("Incoming Webhook",
 * outlook.office.com + MessageCard) ist von Microsoft abgekündigt und Ende 2025
 * gestorben. Ersatz sind Teams-Workflows (Power Automate), URL auf
 * …logic.azure.com…
 */
class TeamsChannel extends Model
{
    protected $table = 'ekkon_teams_channels';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'webhook_url' => 'encrypted',
            'aktiv' => 'boolean',
        ];
    }

    /** @return HasMany<NotificationRoute, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(NotificationRoute::class, 'teams_channel_id');
    }
}

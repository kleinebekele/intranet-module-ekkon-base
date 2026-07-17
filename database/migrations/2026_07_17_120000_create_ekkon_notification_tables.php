<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Benachrichtigungs-System.
 *
 * Die Tabellen liegen in der Intranet-Datenbank, nicht in einer angebundenen
 * Fremdquelle: Framework-Kram gehört zum Modul (wie ekkon_task_runs/
 * ekkon_task_states) ⇒ normale Laravel-Migration.
 *
 * Der Kern-Trick: ROUTING passiert beim ANLEGEN, ZUSTELLUNG ist dumm.
 *   - ekkon_notification_routes = wer bekommt welche Meldungsart
 *   - ekkon_notifications       = fertige Zeilen, ein Ziel pro Zeile
 *   - SendNotifications kennt weder Meldungsarten noch Empfänger
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Teams-Channels. URLs bewusst NICHT in die .env (viele Channels,
        // jede Änderung wäre ein Deployment) ⇒ DB + CRUD-Maske.
        Schema::create('ekkon_teams_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // Cast 'encrypted' im Model: Die Workflow-URL trägt den Token im
            // Query-String, ist also ein Passwort und hat in DB-Dumps/Backups
            // nichts im Klartext verloren. Preis: APP_KEY-Verlust = URLs futsch
            // (bei ~10 Stück verschmerzbar, dann neu eintragen).
            $table->text('webhook_url');
            $table->boolean('aktiv')->default(true);
            $table->string('notiz')->nullable();
            $table->timestamps();
        });

        // 2) Routing-Tabelle: EINE Zeile = EIN Ziel für EINE Meldungsart.
        // Zwei Zeilen = Teams + Mail. Stumpf, aber man sieht auf einen Blick,
        // was wohin geht.
        Schema::create('ekkon_notification_routes', function (Blueprint $table): void {
            $table->id();
            $table->string('meldungsart')->index();
            $table->string('typ', 20);                  // 'mail' | 'teams'
            $table->foreignId('teams_channel_id')->nullable()
                ->constrained('ekkon_teams_channels')
                ->nullOnDelete();
            $table->string('mail_empfaenger')->nullable();
            $table->boolean('aktiv')->default(true);
            $table->timestamps();
        });

        // 3) Warteschlange als Tabelle + Task, NICHT Laravel-Queue: die bräuchte
        // einen dauerhaft laufenden queue:work-Daemon, hier genügt der
        // Minuten-Cron mit schedule:run. Nebeneffekt: im Dashboard sichtbar
        // und wiederholbar.
        Schema::create('ekkon_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('typ', 20);                  // 'mail' | 'teams'
            // Aufgelöstes Ziel: Mail-Adresse bzw. ekkon_teams_channels.id.
            // Absichtlich NICHT die Webhook-URL – die bliebe hier im Klartext
            // liegen; SendNotifications schlägt sie am Channel nach.
            $table->string('ziel')->nullable();
            $table->string('titel');
            $table->text('text');
            $table->json('daten')->nullable();
            $table->string('quelle', 60)->nullable();   // task_key
            // 'ohne_ziel': Meldung ohne passende Route verschwindet NICHT,
            // sondern wird sichtbar abgelegt. Sonst vergisst man die Route und
            // merkt monatelang nicht, dass niemand informiert wird.
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('versuche')->default(0);
            $table->text('letzter_fehler')->nullable();
            $table->dateTime('gesendet_am')->nullable();
            // Idempotenz von Anfang an: ein 15-Minuten-Task darf dieselbe
            // Meldung nicht 96x/Tag posten. Das UNIQUE erledigt das zentral,
            // nicht jeder Task für sich. (MySQL erlaubt beliebig viele NULLs.)
            $table->string('idempotenz_schluessel', 120)->nullable()->unique();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ekkon_notifications');
        Schema::dropIfExists('ekkon_notification_routes');
        Schema::dropIfExists('ekkon_teams_channels');
    }
};

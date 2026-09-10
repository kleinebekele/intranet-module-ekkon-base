<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook-Eingang: Fremde Dienste (z. B. Sally.io) schicken uns per POST etwas
 * zu. Wir speichern erst einmal ALLES roh – Header, Body, Zeitpunkt – und
 * schauen dann in Ruhe, was drinsteckt. Erst danach wird daraus Fachlogik.
 *
 *  - ekkon_webhook_quellen   = eine Zeile je Absender, mit geheimem Schlüssel in
 *                              der URL (/webhooks/ekkon/{schluessel})
 *  - ekkon_webhook_eingaenge = eine Zeile je empfangenem Aufruf
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ekkon_webhook_quellen')) {
            Schema::create('ekkon_webhook_quellen', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                // Der Schlüssel ist das einzige „Passwort" der URL – 32 Byte zufällig, hex.
                $table->string('schluessel', 64)->unique();
                $table->string('notiz')->nullable();
                $table->boolean('aktiv')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ekkon_webhook_eingaenge')) {
            Schema::create('ekkon_webhook_eingaenge', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quelle_id')->constrained('ekkon_webhook_quellen')->cascadeOnDelete();
                $table->string('methode', 10);
                $table->string('content_type')->nullable();
                // Gekürzt (letztes Oktett weg) – reicht zum Erkennen des Absenders, DSGVO-konform.
                $table->string('ip', 45)->nullable();
                $table->json('headers')->nullable();
                $table->longText('body')->nullable();
                $table->unsignedInteger('groesse')->default(0);
                $table->timestamp('created_at')->useCurrent();

                $table->index(['quelle_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ekkon_webhook_eingaenge');
        Schema::dropIfExists('ekkon_webhook_quellen');
    }
};

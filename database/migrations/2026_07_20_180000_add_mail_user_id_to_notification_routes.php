<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail-Routen können jetzt auch an EINEN bestimmten Benutzer gehen (per Auswahl
 * aus den Administratoren), nicht nur an eine feste Adresse oder „alle Admins".
 *
 * Gespeichert wird die `user_id`, nicht die Adresse: So folgt die Route einem
 * späteren Adresswechsel des Benutzers automatisch (aufgelöst beim Anlegen der
 * Benachrichtigung). Wird der Benutzer gelöscht, fällt die Route auf null –
 * und der Benachrichtiger überspringt sie sichtbar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ekkon_notification_routes', function (Blueprint $table): void {
            $table->foreignId('mail_user_id')->nullable()->after('mail_an_admins')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ekkon_notification_routes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mail_user_id');
        });
    }
};

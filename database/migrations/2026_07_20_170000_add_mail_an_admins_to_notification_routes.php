<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail-Routen können jetzt „an die System-Admins" gehen statt an eine feste
 * Adresse.
 *
 * `mail_an_admins = true` → beim Anlegen der Benachrichtigung werden die
 * E-Mail-Adressen aller Administratoren aufgelöst (eine Zeile je Admin);
 * `mail_empfaenger` bleibt dann leer. So muss niemand eine Adresse pflegen, und
 * ein neuer Admin ist automatisch dabei.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ekkon_notification_routes', function (Blueprint $table): void {
            $table->boolean('mail_an_admins')->default(false)->after('mail_empfaenger');
        });
    }

    public function down(): void
    {
        Schema::table('ekkon_notification_routes', function (Blueprint $table): void {
            $table->dropColumn('mail_an_admins');
        });
    }
};

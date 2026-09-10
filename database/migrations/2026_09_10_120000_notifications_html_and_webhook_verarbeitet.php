<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 *  - ekkon_notifications.html: optionale HTML-Fassung einer Meldung. Mail
 *    bekommt HTML + Text (je nach Empfänger), Teams eine Markdown-Fassung.
 *  - ekkon_webhook_eingaenge.verarbeitet_am: ein Task hat den Eingang gelesen
 *    und daraus etwas gemacht (z. B. Sally-Zusammenfassung → Benachrichtigung).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ekkon_notifications', 'html')) {
            Schema::table('ekkon_notifications', function (Blueprint $table): void {
                $table->longText('html')->nullable()->after('text');
            });
        }

        if (! Schema::hasColumn('ekkon_webhook_eingaenge', 'verarbeitet_am')) {
            Schema::table('ekkon_webhook_eingaenge', function (Blueprint $table): void {
                $table->timestamp('verarbeitet_am')->nullable()->after('groesse');
                $table->string('verarbeitung', 255)->nullable()->after('verarbeitet_am');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ekkon_notifications', 'html')) {
            Schema::table('ekkon_notifications', fn (Blueprint $t) => $t->dropColumn('html'));
        }
        if (Schema::hasColumn('ekkon_webhook_eingaenge', 'verarbeitet_am')) {
            Schema::table('ekkon_webhook_eingaenge', fn (Blueprint $t) => $t->dropColumn(['verarbeitet_am', 'verarbeitung']));
        }
    }
};

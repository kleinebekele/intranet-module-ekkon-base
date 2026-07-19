<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Meldungsart wandert mit in die Warteschlangen-Zeile.
 *
 * Damit weiß der Versand (SendNotifications), welche Mailvorlage er rendern
 * muss – je Meldungsart gibt es eine eigene, im Backend bearbeitbare Vorlage
 * (`ekkon:<meldungsart>`). Vorher war die Mail schlichter Text; die Meldungsart
 * war beim Anlegen bekannt, aber nicht gespeichert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ekkon_notifications', function (Blueprint $table): void {
            $table->string('meldungsart')->nullable()->after('quelle');
        });
    }

    public function down(): void
    {
        Schema::table('ekkon_notifications', function (Blueprint $table): void {
            $table->dropColumn('meldungsart');
        });
    }
};

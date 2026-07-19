<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einstellungen je Task – gepflegt im Backend, nicht in der `.env`.
 *
 * Die `.env` beschreibt, WO eine Instanz läuft (Datenbank, Mailserver, Pfade).
 * Was jemand fachlich entscheidet, gehört dorthin, wo er es sieht und ändern
 * kann, ohne auf einen Server zu müssen – zumal ein Wert in der `.env` nach
 * `config:cache` erst nach einem weiteren Befehl wirkt.
 *
 * Gespeichert wird nur, was vom Standard abweicht: Ein Task, an dem niemand
 * etwas eingestellt hat, hat hier keine Zeile. Dadurch wirken geänderte
 * Standardwerte im Code sofort für alle, die sich nie darum gekümmert haben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ekkon_task_settings', function (Blueprint $table) {
            $table->string('task_key');      // z. B. "Linear/BenutzerImport"
            $table->string('schluessel');    // z. B. "probelauf"

            // Als Text abgelegt und beim Lesen anhand der Deklaration des Tasks
            // in den richtigen Typ gewandelt – die Deklaration ist die Wahrheit.
            $table->text('wert')->nullable();

            $table->timestamps();

            $table->primary(['task_key', 'schluessel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ekkon_task_settings');
    }
};

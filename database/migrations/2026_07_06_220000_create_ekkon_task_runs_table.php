<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ekkon_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('task_key')->index();          // z. B. "Demo/Ping"
            $table->string('trigger', 20)->default('scheduled'); // scheduled | manual
            $table->string('status', 20)->default('running');    // running | ok | error | skipped
            $table->dateTime('started_at')->index();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('output')->nullable();           // Ergebnis bzw. Fehlerdetails
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ekkon_task_runs');
    }
};

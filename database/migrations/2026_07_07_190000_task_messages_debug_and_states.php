<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nachrichten (menschenlesbare Historie) und Debug-Daten je Lauf.
        // Debug wird nur für die letzten 10 Läufe je Task behalten (TaskRunner).
        Schema::table('ekkon_task_runs', function (Blueprint $table): void {
            $table->json('messages')->nullable()->after('output');
            $table->json('debug')->nullable()->after('messages');
        });

        // Selbststeuerung: Tasks können per setInterval() ihren nächsten Lauf
        // bestimmen; der Scheduler-Herzschlag schlummert bis dahin lautlos.
        Schema::create('ekkon_task_states', function (Blueprint $table): void {
            $table->id();
            $table->string('task_key')->unique();
            $table->dateTime('next_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('ekkon_task_runs', function (Blueprint $table): void {
            $table->dropColumn(['messages', 'debug']);
        });

        Schema::dropIfExists('ekkon_task_states');
    }
};

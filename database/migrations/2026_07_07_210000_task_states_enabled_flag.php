<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pause-Schalter je Task (Dashboard): enabled=false → Scheduler
        // überspringt lautlos, manuelles Ausführen bleibt möglich.
        Schema::table('ekkon_task_states', function (Blueprint $table): void {
            $table->boolean('enabled')->default(true)->after('task_key');
        });
    }

    public function down(): void
    {
        Schema::table('ekkon_task_states', function (Blueprint $table): void {
            $table->dropColumn('enabled');
        });
    }
};

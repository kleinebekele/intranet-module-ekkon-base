<?php

namespace Intranet\Modules\Ekkon;

use App\Modules\Support\ModuleManifest;
use App\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Intranet\Modules\Ekkon\Console\RunTaskCommand;
use Intranet\Modules\Ekkon\Models\TaskRun;
use Intranet\Modules\Ekkon\Support\TaskRegistry;

/**
 * Basis-Paket: Task-System + MSSQL-Verbindung + Benachrichtigungen.
 *
 * Enthält bewusst KEINE Fachlogik. Tasks steuern Submodule bei, indem ihr
 * Provider sein Tasks-Verzeichnis per TaskRegistry::addSource() anmeldet.
 */
class EkkonServiceProvider extends ModuleServiceProvider
{
    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('ekkon', 'Ekkon', icon: 'cog')
            ->item('index', 'Aufgaben', 'module.ekkon.index')
            // Betriebswerkzeug wie die Aufgaben: Die Route trägt hart
            // EnsureUserIsAdmin (Webhook-URLs sind Passwörter). Neuer Menüpunkt
            // startet ohne Rollen = nur Admin – passt.
            ->item('notifications', 'Benachrichtigungen', 'module.ekkon.notifications.index');
    }

    public function register(): void
    {
        parent::register();

        // singletonIf, nicht singleton: Ein Submodul darf im register() bereits
        // seine Tasks anmelden. Bände die Basis hier hart, würde sie die schon
        // befüllte Registry ersetzen – und die Tasks des Submoduls wären LAUTLOS
        // weg (kein Fehler, sie würden nur nie eingeplant). Wer zuerst kommt,
        // bindet; alle teilen sich dieselbe Instanz.
        $this->app->singletonIf(TaskRegistry::class);

        $this->app->make(TaskRegistry::class)->addSource(
            $this->moduleBasePath().'/src/Tasks',
            __NAMESPACE__.'\\Tasks',
            'do1emu/module-ekkon',
        );

        $this->mergeConfigFrom($this->moduleBasePath().'/config/ekkon.php', 'ekkon');

        // Die MSSQL-Quelle als Laravel-Connection. Der Name kommt aus der Config,
        // damit er zur Datenquelle passen darf (z. B. "wawi").
        config(['database.connections.'.Ekkon::mssqlConnection() => config('ekkon.mssql')]);
    }

    public function boot(): void
    {
        parent::boot();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([RunTaskCommand::class]);

        // Jeden aktiven Task beim Laravel-Scheduler anmelden. Der Server braucht
        // dafür nur EINEN Cron-Eintrag: * * * * * php artisan schedule:run
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Sicherheitsschalter: ohne EKKON_TASKS_ENABLED=true wird gar nichts
            // eingeplant (zweite Sperre sitzt im TaskRunner).
            if (! config('ekkon.tasks_enabled')) {
                return;
            }

            foreach ($this->app->make(TaskRegistry::class)->all() as $task) {
                $schedule->command('ekkon:task', [$task->key()])
                    ->cron($task->schedule())
                    ->withoutOverlapping($task->lockSeconds() / 60)
                    ->runInBackground();
            }

            // Lauf-Historie begrenzen (Statistik bleibt aussagekräftig, DB schlank).
            $schedule->call(function (): void {
                TaskRun::query()->where('started_at', '<', now()->subDays(14))->delete();
            })->dailyAt('04:15');
        });
    }
}

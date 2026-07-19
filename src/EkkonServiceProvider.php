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

        // Für JEDE Meldungsart eine bearbeitbare Mailvorlage im Core-Register
        // anmelden (`ekkon:<meldungsart>`). Läuft in Web UND Konsole: im Web für
        // die Bearbeitung unter Verwaltung → Mailvorlagen, in der Konsole für den
        // Versand durch SendNotifications.
        $this->benachrichtigungsVorlagenAnmelden();

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

    /**
     * Je deklarierter Meldungsart eine Mailvorlage im Core-Register anmelden.
     *
     * Erst wenn alle Provider geladen sind (`booted`), stehen alle Tasks und
     * damit alle Meldungsarten fest. Ohne das Core-Mailvorlagen-System (älterer
     * Core) passiert nichts – die Ekkon-Mails fallen dann auf reinen Text
     * zurück (siehe SendNotifications).
     */
    private function benachrichtigungsVorlagenAnmelden(): void
    {
        if (! class_exists(\App\Mail\Vorlagen\VorlagenRegister::class)) {
            return;
        }

        $this->app->booted(function (): void {
            $register = $this->app->make(\App\Mail\Vorlagen\VorlagenRegister::class);
            $arten = $this->app->make(TaskRegistry::class)->meldungsarten();

            foreach ($arten as $art => $klartext) {
                $register->registrieren(new \App\Mail\Vorlagen\VorlagenDefinition(
                    schluessel: 'ekkon:'.$art,
                    titel: 'Benachrichtigung: '.$klartext,
                    beschreibung: 'Mail für die Ekkon-Meldungsart „'.$klartext.'". Wird verschickt, '
                        .'wenn dafür eine Mail-Route existiert. Der Text kommt vom auslösenden Task '
                        .'(Platzhalter {{ text }}).',
                    platzhalter: [
                        'titel' => 'Überschrift/Betreff der Meldung',
                        'text' => 'Der Meldungstext vom Task (kann einen Link enthalten)',
                        'quelle' => 'Auslösender Task',
                    ],
                    betreff: '{{ titel }}',
                    html: self::MELDUNG_HTML,
                    text: self::MELDUNG_TEXT,
                ));
            }
        });
    }

    private const MELDUNG_HTML = <<<'HTML'
<p style="margin:0 0 16px;font-size:16px;font-weight:bold;">{{ titel }}</p>
<div style="margin:0 0 16px;white-space:pre-line;color:#374151;">{{ text }}</div>
<p style="margin:0;color:#9ca3af;font-size:12px;">Ausgelöst von: {{ quelle }}</p>
HTML;

    private const MELDUNG_TEXT = <<<'TEXT'
{{ titel }}

{{ text }}

—
Ausgelöst von: {{ quelle }}
TEXT;
}

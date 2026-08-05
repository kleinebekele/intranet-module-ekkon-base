<?php

namespace Intranet\Modules\Ekkon\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Ekkon\Ekkon;
use PDO;
use Throwable;

/**
 * Beweist (oder widerlegt), dass das Abfrage-Zeitlimit der MSSQL-Verbindung
 * wirklich greift.
 *
 * ── Warum es diesen Befehl gibt ─────────────────────────────────────────
 * Nach dem Vorfall am 2026-08-05 (eine Abfrage lief 67 Minuten und nahm die
 * produktive Wawi mit) steht in config/ekkon.php ein PDO::ATTR_TIMEOUT. Ob
 * PDO_ODBC diesen Wert als ABFRAGE-Zeitlimit durchreicht oder nur als
 * Verbindungs-Zeitlimit, ist treiberabhaengig - es haengt am ODBC-Treiber und
 * an der PHP-Fassung.
 *
 * Eine Schutzmassnahme, von der man nur GLAUBT, dass sie wirkt, ist
 * gefaehrlicher als gar keine: Man verlaesst sich darauf und schaut nicht mehr
 * hin. Deshalb wird sie hier gemessen statt angenommen.
 *
 * Der Test schickt ein absichtlich langsames WAITFOR DELAY und prueft, ob die
 * Datenbank vorher abbricht. Er veraendert nichts.
 */
class TimeoutTestCommand extends Command
{
    protected $signature = 'ekkon:timeout-test {--sekunden=5 : Zeitlimit fuer diesen Test}
                                               {--warten=30 : So lange soll die Testabfrage kuenstlich brauchen}';

    protected $description = 'Prueft, ob das Abfrage-Zeitlimit der MSSQL-Verbindung wirklich greift (schreibt nichts).';

    public function handle(): int
    {
        if (! Ekkon::mssqlKonfiguriert()) {
            $this->error('Keine MSSQL-Verbindung konfiguriert (MSSQL_ODBC_DSN).');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('sekunden'));
        $warten = max(1, (int) $this->option('warten'));

        if ($warten <= $limit) {
            $this->error('--warten muss groesser sein als --sekunden, sonst beweist der Test nichts.');

            return self::FAILURE;
        }

        $this->info("Zeitlimit: {$limit}s · Testabfrage braucht: {$warten}s");
        $this->line('Erwartung: Die Abfrage bricht nach etwa '.$limit.' Sekunden mit einem Fehler ab.');
        $this->newLine();

        // Eigene Verbindung mit dem Testwert - die echte 'wawi'-Verbindung
        // bleibt unangetastet.
        $config = config('ekkon.mssql');
        $config['options'] = [PDO::ATTR_TIMEOUT => $limit];
        config(['database.connections.ekkon-timeout-test' => $config]);

        $start = microtime(true);

        try {
            DB::connection('ekkon-timeout-test')
                ->statement("WAITFOR DELAY '00:00:".str_pad((string) $warten, 2, '0', STR_PAD_LEFT)."'");

            $dauer = microtime(true) - $start;

            $this->newLine();
            $this->error(sprintf(
                'DAS ZEITLIMIT GREIFT NICHT. Die Abfrage lief %.1f Sekunden durch.',
                $dauer,
            ));
            $this->line('Folge: PDO::ATTR_TIMEOUT in config/ekkon.php ist auf diesem Server wirkungslos.');
            $this->line('Eine einzelne entgleiste Abfrage kann die Datenbank weiterhin blockieren - der Schutz');
            $this->line('beschraenkt sich dann auf die Laufzeit-Warnung des TaskRunners.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $dauer = microtime(true) - $start;

            // Kurz vor dem Limit abgebrochen = der Treiber hat es umgesetzt.
            // Grosszuegige Grenze, weil Verbindungsaufbau und Latenz dazukommen.
            if ($dauer < $warten * 0.9) {
                $this->newLine();
                $this->info(sprintf('Das Zeitlimit greift: Abbruch nach %.1f Sekunden.', $dauer));
                $this->line('Meldung: '.mb_substr($e->getMessage(), 0, 200));

                return self::SUCCESS;
            }

            $this->newLine();
            $this->error(sprintf(
                'Abbruch erst nach %.1f Sekunden - das war nicht das Zeitlimit, sondern die Abfrage selbst.',
                $dauer,
            ));
            $this->line('Meldung: '.mb_substr($e->getMessage(), 0, 200));

            return self::FAILURE;
        }
    }
}

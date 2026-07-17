<?php

namespace Intranet\Modules\Ekkon\Tasks\Mssql;

use Illuminate\Support\Facades\DB;
use Intranet\Modules\Ekkon\Ekkon;
use Intranet\Modules\Ekkon\Tasks\EkkonTask;
use PDO;

/**
 * Verbindungstest zur MSSQL-Quelle: prüft, dass die Verbindung steht, und liefert
 * Serverversion, Datenbankname und Serverzeit zurück.
 *
 * Läuft täglich als Frühwarnung – und ist beim Einrichten das Werkzeug, mit dem
 * man herausfindet, woran es hängt: Ohne Zugangsdaten oder ohne passende
 * PHP-Erweiterung meldet er das im Klartext, statt einen PDO-Fehler zu werfen,
 * den niemand deuten kann.
 */
class Ping extends EkkonTask
{
    public string $category = 'Demo';

    public string $description = 'Verbindungstest zur MSSQL-Quelle (Version, Datenbank, Serverzeit).';

    public function schedule(): string
    {
        return '0 6 * * *';
    }

    public function run(): array
    {
        $treiber = PDO::getAvailableDrivers();
        $this->debug['pdo_treiber'] = $treiber;

        if (! Ekkon::mssqlKonfiguriert()) {
            $this->msg('Keine MSSQL-Zugangsdaten hinterlegt – erwartet wird MSSQL_ODBC_DSN oder MSSQL_HOST in der .env.');

            return ['konfiguriert' => false, 'pdo_treiber' => $treiber];
        }

        // sqlsrv (nativ) ODER odbc muss vorhanden sein – sonst scheitert PDO mit
        // "could not find driver", was wie ein Konfigurationsfehler aussieht,
        // aber eine fehlende PHP-Erweiterung ist.
        if (! array_intersect(['sqlsrv', 'odbc'], $treiber)) {
            $this->msg('Kein MSSQL-fähiger PDO-Treiber installiert. Vorhanden: '.(implode(', ', $treiber) ?: 'keiner').'. Gebraucht wird pdo_sqlsrv oder pdo_odbc.');

            return ['konfiguriert' => true, 'treiber_vorhanden' => false, 'pdo_treiber' => $treiber];
        }

        $connection = Ekkon::mssqlConnection();

        $row = DB::connection($connection)->selectOne(
            'SELECT @@VERSION AS version, DB_NAME() AS db, SYSDATETIME() AS server_time'
        );

        $this->msg('Verbindung "'.$connection.'" steht – Datenbank '.$row->db.'.');

        return [
            'connected' => true,
            'connection' => $connection,
            'database' => $row->db,
            'server_time' => (string) $row->server_time,
            'version' => trim(strtok((string) $row->version, "\n")),
        ];
    }
}

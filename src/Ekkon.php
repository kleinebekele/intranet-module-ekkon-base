<?php

namespace Intranet\Modules\Ekkon;

/**
 * Die schmale öffentliche Schnittstelle des Basis-Pakets für Submodule.
 *
 * Submodule sollen NICHT auf die interne Config-Struktur (config('ekkon.mssql.…'))
 * zugreifen – sonst bricht jede Umbenennung dort fremde Pakete. Sie fragen hier.
 */
class Ekkon
{
    /**
     * Name der Laravel-Connection zur MSSQL-Quelle: DB::connection(Ekkon::mssqlConnection()).
     *
     * Der Name ist konfigurierbar, damit er zur jeweiligen Datenquelle passen darf
     * (z. B. "wawi" statt eines nichtssagenden "mssql") – die Tasks des Submoduls
     * bleiben dadurch lesbar.
     */
    public static function mssqlConnection(): string
    {
        return (string) config('ekkon.mssql_connection', 'mssql');
    }

    /**
     * Sind überhaupt Zugangsdaten hinterlegt?
     *
     * Wichtig für Tasks/Seiten, die ohne Verbindung etwas anderes tun (Fallback,
     * Hinweis). Ohne diese Prüfung liefert PDO einen kryptischen Treiberfehler –
     * und wer einen Fallback baut, muss WISSEN, dass er im Fallback ist, sonst
     * zeigt eine funktionierende Seite stillschweigend veraltete Zahlen.
     */
    public static function mssqlKonfiguriert(): bool
    {
        $config = (array) config('ekkon.mssql', []);

        return ($config['odbc_datasource_name'] ?? '') !== '' || ($config['host'] ?? '') !== '';
    }
}

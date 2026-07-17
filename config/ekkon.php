<?php

return [

    /*
     * Sicherheitsschalter: Nur wenn EKKON_TASKS_ENABLED=true gesetzt ist, werden
     * Tasks ausgeführt – weder der Scheduler noch der „jetzt ausführen"-Button
     * noch `artisan ekkon:task` laufen sonst. Auf dem Server: true. Lokal
     * bewusst NICHT setzen, damit die Entwicklung nie versehentlich echte
     * Fremdsysteme anfasst.
     */
    'tasks_enabled' => (bool) env('EKKON_TASKS_ENABLED', false),

    /*
     * Name, unter dem die MSSQL-Verbindung als Laravel-Connection registriert wird.
     * Submodule holen ihn über Ekkon::mssqlConnection(), nicht von hier.
     *
     * Konfigurierbar, damit der Name zur Datenquelle passen darf: Eine Instanz, die
     * ihre Warenwirtschaft liest, setzt MSSQL_CONNECTION=wawi und ihre Tasks
     * schreiben lesbar DB::connection('wawi') statt eines nichtssagenden 'mssql'.
     */
    'mssql_connection' => env('MSSQL_CONNECTION', 'mssql'),

    /*
     * Die MSSQL-Quelle. ZWEI WEGE – die installierte PHP-Erweiterung entscheidet,
     * welcher geht. Welche vorhanden ist, verrät der Task „Mssql/Ping": er listet
     * die verfügbaren PDO-Treiber mit auf.
     *
     *  a) Nativ (pdo_sqlsrv):
     *       MSSQL_HOST=sql.example.local
     *       MSSQL_PORT=1433
     *       MSSQL_DB_DATABASE=meinedb
     *       MSSQL_DB_USERNAME=leser
     *       MSSQL_DB_PASSWORD=...
     *
     *  b) Über ODBC (pdo_odbc + Microsoft ODBC Driver). Sobald MSSQL_ODBC_DSN
     *     gesetzt ist, gilt dieser Weg und Host/Port werden ignoriert:
     *       MSSQL_ODBC_DSN="Driver={ODBC Driver 18 for SQL Server};Server=host,1433;Database=meinedb;TrustServerCertificate=yes"
     *       MSSQL_DB_USERNAME=leser
     *       MSSQL_DB_PASSWORD=...
     */
    'mssql' => [
        'driver' => 'sqlsrv',

        // Nur mit hinterlegtem DSN geht Laravel den ODBC-Weg.
        'odbc' => env('MSSQL_ODBC_DSN', '') !== '',
        'odbc_datasource_name' => env('MSSQL_ODBC_DSN', ''),

        'host' => env('MSSQL_HOST', ''),
        'port' => env('MSSQL_PORT', 1433),
        'database' => env('MSSQL_DB_DATABASE', ''),
        'username' => env('MSSQL_DB_USERNAME', ''),
        'password' => env('MSSQL_DB_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',

        /*
         * Selbstsigniertes Server-Zertifikat akzeptieren. Nur für den nativen Weg –
         * beim ODBC-Weg gehört TrustServerCertificate in den DSN.
         */
        'trust_server_certificate' => env('MSSQL_TRUST_CERT', false),
    ],

];

# Ekkon – Task-System für die Intranet-Plattform

Ekkon führt wiederkehrende Aufgaben aus und macht sie sichtbar: Lauf-Historie mit
Dauer und Ergebnis, „jetzt ausführen" auf Knopfdruck, Pause-Schalter je Aufgabe,
Benachrichtigungen bei Auffälligkeiten – und eine fertige MSSQL-Anbindung für
Aufgaben, die aus einem SQL Server lesen.

> **Voraussetzung:** Dieses Paket ist ein Modul der Intranet-Plattform
> (`intranet-core`) und erbt von deren `App\Modules\Support\ModuleServiceProvider`.
> In einem beliebigen Laravel-Projekt läuft es nicht.

## Installation

```bash
composer require do1emu/module-ekkon
php artisan migrate && php artisan modules:sync
```

Der Server braucht genau **einen** Cron-Eintrag – alles Weitere plant Ekkon selbst:

```
* * * * * php /pfad/zum/intranet/artisan schedule:run
```

In der `.env`:

```
EKKON_TASKS_ENABLED=true
```

Ohne diesen Schalter läuft **kein** Task – weder per Scheduler noch per Button noch
per `artisan ekkon:task`. Absicht: Auf Entwicklungsrechnern soll nie versehentlich
ein echtes Fremdsystem angefasst werden.

## Eine Aufgabe schreiben

Eine Klasse unter `src/Tasks/<Gruppe>/<Name>.php`, die von `EkkonTask` erbt – mehr
nicht. Registrieren muss man sie nicht, der Key ergibt sich aus dem Pfad:

```php
namespace Intranet\Modules\MeinModul\Tasks\Import;

use Intranet\Modules\Ekkon\Tasks\EkkonTask;

class Nachtlauf extends EkkonTask
{
    public string $category = 'Täglich';
    public string $description = 'Liest die Stammdaten der Nacht ein.';

    public function schedule(): string
    {
        return '15 3 * * *';   // Cron-Ausdruck
    }

    public function run(): array
    {
        $this->msg('42 Datensätze übernommen.');   // Klartext für die Historie
        $this->debug['ids'] = [1, 2, 3];           // Details, nur die letzten 10 Läufe

        return ['uebernommen' => 42];              // Kennzahlen als Ergebnis-JSON
    }
}
```

Statt eines starren Cron kann eine Aufgabe ihren nächsten Lauf auch selbst
bestimmen (`$this->setInterval($zeitpunkt)`); der Cron wird dann zum Herzschlag.

## Aufgaben aus eigenen Modulen beisteuern

Jedes Modul meldet sein Tasks-Verzeichnis im **`register()`** seines Providers an:

```php
public function register(): void
{
    parent::register();

    $this->app->singletonIf(TaskRegistry::class);

    $this->app->make(TaskRegistry::class)->addSource(
        $this->moduleBasePath().'/src/Tasks',
        __NAMESPACE__.'\\Tasks',
        'meinvendor/mein-modul',
    );
}
```

`singletonIf` statt `singleton` ist hier nicht Geschmack: Bände ein Modul die
Registry hart, würde es die bereits befüllte Instanz eines anderen ersetzen – und
dessen Aufgaben wären lautlos verschwunden. `addSource()` gehört ins `register()`,
nicht ins `boot()`; aufgelöst wird erst beim ersten Zugriff, dadurch ist die
Ladereihenfolge der Provider egal.

> **Konvention: Eine Gruppe gehört genau einem Paket.** Der Task-Key
> („Gruppe/Name") ist zugleich Schlüssel der Lauf-Historie, Name der Sperre,
> Route-Parameter und Pause-Schalter. Zwei Aufgaben unter einem Key würden sich
> Historie und Pause teilen. Passiert es doch, gewinnt die zuerst gefundene; die
> andere wird verworfen und im Dashboard sowie von `artisan ekkon:task` (Exit-Code
> 1) gemeldet.

## MSSQL-Anbindung

Ekkon registriert eine Laravel-Connection zu einem SQL Server. Aufgaben holen den
Namen über die schmale API des Pakets, nicht aus der Config:

```php
DB::connection(Ekkon::mssqlConnection())->select('SELECT …');

if (! Ekkon::mssqlKonfiguriert()) { /* … */ }
```

Zwei Wege, je nach installierter PHP-Erweiterung – **nativ** (`pdo_sqlsrv`) oder
über **ODBC** (`pdo_odbc` + Microsoft ODBC Driver):

```
MSSQL_CONNECTION=mssql          # Name der Connection; darf zur Quelle passen (z. B. "wawi")

# nativ
MSSQL_HOST=sql.example.local
MSSQL_PORT=1433
MSSQL_DB_DATABASE=meinedb
MSSQL_DB_USERNAME=leser
MSSQL_DB_PASSWORD=…

# oder über ODBC (hat Vorrang, sobald gesetzt)
MSSQL_ODBC_DSN="Driver={ODBC Driver 18 for SQL Server};Server=host,1433;Database=meinedb;TrustServerCertificate=yes"
```

Welcher Treiber vorhanden ist und ob die Verbindung steht, beantwortet die
mitgelieferte Aufgabe **`Mssql/Ping`**: Sie meldet fehlende Zugangsdaten und
fehlende Erweiterungen im Klartext, statt einen PDO-Fehler zu werfen, den niemand
deuten kann.

## Benachrichtigungen

Eine Aufgabe kann melden, ohne zu wissen, wer es bekommt:

```php
public array $meldungsarten = ['import-leer' => 'Nächtlicher Import ohne Ergebnis'];

$this->benachrichtige('import-leer', 'Import leer', 'Heute Nacht kam nichts an.');
```

Die Ziele (Teams-Webhook, Mail) pflegt man unter „Benachrichtigungen" in der
Oberfläche. Aufgelöst werden sie beim **Anlegen** der Meldung, zugestellt wird
**dumm** – dadurch kennt der Versand weder Meldungsarten noch Empfänger und kann
nicht zur `if`-Wüste wachsen. Gibt es für eine Meldungsart keine Route, verschwindet
die Meldung nicht, sondern bleibt als `ohne_ziel` sichtbar: Sonst vergisst man die
Route und merkt monatelang nicht, dass niemand informiert wird.

## Lizenz

MIT.

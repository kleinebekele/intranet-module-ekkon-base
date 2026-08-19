# CLAUDE.md — Ekkon

Interne Hinweise für die Arbeit an diesem Repository (Entwickler + KI-Assistenten).
Die öffentliche Kurzfassung steht in der [README.md](README.md).

Ekkon ist **kein Fachmodul, sondern die Laufzeit für wiederkehrende Aufgaben**. Fachmodule
melden ihre Tasks bei der Registry an und nutzen Protokoll, Sperren und Benachrichtigungen
dieses Pakets. Paketname: `do1emu/module-ekkon`.

## Lokal entwickeln

Während der Entwicklung wird das Paket von einer Instanz als **Path-Repository** eingebunden;
andere Instanzen haben unter `vendor/` nur eine Composer-Kopie. Vor dem Editieren prüfen,
welcher der beiden Fälle vorliegt — Änderungen an einer vendor-Kopie sind beim nächsten
`composer update` weg.

```bash
php artisan ekkon:task <Gruppe/Name>   # einen Task von Hand starten
```

## Der Task-Vertrag

Ein Task erbt von `EkkonTask` (`src/Tasks/EkkonTask.php`) und liefert:

- `schedule()` — Cron-Ausdruck
- `run(): array` — die eigentliche Arbeit

Darin:

| Aufruf | Wirkung |
|---|---|
| `$this->msg(...)` | Klartext ins Protokoll |
| `$this->debug[...]` | strukturierte Daten zum Lauf |
| `$this->benachrichtige(...)` | Meldung an die Routing-Tabelle |

⚠️ Die Registry hält Tasks als **Singleton** — `resetChannels()` leert die Kanäle vor jedem Lauf.
Ohne das schleppt ein Task Meldungen des Vorlaufs mit.

`TaskRegistry::addSource($dir, $ns, $paket)` meldet die Tasks eines Fachmoduls an
(`src/Support/TaskRegistry.php`). Die Registry wird mit **`singletonIf`** gebunden — dieselbe
Provider-Reihenfolge-Falle wie im Core: Paket-Provider laufen **vor** dem `AppServiceProvider`,
ein hartes `singleton` würde die bereits gefüllte Registry mit einer leeren überschreiben.

## Läufe und Sperren

`TaskRunner` (`src/Support/TaskRunner.php`) schreibt jeden Lauf nach `ekkon_task_runs`
(Status, Dauer, `messages`, `debug`) und sperrt gegen Überlappung.

⚠️ **`lockSeconds()` muss länger sein als der längste Lauf** (Default 600 s). Sonst verfällt die
Sperre mitten im Lauf, ein zweiter Lauf startet parallel und beide schreiben dieselben
Schlüssel — der Fehler sieht dann wie ein Datenproblem aus, nicht wie ein Sperrproblem.

**Aufbewahrung:** `debug` nur für die letzten **10 Läufe** je Task (`TaskRunner::pruneDebug`),
die Lauf-Historie **14 Tage** (Scheduler-Eintrag im `EkkonServiceProvider`).
Ein auffälliger Lauf eines Minuten-Tasks ist also schnell weg — vorher rauskopieren.

## Benachrichtigungen

Das Routing passiert beim **Anlegen** (`src/Services/Benachrichtiger.php`), der Versand ist
bewusst dumm (`src/Tasks/Notifications/SendNotifications.php`).

- Kein `if ($meldungsart === …)` im Versand — Fallunterscheidung gehört in die Routing-Tabelle.
- Meldungsarten deklariert der Task selbst (`public array $meldungsarten`); die Maske bietet
  nur diese an.
- Teams läuft über einen **Workflow**, nicht über den klassischen Connector (der ist abgekündigt).
- Zustellung über eigene Tabelle + Task, bewusst **nicht** über die Laravel-Queue.

## Sicherheitsschalter

Ohne **`EKKON_TASKS_ENABLED=true`** läuft **kein** Task — auch nicht „jetzt ausführen" in der
Oberfläche und auch nicht `artisan ekkon:task`. In Entwicklungsumgebungen bewusst nicht setzen.

Der Laravel-Scheduler braucht außerdem genau **einen** Cron-Eintrag
(`* * * * * php artisan schedule:run`) — ohne ihn läuft kein Task.

## MSSQL: immer über PDO_ODBC

Nie nativ, sondern über `MSSQL_ODBC_DSN` — so haben alle Umgebungen dieselben Treiber-Macken.
Bekannte Fallen:

- SQL-`NULL` kommt als **Leerstring** an, Zahlen als String. `=== null` läuft ins Leere, ein
  `UPDATE` trifft dann lautlos 0 Zeilen → Rohzeile an der Grenze normalisieren und die Wirkung
  per `COUNT` prüfen.
- Rohe `datetime`-Spalten in einem größeren SELECT können den Puffer zerlegen (die
  *Nachbar*-Spalte kommt mit Binärmüll zurück) → `CONVERT(varchar(19), …, 120)` nutzen.
- `[` ist im `LIKE`-Muster ein Sonderzeichen: `LIKE '[%'` matcht lautlos **nichts**.
  JSON-Array-Prüfung stattdessen über `LEFT(LTRIM(x),1) = '['`.
- Ein nicht indexfähiger Vergleich (z. B. `REPLACE()` um die Spalte) kippt einen Seek in einen
  Vollscan; bei leerer Treffermenge fehlt zusätzlich der frühe Abbruch. Dann in zwei Schritte
  zerlegen statt eine große Abfrage zu bauen.

<?php

namespace Intranet\Modules\Ekkon\Support;

use Intranet\Modules\Ekkon\Tasks\EkkonTask;
use LogicException;
use ReflectionClass;

/**
 * Findet alle Task-Klassen der angemeldeten Pakete.
 *
 * Jedes Paket meldet sein Tasks-Verzeichnis per addSource() an – das Basis-Paket
 * genauso wie jedes Submodul. Aufgelöst wird erst beim ersten all(); dadurch ist
 * die Reihenfolge, in der Laravel die Provider lädt, egal.
 *
 * Task-Klassen liegen unter <Quelle>/<Gruppe>/<Name>.php; der Key ergibt sich als
 * "Gruppe/Name". Registrieren muss man einen Task nicht – Klasse anlegen genügt.
 */
class TaskRegistry
{
    /** @var list<array{dir: string, ns: string, paket: string}> */
    private array $sources = [];

    /** @var array<string, EkkonTask>|null key => Task-Instanz */
    private ?array $tasks = null;

    /** @var list<array{key: string, behalten: string, verworfen: string, paket: string}> */
    private array $kollisionen = [];

    /**
     * Tasks-Verzeichnis eines Pakets anmelden. Gehört in register() des Providers,
     * NICHT in boot() – sonst kann die Registry schon aufgelöst sein.
     *
     * @param  string  $dir  absoluter Pfad auf das Tasks-Verzeichnis
     * @param  string  $ns  Namespace darunter, z. B. Intranet\Modules\EkkonJtl\Tasks
     * @param  string  $paket  Paketname im Klartext – erscheint in Kollisions-Meldungen
     */
    public function addSource(string $dir, string $ns, string $paket): void
    {
        if ($this->tasks !== null) {
            throw new LogicException(
                "TaskRegistry ist bereits aufgelöst – {$paket} meldet sich zu spät an. "
                .'addSource() gehört in register() des Providers, nicht in boot().'
            );
        }

        $this->sources[] = [
            'dir' => rtrim($dir, '/\\'),
            'ns' => trim($ns, '\\'),
            'paket' => $paket,
        ];
    }

    /** @return array<string, EkkonTask> */
    public function all(): array
    {
        if ($this->tasks !== null) {
            return $this->tasks;
        }

        $tasks = [];

        foreach ($this->sources as $source) {
            foreach (glob($source['dir'].'/*/*.php') ?: [] as $file) {
                $class = $source['ns'].'\\'.basename(dirname($file)).'\\'.basename($file, '.php');

                if (! class_exists($class) || ! is_subclass_of($class, EkkonTask::class)) {
                    continue;
                }
                if ((new ReflectionClass($class))->isAbstract()) {
                    continue;
                }

                /** @var EkkonTask $task */
                $task = new $class();
                $key = $task->key();

                // Zwei Tasks unter einem Key wären fatal: Der Key ist zugleich
                // Historien-Schlüssel, Cache-Lock, Route-Parameter und Pause-Schalter –
                // die beiden würden sich Lauf-Historie und Pause teilen. Erster gewinnt,
                // der zweite wird verworfen und GEMELDET (Dashboard + ekkon:task).
                // Bewusst keine Exception: ein Fehler im register() eines Nebenmoduls
                // würde sonst das ganze Intranet in einen 500er reißen, Login inklusive.
                if (isset($tasks[$key])) {
                    $this->kollisionen[] = [
                        'key' => $key,
                        'behalten' => $tasks[$key]::class,
                        'verworfen' => $class,
                        'paket' => $source['paket'],
                    ];

                    continue;
                }

                $tasks[$key] = $task;
            }
        }

        ksort($tasks);

        return $this->tasks = $tasks;
    }

    /** @return array<string, array<string, EkkonTask>> Kategorie => (key => Task) */
    public function byCategory(): array
    {
        $grouped = [];
        foreach ($this->all() as $key => $task) {
            $grouped[$task->category][$key] = $task;
        }
        ksort($grouped);

        return $grouped;
    }

    public function find(string $key): ?EkkonTask
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Verworfene Tasks wegen doppelt vergebenem Key. Leer = alles in Ordnung.
     *
     * @return list<array{key: string, behalten: string, verworfen: string, paket: string}>
     */
    public function kollisionen(): array
    {
        $this->all();

        return $this->kollisionen;
    }

    /**
     * Alle Meldungsarten, die irgendein Task deklariert (EkkonTask::$meldungsarten):
     * schluessel => "Klartext (Task/Key)".
     *
     * Quelle für das Dropdown in der Benachrichtigungs-Maske. Bewusst KEIN
     * Freitext: Ein Tippfehler in der Meldungsart würde die Meldung lautlos ins
     * Nichts routen – man legt eine Route an, sie sieht richtig aus, und
     * niemand wird informiert.
     *
     * @return array<string, string>
     */
    public function meldungsarten(): array
    {
        $arten = [];

        foreach ($this->all() as $key => $task) {
            foreach ($task->meldungsarten as $art => $klartext) {
                $arten[$art] = $klartext.' ('.$key.')';
            }
        }

        ksort($arten);

        return $arten;
    }
}

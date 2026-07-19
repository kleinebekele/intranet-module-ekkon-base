<?php

namespace Intranet\Modules\Ekkon\Tasks;

use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Intranet\Modules\Ekkon\Models\TaskSetting;
use Intranet\Modules\Ekkon\Services\Benachrichtiger;

/**
 * Basisklasse aller Ekkon-Tasks.
 *
 * Ein Task ist eine Klasse unter src/Tasks/<Gruppe>/<Name>.php. Die Registry
 * findet ihn automatisch; sein Key ergibt sich aus dem Pfad ("Gruppe/Name").
 * Der Laravel-Scheduler stößt ihn anhand von schedule() an (Cron-Ausdruck).
 *
 * Drei Ausgabe-Kanäle innerhalb von run():
 *  - $this->msg('…')      → menschenlesbare Nachrichten für die Lauf-Historie
 *  - $this->debug[...]    → beliebig strukturierte Debug-Daten (Detail-Ansicht,
 *                           nur die letzten 10 Läufe je Task werden behalten)
 *  - return [...]         → kompaktes Ergebnis-JSON (Kennzahlen)
 *
 * Selbststeuerung: Mit $this->setInterval($zeitpunkt) bestimmt der Task seinen
 * nächsten Lauf selbst. Der Cron aus schedule() wird dann zum Herzschlag – Ticks
 * vor dem Zeitpunkt werden lautlos übersprungen und erzeugen KEINEN
 * Historie-Eintrag. Ohne setInterval gilt der Cron allein.
 */
abstract class EkkonTask
{
    /** Anzeige-Gruppe im Dashboard (freie Gruppierung, z. B. "often", "daily"). */
    public string $category = 'Allgemein';

    /** Kurzbeschreibung: Was tut der Task? Erscheint im Dashboard/Detail. */
    public string $description = '';

    /**
     * Meldungsarten, die dieser Task verschicken kann – Schlüssel => Klartext:
     *
     *   public array $meldungsarten = [
     *       'import-fehlgeschlagen' => 'Nächtlicher Import ohne Ergebnis',
     *   ];
     *
     * Wird im Code deklariert (wie $category/$description), damit die
     * Benachrichtigungs-Maske ein DROPDOWN daraus bauen kann statt Freitext.
     * Grund: Ein Tippfehler in einer frei eingetippten Meldungsart würde die
     * Meldung LAUTLOS ins Nichts routen – man legt eine Route an, sie sieht
     * richtig aus, und niemand wird informiert.
     *
     * @var array<string, string>
     */
    public array $meldungsarten = [];

    /**
     * Einstellungen, die dieser Task im Backend anbietet.
     *
     *   public array $einstellungen = [
     *       'probelauf' => [
     *           'typ' => 'ja_nein',           // ja_nein | text | zahl | auswahl
     *           'label' => 'Probelauf',
     *           'standard' => true,
     *           'hilfe' => 'Liest und berichtet, schreibt aber nichts.',
     *       ],
     *       'grenze' => ['typ' => 'zahl', 'label' => 'Höchstzahl', 'standard' => 100],
     *       'modus' => ['typ' => 'auswahl', 'label' => 'Modus', 'standard' => 'sanft',
     *                   'optionen' => ['sanft' => 'Sanft', 'hart' => 'Hart']],
     *   ];
     *
     * Aus dieser Deklaration baut die Task-Detailseite selbstständig eine Maske.
     * Ein Task muss dafür nichts weiter tun – kein Formular, kein Controller,
     * keine `.env`-Variable. Gelesen wird mit $this->einstellung('probelauf').
     *
     * Warum überhaupt: Die `.env` beschreibt, WO eine Instanz läuft. Was jemand
     * fachlich entscheidet, gehört ins Backend – dort sieht man es, kann es
     * ändern, ohne auf einen Server zu müssen, und es wirkt sofort statt erst
     * nach `config:clear`.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $einstellungen = [];

    /** @var array<string, string|null>|null Zwischenspeicher für den laufenden Vorgang */
    private ?array $gespeicherteEinstellungen = null;

    /**
     * Schwellwerte (Sekunden) für die Farbcodierung der Laufdauer,
     * je Task überschreibbar.
     */
    public array $durationThresholds = [
        'speedOfLight' => 1,
        'veryFast' => 5,
        'fast' => 10,
        'neutral' => 30,
        'slow' => 60,
        'verySlow' => 180,
        'blocker' => 1000,
    ];

    /** @var list<string> Nachrichten des aktuellen Laufs */
    protected array $msg = [];

    /** @var array<string, mixed> Debug-Daten des aktuellen Laufs */
    protected array $debug = [];

    private ?DateTimeInterface $interval = null;

    /** Cron-Ausdruck, wann der Task laufen soll (z. B. "*\/5 * * * *"). */
    abstract public function schedule(): string;

    /**
     * Die eigentliche Logik. Das zurückgegebene Array wird als JSON-Ergebnis
     * des Laufs gespeichert und im Dashboard angezeigt.
     */
    abstract public function run(): array;

    /**
     * Überlappungsschutz: solange (Sekunden) hält der Lauf die Sperre,
     * ein zweiter Start desselben Tasks wird währenddessen übersprungen.
     */
    public function lockSeconds(): int
    {
        return 600;
    }

    /** "Gruppe/Name", abgeleitet aus dem Klassennamen (Tasks\Demo\Ping → "Demo/Ping"). */
    public function key(): string
    {
        $parts = explode('\\', static::class);

        return $parts[count($parts) - 2].'/'.end($parts);
    }

    /** Nächster geplanter Lauf laut Cron-Ausdruck (ohne Selbststeuerung). */
    public function nextRunDate(): DateTimeInterface
    {
        return (new CronExpression($this->schedule()))->getNextRunDate(now());
    }

    // ── Einstellungen ───────────────────────────────────────────────────

    /**
     * Den im Backend eingestellten Wert lesen – oder den Standard aus der
     * Deklaration, solange niemand etwas geändert hat.
     *
     * Der Typ kommt aus der Deklaration, nicht aus dem gespeicherten Text:
     * `ja_nein` liefert bool, `zahl` liefert int, alles andere string.
     */
    protected function einstellung(string $schluessel): mixed
    {
        $deklaration = $this->einstellungen[$schluessel] ?? null;

        if ($deklaration === null) {
            throw new \InvalidArgumentException(
                "Einstellung „{$schluessel}\" ist in ".static::class.' nicht deklariert.'
            );
        }

        $this->gespeicherteEinstellungen ??= TaskSetting::fuer($this->key());

        if (! array_key_exists($schluessel, $this->gespeicherteEinstellungen)) {
            return $deklaration['standard'] ?? null;
        }

        $wert = $this->gespeicherteEinstellungen[$schluessel];

        return match ($deklaration['typ'] ?? 'text') {
            // "0"/"" gelten als Nein – so kommt ein Häkchen aus dem Formular an.
            'ja_nein' => (bool) $wert && $wert !== '0',
            'zahl' => (int) $wert,
            default => (string) $wert,
        };
    }

    // ── Nachrichten & Debug ─────────────────────────────────────────────

    /** Nachricht an die Lauf-Historie anhängen. */
    protected function msg(string $message): void
    {
        $this->msg[] = $message;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->msg;
    }

    /** @return array<string, mixed> */
    public function debugData(): array
    {
        return $this->debug;
    }

    /** Kanäle vor einem Lauf leeren (Registry hält Task-Instanzen als Singleton). */
    public function resetChannels(): void
    {
        $this->msg = [];
        $this->debug = [];
        $this->interval = null;
    }

    // ── Benachrichtigungen ──────────────────────────────────────────────

    /**
     * Benachrichtigung anlegen. Die ZIELE werden hier sofort aufgelöst
     * (Routing-Tabelle), der Versand passiert später im Task
     * Notifications/SendNotifications.
     *
     * Ein Task darf mehrere Benachrichtigungen anlegen. Er scheitert NIE an
     * einer: Der Versand ist entkoppelt, und der Teams-Client wirft nicht.
     *
     * @param  string  $meldungsart  muss in $this->meldungsarten deklariert sein
     * @param  array<string, mixed>  $daten  strukturiertes Beiwerk
     * @param  string|null  $idempotenzSchluessel  gesetzt = dieselbe Meldung wird
     *                      nie zweimal angelegt. Für häufig laufende Tasks
     *                      praktisch Pflicht: Ein 15-Minuten-Task würde sonst
     *                      dieselbe Meldung 96x am Tag posten.
     * @return array{angelegt: int, ohne_ziel: bool, uebersprungen: int}
     */
    protected function benachrichtige(
        string $meldungsart,
        string $titel,
        string $text,
        array $daten = [],
        ?string $idempotenzSchluessel = null,
    ): array {
        return (new Benachrichtiger())->benachrichtige(
            $meldungsart,
            $titel,
            $text,
            $daten,
            $idempotenzSchluessel,
            $this->key(),
        );
    }

    // ── Selbststeuerung (setInterval) ───────────────────────────────────

    /** Nächsten Lauf selbst bestimmen; bis dahin schlummert der Task lautlos. */
    protected function setInterval(DateTimeInterface $when): void
    {
        $this->interval = $when;
    }

    /** Vom Lauf gesetzter nächster Zeitpunkt (null = Cron entscheidet). */
    public function interval(): ?DateTimeInterface
    {
        return $this->interval;
    }

    protected function calculateNextXMinute(int $minutes): Carbon
    {
        return now()->addMinutes($minutes)->startOfMinute();
    }

    protected function calculateNextDayAtTime(int $hour): Carbon
    {
        return now()->addDay()->setTime($hour, 0);
    }

    // ── Farbcodierung der Laufdauer ─────────────────────────────────────

    /**
     * Tailwind-Klassen für eine Historie-Zeile anhand der Laufdauer:
     * Türkis (Lichtgeschwindigkeit) über Grüntöne, Gelb, Orange, Rot
     * bis Schwarz (Blocker) – Schwellwerte aus $durationThresholds.
     */
    public function durationClasses(?float $ms): string
    {
        if ($ms === null) {
            return 'bg-gray-50 text-gray-500';
        }

        $s = $ms / 1000;
        $t = $this->durationThresholds;

        return match (true) {
            $s <= $t['speedOfLight'] => 'bg-cyan-100 text-cyan-900',
            $s <= $t['veryFast'] => 'bg-green-50 text-green-900',
            $s <= $t['fast'] => 'bg-green-100 text-green-900',
            $s <= $t['neutral'] => 'bg-green-200 text-green-900',
            $s <= $t['slow'] => 'bg-yellow-100 text-yellow-900',
            $s <= $t['verySlow'] => 'bg-orange-200 text-orange-900',
            $s <= $t['blocker'] => 'bg-red-200 text-red-900',
            default => 'bg-gray-800 text-gray-100',
        };
    }
}

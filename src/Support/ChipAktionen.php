<?php

namespace Intranet\Modules\Ekkon\Support;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;

/**
 * Zusatz-Aktionen von Fachmodulen auf der Seite „Chip einlesen".
 *
 * Die Basis liest den Chip und zeigt die Kennung in allen Schreibweisen. Was
 * damit in einem Fremdsystem passieren soll (Chip einem Konto zuordnen o. ä.),
 * weiß nur das Fachmodul – es hängt sich hier ein und liefert ein Stück HTML,
 * das unter der Tabelle erscheint. Formulare/Aufrufe darin gehören dem Modul
 * (eigene Routen; Namen unter module.ekkon.chip.* erben die Rollen des
 * Menüpunkts „Chip einlesen").
 *
 *   // im boot() des Modul-Providers
 *   ChipAktionen::registrieren('menueserve', fn () => view('meinmodul::chip-aktion'));
 *
 * Das HTML liegt innerhalb der Alpine-Komponente der Seite; darin sind die
 * Rohdaten als `eingabe` und die bereinigte UID als `werte` lesbar (null, solange
 * nichts gelesen wurde).
 */
class ChipAktionen
{
    /** @var array<string, Closure(): (View|string|null)> */
    private static array $aktionen = [];

    public static function registrieren(string $schluessel, Closure $aktion): void
    {
        self::$aktionen[$schluessel] = $aktion;
    }

    /**
     * Gerenderte Aktionen, Schlüssel => HTML. Ein Fehler in einer Aktion reißt
     * die Seite nicht mit, sondern erscheint als Hinweiskasten.
     *
     * @return array<string, string>
     */
    public static function alle(): array
    {
        $html = [];

        foreach (self::$aktionen as $schluessel => $aktion) {
            try {
                $ergebnis = $aktion();
                if ($ergebnis instanceof View) {
                    $ergebnis = $ergebnis->render();
                }
                if ($ergebnis === null || trim((string) $ergebnis) === '') {
                    continue;
                }
                $html[$schluessel] = (string) $ergebnis;
            } catch (\Throwable $e) {
                Log::warning("Chip-Aktion „{$schluessel}\" fehlgeschlagen: ".$e->getMessage());
                $html[$schluessel] = '<div class="rounded-xl border border-amber-200 bg-amber-50 px-6 py-4 text-sm text-amber-800">'
                    .'Aktion „'.e($schluessel).'" konnte nicht geladen werden: '.e($e->getMessage()).'</div>';
            }
        }

        return $html;
    }
}

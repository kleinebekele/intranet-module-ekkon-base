<?php

namespace Intranet\Modules\Ekkon\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Intranet\Modules\Ekkon\Support\ChipAktionen;

/**
 * „Chip einlesen": zeigt die Kennung eines 125-kHz-Chips (EM4100) in allen
 * Schreibweisen, die gaengige Systeme verwenden (Intranet/Kantine,
 * LCN-Pro, LCN-Schlüssel, Zeiterfassung). Reine Anzeige-Seite, speichert nichts;
 * das Lesen und Umrechnen passiert im Browser (Web Serial bzw. Tastatur-Leser).
 */
class ChipController extends Controller
{
    public function index(): View
    {
        return view('ekkon::chip.index', ['aktionen' => ChipAktionen::alle()]);
    }
}

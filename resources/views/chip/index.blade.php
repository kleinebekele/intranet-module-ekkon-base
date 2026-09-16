<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Chip einlesen</h2>
    </x-slot>

    {{-- Liest einen 125-kHz-Chip (EM4100) und zeigt die Kennung in allen Schreibweisen,
         die gängige Systeme (LCN, Zeiterfassung) verwenden. Zwei Wege: der alte serielle Leser am USB-COM-Adapter
         (Web Serial, Rahmen STX '0' + 10 Hex + Prüfzeichen ETX CR LF) oder ein Leser im
         Tastatur-Modus, der in das Eingabefeld tippt. Nichts wird gespeichert. --}}
    <div class="py-6" x-data="chipLesen()" x-init="init()">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <x-input-label for="eingabe" value="Kennung (Leser tippt hier hinein, oder von Hand eingeben)" />
                        <input id="eingabe" type="text" x-ref="eingabe" x-model="eingabe" @keydown.enter.prevent="uebernehmen(eingabe)"
                               autocomplete="off" spellcheck="false" placeholder="z. B. 010CD3C4C10"
                               class="mt-1 block w-full rounded-md border-gray-300 font-mono shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <button type="button" @click="uebernehmen(eingabe)"
                            class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Umrechnen
                    </button>
                    <button type="button" x-show="hasSerial && !serialOk" @click="serialConnect(true)"
                            class="inline-flex items-center justify-center rounded-md border border-indigo-300 bg-white px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50">
                        COM-Leser verbinden (alter Leser)
                    </button>
                </div>
                <p class="text-xs text-gray-500">
                    Alter Leser am COM-Adapter: einmal „COM-Leser verbinden", danach öffnet sich der Port beim Laden von selbst (Chrome/Edge).
                    Tastatur-Leser: ins Feld klicken und den Chip auflegen.
                </p>
                <p class="text-sm text-red-600" x-show="fehler" x-text="fehler" style="display: none;"></p>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden" x-show="werte" x-cloak>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2">Verwendung</th>
                            <th class="px-4 py-2">Wert</th>
                            <th class="px-4 py-2">Herleitung</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="z in zeilen" :key="z.titel">
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-800" x-text="z.titel"></td>
                                <td class="px-4 py-2 font-mono text-base text-gray-900" x-text="z.wert"></td>
                                <td class="px-4 py-2 text-gray-500" x-text="z.herleitung"></td>
                                <td class="px-4 py-2 text-right">
                                    <button type="button" @click="kopieren(z.wert)"
                                            class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50">Kopieren</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-600 space-y-2">
                <p class="font-medium text-gray-800">Was steckt in der Kennung?</p>
                <p>
                    Ein EM4100-Chip trägt 40 Bit, also 10 Hex-Zeichen. Der alte Leser sendet davor eine „0" und dahinter ein
                    Prüfzeichen. Das erste Byte (meist „01") ist die Versions- bzw. Kundenkennung, die restlichen
                    4 Bytes (8 Hex) sind die eigentliche Nummer. LCN-Pro zeigt genau diese 8 Hex, der LCN-Schlüssel
                    nutzt die letzten 3 Bytes, eine Zeiterfassung die Dezimalzahl der 8 Hex, abgeschnitten auf 7 Stellen.
                </p>
            </div>
        </div>
    </div>

    <script>
    function chipLesen() {
        return {
            eingabe: '',
            fehler: '',
            werte: null,
            zeilen: [],
            hasSerial: 'serial' in navigator,
            serialOk: false,
            serialBuf: '',
            init() {
                if (this.hasSerial) this.serialConnect(false);
                this.$nextTick(() => this.$refs.eingabe.focus());
            },
            // Rohtext aus Leser oder Feld → 10 Hex (mit Versionsbyte). Erkennt:
            // '0' + 10 Hex + Prüfzeichen (Roh-Rahmen), 10 Hex + Prüfzeichen, 10 Hex,
            // 8 Hex (LCN-Pro, ohne Versionsbyte → als 00 ergänzt).
            uidAus(raw) {
                const s = (raw || '').trim();
                if (/^0[0-9A-Fa-f]{10}.$/.test(s)) return s.slice(1, 11);
                if (/^[0-9A-Fa-f]{10}.$/.test(s)) return s.slice(0, 10);
                if (/^[0-9A-Fa-f]{10}$/.test(s)) return s;
                if (/^[0-9A-Fa-f]{8}$/.test(s)) return '00' + s;
                return '';
            },
            uebernehmen(raw) {
                const uid = this.uidAus(raw);
                if (!uid) {
                    this.fehler = 'Das ist keine Chip-Kennung (erwartet 10 Hex-Zeichen, ggf. mit führender 0 und Prüfzeichen).';
                    this.werte = null;
                    return;
                }
                this.fehler = '';
                const u = uid.toUpperCase();
                const hex8 = u.slice(2);
                const dez = parseInt(hex8, 16);
                const dezStr = String(dez);
                this.werte = u;
                this.eingabe = u;
                this.zeilen = [
                    { titel: 'Rohdaten vom Leser', wert: (raw || '').trim(), herleitung: 'so wie der Leser es sendet (0 + Kennung + Prüfzeichen)' },
                    { titel: 'Intranet / Kantine (UID)', wert: u, herleitung: 'die 10 Hex ohne führende 0 und ohne Prüfzeichen' },
                    { titel: 'LCN-Pro Busmonitor', wert: hex8, herleitung: 'letzte 8 Hex (ohne Versionsbyte ' + u.slice(0, 2) + ')' },
                    { titel: 'LCN-Schlüssel', wert: u.slice(-6), herleitung: 'letzte 6 Hex (3 Bytes)' },
                    { titel: '7 Hex', wert: u.slice(-7), herleitung: 'letzte 7 Hex' },
                    { titel: 'Dezimal (8 Hex)', wert: dezStr, herleitung: hex8 + ' als Dezimalzahl' },
                    { titel: 'Dezimal 10-stellig', wert: dezStr.padStart(10, '0'), herleitung: 'wie oben, auf 10 Stellen aufgefüllt' },
                    { titel: 'Zeiterfassung', wert: dezStr.slice(-7), herleitung: 'letzte 7 Ziffern der Dezimalzahl' },
                ];
                this.$nextTick(() => { this.$refs.eingabe.select(); });
            },
            async kopieren(text) {
                try { await navigator.clipboard.writeText(text); } catch (e) { /* kein Clipboard-Zugriff */ }
            },
            // ---- Alter serieller Leser (Web Serial), gleiche Logik wie im Kantinen-Terminal ----
            async serialConnect(ask) {
                try {
                    let port = null;
                    if (ask) port = await navigator.serial.requestPort();
                    else port = (await navigator.serial.getPorts())[0] || null;
                    if (!port) return;
                    await port.open({ baudRate: 9600 });
                    this.serialOk = true;
                    this.serialRead(port);
                } catch (err) {
                    this.serialOk = false;
                    if (ask) this.fehler = 'COM-Leser: ' + (err && err.message ? err.message : err);
                }
            },
            async serialRead(port) {
                const dec = new TextDecoder();
                try {
                    while (port.readable) {
                        const reader = port.readable.getReader();
                        try {
                            while (true) {
                                const { value, done } = await reader.read();
                                if (done) break;
                                this.serialChunk(dec.decode(value));
                            }
                        } finally { reader.releaseLock(); }
                    }
                } catch (e) { /* Leser abgezogen */ }
                this.serialOk = false;
            },
            serialChunk(text) {
                for (const ch of text) {
                    const c = ch.charCodeAt(0);
                    if (c === 2) { this.serialBuf = ''; continue; }
                    if (c === 3 || c === 13 || c === 10) {
                        const raw = this.serialBuf; this.serialBuf = '';
                        if (raw.trim()) this.uebernehmen(raw);
                        continue;
                    }
                    this.serialBuf += ch;
                }
            },
        };
    }
    </script>
</x-app-layout>

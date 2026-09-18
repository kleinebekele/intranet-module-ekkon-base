# Ekkon – Übergangspaket

Ekkon (Task-System, Benachrichtigungen, Webhook-Eingang) ist seit 2026-09 **fester
Bestandteil der Intranet-Plattform** und liegt dort unter `app/Ekkon/`; die Anleitung steht in
deren `EKKON.md`.

Dieses Paket enthält ab Version 1.24 **keinen Code mehr**. Es existiert nur, damit Module mit
`"do1emu/module-ekkon": "^1.0"` in ihrer `composer.json` weiter installierbar bleiben. Die alten
Klassennamen (`Intranet\Modules\Ekkon\…`) bildet die Plattform selbst auf `App\Ekkon\…` ab.

Neue Module tragen das Paket nicht mehr ein.

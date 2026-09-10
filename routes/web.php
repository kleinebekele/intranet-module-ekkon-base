<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;
use Intranet\Modules\Ekkon\Http\Controllers\NotificationController;
use Intranet\Modules\Ekkon\Http\Controllers\TaskController;
use Intranet\Modules\Ekkon\Http\Controllers\WebhookController;

Route::middleware(['web', 'auth'])
    ->prefix('modules/ekkon')
    ->name('module.ekkon.')
    ->group(function (): void {
        // Task-System: bewusst HART nur für Administratoren (Betriebswerkzeug) —
        // unabhängig davon, was in der Modul-Verwaltung eingestellt wird.
        Route::middleware(EnsureUserIsAdmin::class)->group(function (): void {
            Route::get('/', [TaskController::class, 'index'])->name('index');
            Route::get('/task/{group}/{name}', [TaskController::class, 'show'])->name('task.show');
            Route::post('/task/{group}/{name}/run', [TaskController::class, 'run'])->name('task.run');
            Route::post('/task/{group}/{name}/toggle', [TaskController::class, 'toggle'])->name('task.toggle');
            Route::post('/task/{group}/{name}/einstellungen', [TaskController::class, 'einstellungen'])->name('task.einstellungen');

            // Benachrichtigungen: Channels sind Passwort-Träger (Webhook-URL),
            // und wer routet, entscheidet, wer Betriebsmeldungen sieht –
            // gehört also zum Betriebswerkzeug, nicht in die Rollen-Freigabe.
            Route::prefix('benachrichtigungen')->name('notifications.')->group(function (): void {
                Route::get('/', [NotificationController::class, 'index'])->name('index');

                Route::post('/channel', [NotificationController::class, 'channelStore'])->name('channel.store');
                Route::put('/channel/{channel}', [NotificationController::class, 'channelUpdate'])->name('channel.update');
                Route::post('/channel/{channel}/test', [NotificationController::class, 'channelTest'])->name('channel.test');
                Route::post('/channel/{channel}/toggle', [NotificationController::class, 'channelToggle'])->name('channel.toggle');
                Route::delete('/channel/{channel}', [NotificationController::class, 'channelDestroy'])->name('channel.destroy');

                Route::post('/route', [NotificationController::class, 'routeStore'])->name('route.store');
                Route::post('/route/{route}/toggle', [NotificationController::class, 'routeToggle'])->name('route.toggle');
                Route::delete('/route/{route}', [NotificationController::class, 'routeDestroy'])->name('route.destroy');

                Route::post('/{notification}/retry', [NotificationController::class, 'retry'])->name('retry');
                Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('destroy');
            });

            // Webhook-Eingang (Admin-Seite): Schlüssel sind Passwörter.
            Route::prefix('webhooks')->name('webhooks.')->group(function (): void {
                Route::get('/', [WebhookController::class, 'index'])->name('index');
                Route::post('/quelle', [WebhookController::class, 'quelleStore'])->name('quelle.store');
                Route::post('/quelle/{quelle}/toggle', [WebhookController::class, 'quelleToggle'])->name('quelle.toggle');
                Route::delete('/quelle/{quelle}', [WebhookController::class, 'quelleDestroy'])->name('quelle.destroy');
                Route::delete('/eingang/{eingang}', [WebhookController::class, 'eingangDestroy'])->name('eingang.destroy');
            });
        });
    });

// Öffentlicher Empfang: bewusst OHNE 'web' (keine Session, kein CSRF) – der
// Absender ist ein fremder Dienst. Der Schlüssel in der URL ist die Zugangsprüfung,
// die Drossel fängt Dauerfeuer ab. Name ohne 'module.'-Präfix, damit die
// Modul-Zugriffsprüfung des Cores hier nicht greift.
Route::post('/webhooks/ekkon/{schluessel}', [WebhookController::class, 'empfangen'])
    ->middleware('throttle:120,1')
    ->name('ekkon.webhook.empfangen');

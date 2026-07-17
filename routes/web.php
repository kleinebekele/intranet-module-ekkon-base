<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;
use Intranet\Modules\Ekkon\Http\Controllers\NotificationController;
use Intranet\Modules\Ekkon\Http\Controllers\TaskController;

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

            // Benachrichtigungen: Channels sind Passwort-Träger (Webhook-URL),
            // und wer routet, entscheidet, wer Betriebsmeldungen sieht –
            // gehört also zum Betriebswerkzeug, nicht in die Rollen-Freigabe.
            Route::prefix('benachrichtigungen')->name('notifications.')->group(function (): void {
                Route::get('/', [NotificationController::class, 'index'])->name('index');

                Route::post('/channel', [NotificationController::class, 'channelStore'])->name('channel.store');
                Route::post('/channel/{channel}/test', [NotificationController::class, 'channelTest'])->name('channel.test');
                Route::post('/channel/{channel}/toggle', [NotificationController::class, 'channelToggle'])->name('channel.toggle');
                Route::delete('/channel/{channel}', [NotificationController::class, 'channelDestroy'])->name('channel.destroy');

                Route::post('/route', [NotificationController::class, 'routeStore'])->name('route.store');
                Route::post('/route/{route}/toggle', [NotificationController::class, 'routeToggle'])->name('route.toggle');
                Route::delete('/route/{route}', [NotificationController::class, 'routeDestroy'])->name('route.destroy');

                Route::post('/{notification}/retry', [NotificationController::class, 'retry'])->name('retry');
            });
        });
    });

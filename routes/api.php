<?php

use App\Http\Controllers\Internal\TelegramSubscriptionsController;
use App\Http\Controllers\Internal\TelegramUpdateController;
use App\Http\Middleware\VerifyTelegramBridgeSignature;
use Illuminate\Support\Facades\Route;

Route::post('/internal/telegram/updates', TelegramUpdateController::class)
    ->middleware(VerifyTelegramBridgeSignature::class)
    ->name('internal.telegram.updates');

Route::post('/internal/telegram/subscriptions', TelegramSubscriptionsController::class)
    ->middleware(VerifyTelegramBridgeSignature::class)
    ->name('internal.telegram.subscriptions');

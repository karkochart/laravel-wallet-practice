<?php

use App\Http\Controllers\Api\DepositWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/deposit', DepositWebhookController::class);

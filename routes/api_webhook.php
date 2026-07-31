<?php

use App\Http\Controllers\Api\Webhook\XenditWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('xendit', XenditWebhookController::class);

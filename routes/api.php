<?php

use App\Enums\ApiCode;
use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/health', static function (): JsonResponse {
    return ResponseHelper::response(['status' => 'up'], 'success', ApiCode::SUCCESS);
})->name('api.health');

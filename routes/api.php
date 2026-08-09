<?php

use App\Domains\Settings\ManageUsers\Api\Controllers\UserController;
use App\Domains\Vault\ManageVault\Api\Controllers\VaultController;
use App\Http\Controllers\Api\ImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the bootstrap/app.php file and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->name('api.')->group(function () {
    // users
    Route::get('user', [UserController::class, 'user']);
    Route::apiResource('users', UserController::class)->only(['index', 'show']);

    // vaults
    Route::apiResource('vaults', VaultController::class);

    // import
    Route::post('import', [ImportController::class, 'store'])->name('import.store');
    Route::get('import/{id}', [ImportController::class, 'show'])->name('import.show');
    Route::post('import/{id}/cancel', [ImportController::class, 'cancel'])->name('import.cancel');
    Route::delete('import/{id}', [ImportController::class, 'cancel'])->name('import.delete');
    Route::get('import/{id}/failed-rows', [ImportController::class, 'downloadFailedRows'])->name('import.failed-rows');
});

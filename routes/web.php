<?php

use Illuminate\Support\Facades\Route;
use Riasath\R2Backup\Http\Controllers\BackupController;

/*
| Registered by R2BackupServiceProvider inside a group that supplies the
| prefix, route-name prefix and middleware from config('r2-backup.routes').
|
| Filenames are constrained to a safe character class so a crafted path can
| never escape the backup prefix — the service defends against this too, but a
| route that cannot express the attack is better than one that catches it.
*/

Route::get('/', [BackupController::class, 'index'])->name('index');

Route::post('/', [BackupController::class, 'store'])->name('store');

Route::get('{name}/download', [BackupController::class, 'download'])
    ->where('name', '[A-Za-z0-9._-]+')
    ->name('download');

Route::delete('{name}', [BackupController::class, 'destroy'])
    ->where('name', '[A-Za-z0-9._-]+')
    ->name('destroy');

<?php

// Fixture routes for tests/Live/smoke.sh. Copied over routes/web.php of a fresh Laravel app.

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

Route::middleware('bfsg')->prefix('live')->group(function () {
    Route::view('/accessible', 'bfsg-live.accessible');
    Route::view('/broken', 'bfsg-live.broken');
    Route::view('/component', 'bfsg-live.component');
    Route::get('/json', fn () => response()->json(['ok' => true, 'items' => [1, 2, 3]]));
    Route::get('/download', function () {
        $path = storage_path('app/bfsg-live-download.txt');
        file_put_contents($path, 'BFSG-LIVE-DOWNLOAD');

        return response()->download($path, 'download.txt');
    });
    Route::get('/redirect', fn () => redirect('/live/accessible'));
});

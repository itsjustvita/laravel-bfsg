<?php

// Fixture routes for tests/Live/smoke.sh. Copied over routes/web.php of a fresh Laravel app.

use App\Http\Middleware\BfsgLiveBasicAuth;
use Illuminate\Http\Request;
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
    Route::view('/spa', 'bfsg-live.spa');
    Route::view('/long/{rest}', 'bfsg-live.broken')->where('rest', '.*');
});

// A staging-like area: HTTP basic auth (deploy / s3cret) in front of a form login (live@example.com / secret).
Route::middleware(BfsgLiveBasicAuth::class)->prefix('live/basic')->group(function () {
    Route::view('/login', 'bfsg-live.login');
    Route::post('/login', function (Request $request) {
        if ($request->input('email') !== 'live@example.com' || $request->input('password') !== 'secret') {
            return redirect('/live/basic/login');
        }

        $request->session()->regenerate();
        $request->session()->put('bfsg_live_user', true);

        return redirect('/live/basic/dashboard');
    });
    Route::get('/dashboard', fn (Request $request) => $request->session()->get('bfsg_live_user')
        ? view('bfsg-live.accessible')
        : redirect('/live/basic/login'));
});

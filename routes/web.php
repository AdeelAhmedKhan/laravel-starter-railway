<?php

use Illuminate\Support\Facades\Route;

/*
| Landing page: a short JSON pointer, not a Blade view. This starter
| is an API — the welcome page adds nothing and would need frontend
| assets. All real routes live in routes/api.php.
*/

Route::get('/', function () {
    return response()->json([
        'app' => config('app.name'),
        'laravel' => \Illuminate\Foundation\Application::VERSION,
        'php' => PHP_VERSION,
        'health' => url('/api/health'),
        'docs' => 'See README.md for the API routes and deploy steps.',
    ]);
});

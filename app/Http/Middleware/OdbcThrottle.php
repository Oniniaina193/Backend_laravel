<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class OdbcThrottle extends Middleware
{
    // app/Http/Middleware/OdbcThrottle.php
public function handle($request, Closure $next)
{
    $key = 'odbc_connections_' . $request->ip();
    $maxConcurrent = 3;
    
    if (Cache::get($key, 0) >= $maxConcurrent) {
        return response()->json(['error' => 'Trop de connexions'], 429);
    }
    
    Cache::increment($key, 1, 30); // Expire en 30 secondes
    
    $response = $next($request);
    
    Cache::decrement($key, 1);
    
    return $response;
}
}
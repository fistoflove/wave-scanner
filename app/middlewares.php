<?php

declare(strict_types=1);

use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

$api->middleware(function ($request, $next) {
    try {
        return $next($request);
    } catch (Throwable $e) {
        error_log('Unhandled error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        if (str_starts_with($request->path(), '/api/')) {
            return Response::json(['error' => 'Internal Server Error'], 500);
        }
        return Response::html('Internal Server Error', 500);
    }
});

$api->middleware(function ($request, $next) use ($api) {
    $path = $request->path();
    $publicPaths = ['/login', '/logout', '/health'];

    if (in_array($path, $publicPaths, true)) {
        return $next($request);
    }

    $isAuthed = $api->auth()->check('session');
    if ($isAuthed) {
        return $next($request);
    }

    if (str_starts_with($path, '/api/')) {
        return Response::json(['error' => 'Unauthorized'], 401);
    }

    return Response::redirect('/login', 302);
});

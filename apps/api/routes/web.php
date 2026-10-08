<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'private-wf-api',
    'contract' => 'packages/shared-types/openapi.yaml',
    'ui' => 'apps/web',
]));

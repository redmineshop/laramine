<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class FrontendSmokeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Health', [
            'status' => 'ok',
        ]);
    }
}

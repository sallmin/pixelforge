<?php

namespace App\Http\Controllers;

use App\Actions\Billing\Summarize;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Summarize $summarize): Response
    {
        Gate::authorize('viewReport');

        return Inertia::render('reports/Index', $summarize());
    }
}

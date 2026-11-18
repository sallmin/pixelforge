<?php

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGeneration;
use App\Billing\Quality;
use App\Billing\Size;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class StoreGenerationController extends Controller
{
    /**
     * Store a generation and queue it for Pixelforge.
     */
    public function __invoke(StoreGenerationRequest $request, CreateGeneration $create): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        /** @var array<int|string, string> $referenceImages */
        $referenceImages = $request->input('reference_images', []);

        $create(
            $user,
            $request->string('prompt')->toString(),
            Size::from($request->string('size')->toString()),
            Quality::from($request->string('quality')->toString()),
            $referenceImages,
        );

        return to_route('dashboard');
    }
}

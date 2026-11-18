<?php

namespace App\Http\Controllers;

use App\Billing\Money;
use App\Generations\Status;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $generations = $user->generations()
            ->latest()
            ->get()
            ->map(fn (Generation $generation): array => $this->card($generation))
            ->all();

        return Inertia::render('generations/Index', [
            'generations' => $generations,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('generations/Create');
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Generation $generation): array
    {
        $minimum = Money::fromNanodollars($generation->estimate_minimum);
        $maximum = Money::fromNanodollars($generation->estimate_maximum);
        $expires = $generation->image_url_expires_at;
        $imageAvailable = is_string($generation->image_url)
            && $generation->image_url !== ''
            && ($expires === null || $expires->isFuture());

        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'status' => $generation->status->value,
            'status_label' => match ($generation->status) {
                Status::Pending => 'Waiting',
                Status::Queued => 'Queued',
                Status::Processing => 'Processing',
                Status::Succeeded => 'Succeeded',
                Status::Failed => 'Failed',
            },
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'estimate_minimum' => $minimum->label(),
            'estimate_maximum' => $maximum->label(),
            'estimate_minimum_exact' => $minimum->exact(),
            'estimate_maximum_exact' => $maximum->exact(),
            'actual' => $generation->status === Status::Succeeded
                ? Money::fromNanodollars($generation->charged)->label()
                : null,
            'actual_exact' => $generation->status === Status::Succeeded
                ? Money::fromNanodollars($generation->charged)->exact()
                : null,
            'error_message' => $generation->error_message,
            'image_url' => $imageAvailable ? $generation->image_url : null,
            'image_expired' => $generation->status === Status::Succeeded && $expires?->isPast() === true,
            'usage' => $generation->usage,
            'in_progress' => in_array($generation->status, [Status::Pending, Status::Queued, Status::Processing], true),
        ];
    }
}

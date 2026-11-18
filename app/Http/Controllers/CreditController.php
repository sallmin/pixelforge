<?php

namespace App\Http\Controllers;

use App\Actions\Billing\Ledger;
use App\Billing\Money;
use App\Http\Requests\StoreCreditRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function index(Request $request, Ledger $ledger): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $balance = Money::fromNanodollars($ledger->available($user->id));

        return Inertia::render('credits/Index', [
            'balance' => [
                'label' => $balance->label(),
                'exact' => $balance->exact(),
            ],
        ]);
    }

    public function store(StoreCreditRequest $request, Ledger $ledger): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $amount = Money::fromUsd($request->string('amount')->toString());

        if ($amount->nanodollars() === 0) {
            return back()->withErrors(['amount' => 'Enter an amount greater than zero.']);
        }

        $ledger->purchase($user, $amount->nanodollars());

        return to_route('credits.index');
    }
}

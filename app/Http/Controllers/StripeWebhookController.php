<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\UseCases\MeetingQuota\HandleStripeWebhookAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, HandleStripeWebhookAction $action): JsonResponse
    {
        $action($request->getContent(), (string) $request->header('Stripe-Signature'));

        return response()->json(['received' => true]);
    }
}

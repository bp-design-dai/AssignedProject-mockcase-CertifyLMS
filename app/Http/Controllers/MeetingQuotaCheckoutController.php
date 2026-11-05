<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MeetingQuota\CheckoutResultRequest;
use App\Http\Requests\MeetingQuota\CreateCheckoutRequest;
use App\UseCases\MeetingQuota\CreateCheckoutAction;
use App\UseCases\MeetingQuota\SelectCheckoutAction;
use App\UseCases\MeetingQuota\ShowCheckoutResultAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MeetingQuotaCheckoutController extends Controller
{
    public function select(Request $request, SelectCheckoutAction $action): View
    {
        return view('meeting-quota.checkout-select', $action($request->user()));
    }

    public function create(CreateCheckoutRequest $request, CreateCheckoutAction $action): RedirectResponse
    {
        return redirect()->away($action($request->user(), $request->validated('meeting_pack_id')), 303);
    }

    public function success(CheckoutResultRequest $request, ShowCheckoutResultAction $action): View
    {
        return view('meeting-quota.success', ['payment' => $action($request->user(), $request->validated('session_id'))]);
    }
}

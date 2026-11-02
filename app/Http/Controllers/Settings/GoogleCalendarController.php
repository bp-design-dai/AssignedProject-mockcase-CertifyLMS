<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Exceptions\GoogleCalendarException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GoogleCalendar\CallbackRequest;
use App\UseCases\GoogleCalendar\CallbackAction;
use App\UseCases\GoogleCalendar\ConnectAction;
use App\UseCases\GoogleCalendar\DisconnectAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class GoogleCalendarController extends Controller
{
    public function connect(Request $request, ConnectAction $action): RedirectResponse
    {
        try {
            return redirect()->away($action($request->user(), $request->session()));
        } catch (GoogleCalendarException) {
            return redirect()->route('settings.availability.index')->with('error', 'Google連携を開始できません。接続設定を確認してください。');
        }
    }

    public function callback(CallbackRequest $request, CallbackAction $action): RedirectResponse
    {
        try {
            $action($request->user(), $request->validated(), $request->session());
        } catch (GoogleCalendarException) {
            return redirect()->route('settings.availability.index')->with('error', 'Google連携に失敗しました。権限を確認し、もう一度連携してください。');
        }

        return redirect()->route('settings.availability.index')->with('success', 'Googleカレンダーと連携しました。');
    }

    public function destroy(Request $request, DisconnectAction $action): RedirectResponse
    {
        $action($request->user(), $request->session());

        return redirect()->route('settings.availability.index')->with('success', 'Googleカレンダーの連携を解除しました。');
    }
}

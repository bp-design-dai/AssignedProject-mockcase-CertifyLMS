<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Notification\IndexRequest;
use App\UseCases\Notification\IndexAction;
use App\UseCases\Notification\MarkAllAsReadAction;
use App\UseCases\Notification\MarkAsReadAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

final class NotificationController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        return view('notifications.index', $action($request->user(), $request->validated('tab') ?? 'all'));
    }

    public function markAsRead(Request $request, DatabaseNotification $notification, MarkAsReadAction $action): RedirectResponse
    {
        return redirect()->to($action($request->user(), $notification));
    }

    public function markAllAsRead(Request $request, MarkAllAsReadAction $action): RedirectResponse
    {
        $action($request->user());

        return redirect()->route('notifications.index')->with('success', 'すべての通知を既読にしました。');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\IndexRequest;
use App\UseCases\Api\Notification\IndexAction;
use App\UseCases\Api\Notification\MarkAllAsReadAction;
use App\UseCases\Api\Notification\MarkAsReadAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): JsonResponse
    {
        return response()->json($action($request->user(), $request->validated('tab') ?? 'all'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function markAsRead(Request $request, string $notification, MarkAsReadAction $action): JsonResponse
    {
        return response()->json($action($request->user(), $notification))
            ->header('Cache-Control', 'private, no-store');
    }

    public function markAllAsRead(Request $request, MarkAllAsReadAction $action): JsonResponse
    {
        return response()->json($action($request->user()))
            ->header('Cache-Control', 'private, no-store');
    }
}

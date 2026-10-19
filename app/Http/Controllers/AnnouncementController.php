<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Announcement\StoreRequest;
use App\Models\Announcement;
use App\UseCases\Announcement\CreateAction;
use App\UseCases\Announcement\IndexAction;
use App\UseCases\Announcement\ShowAction;
use App\UseCases\Announcement\StoreAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AnnouncementController extends Controller
{
    public function index(Request $request, IndexAction $action): View
    {
        return view('announcement.management.index', $action($request->user()));
    }

    public function create(Request $request, CreateAction $action): View
    {
        return view('announcement.management.create', $action($request->user()));
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        try {
            $announcement = $action($request->user(), $request->validated());
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return back()->withInput()->withErrors($exception->errors())->with('error', $exception->errors()['target_type'][0]);
        }

        return redirect()->route('admin.announcements.show', $announcement)->with('success', 'お知らせを配信しました。（成功'.$announcement->dispatched_count.'件／失敗'.$announcement->failed_count.'件）');
    }

    public function show(Request $request, Announcement $announcement, ShowAction $action): View
    {
        return view('announcement.management.show', $action($request->user(), $announcement));
    }
}

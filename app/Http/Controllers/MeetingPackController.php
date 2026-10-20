<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingPackStatus;
use App\Http\Requests\MeetingPack\IndexRequest;
use App\Http\Requests\MeetingPack\StoreRequest;
use App\Http\Requests\MeetingPack\UpdateRequest;
use App\Models\MeetingPack;
use App\UseCases\MeetingPack\ChangeStatusAction;
use App\UseCases\MeetingPack\DestroyAction;
use App\UseCases\MeetingPack\IndexAction;
use App\UseCases\MeetingPack\ShowAction;
use App\UseCases\MeetingPack\StoreAction;
use App\UseCases\MeetingPack\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MeetingPackController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $data = $request->validated();

        return view('meeting-pack.management.index', [
            'plans' => $action($data['keyword'] ?? null, isset($data['status']) ? MeetingPackStatus::from($data['status']) : null),
            'keyword' => $data['keyword'] ?? '',
            'status' => $data['status'] ?? '',
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', MeetingPack::class);

        return view('meeting-pack.management.create');
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $plan = $action($request->validated(), $request->user());

        return redirect()->route('admin.meeting-packs.show', $plan)->with('success', '面談パックを作成しました。');
    }

    public function show(MeetingPack $plan, ShowAction $action): View
    {
        $this->authorize('view', $plan);

        return view('meeting-pack.management.show', ['plan' => $action($plan)]);
    }

    public function edit(MeetingPack $plan): View
    {
        $this->authorize('update', $plan);

        return view('meeting-pack.management.edit', ['plan' => $plan]);
    }

    public function update(MeetingPack $plan, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($plan, $request->validated(), $request->user());

        return redirect()->route('admin.meeting-packs.show', $plan)->with('success', '面談パックを更新しました。');
    }

    public function destroy(MeetingPack $plan, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $plan);
        $action($plan);

        return redirect()->route('admin.meeting-packs.index')->with('success', '面談パックを削除しました。');
    }

    public function publish(MeetingPack $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('publish', $plan);
        $action($plan, MeetingPackStatus::Published, auth()->user());

        return redirect()->route('admin.meeting-packs.show', $plan)->with('success', '面談パックを公開しました。');
    }

    public function archive(MeetingPack $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('archive', $plan);
        $action($plan, MeetingPackStatus::Archived, auth()->user());

        return redirect()->route('admin.meeting-packs.show', $plan)->with('success', '面談パックをアーカイブしました。');
    }

    public function unarchive(MeetingPack $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('unarchive', $plan);
        $action($plan, MeetingPackStatus::Draft, auth()->user());

        return redirect()->route('admin.meeting-packs.show', $plan)->with('success', '面談パックを下書きに戻しました。');
    }
}

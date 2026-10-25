<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanStatus;
use App\Http\Requests\Plan\IndexRequest;
use App\Http\Requests\Plan\StoreRequest;
use App\Http\Requests\Plan\UpdateRequest;
use App\Models\Plan;
use App\UseCases\Plan\ChangeStatusAction;
use App\UseCases\Plan\DestroyAction;
use App\UseCases\Plan\IndexAction;
use App\UseCases\Plan\ShowAction;
use App\UseCases\Plan\StoreAction;
use App\UseCases\Plan\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $data = $request->validated();

        return view('plan.management.index', [
            'plans' => $action($data['keyword'] ?? null, isset($data['status']) ? PlanStatus::from($data['status']) : null),
            'keyword' => $data['keyword'] ?? '',
            'status' => $data['status'] ?? '',
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Plan::class);

        return view('plan.management.create');
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $plan = $action($request->validated(), $request->user());

        return redirect()->route('admin.plans.show', $plan)->with('success', 'プランを作成しました。');
    }

    public function show(Plan $plan, ShowAction $action): View
    {
        $this->authorize('view', $plan);

        return view('plan.management.show', ['plan' => $action($plan)]);
    }

    public function edit(Plan $plan): View
    {
        $this->authorize('update', $plan);

        return view('plan.management.edit', ['plan' => $plan]);
    }

    public function update(Plan $plan, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($plan, $request->validated(), $request->user());

        return redirect()->route('admin.plans.show', $plan)->with('success', 'プランを更新しました。');
    }

    public function destroy(Plan $plan, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $plan);
        $action($plan);

        return redirect()->route('admin.plans.index')->with('success', 'プランを削除しました。');
    }

    public function publish(Plan $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('publish', $plan);
        $action($plan, PlanStatus::Published, auth()->user());

        return redirect()->route('admin.plans.show', $plan)->with('success', 'プランを公開しました。');
    }

    public function archive(Plan $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('archive', $plan);
        $action($plan, PlanStatus::Archived, auth()->user());

        return redirect()->route('admin.plans.show', $plan)->with('success', 'プランをアーカイブしました。');
    }

    public function unarchive(Plan $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('unarchive', $plan);
        $action($plan, PlanStatus::Draft, auth()->user());

        return redirect()->route('admin.plans.show', $plan)->with('success', 'プランを下書きに戻しました。');
    }
}

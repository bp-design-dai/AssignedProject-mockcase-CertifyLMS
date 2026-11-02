<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Http\Requests\Meeting\AvailabilityRequest;
use App\Http\Requests\Meeting\IndexAsCoachRequest;
use App\Http\Requests\Meeting\IndexRequest;
use App\Http\Requests\Meeting\StoreRequest;
use App\Http\Requests\Meeting\UpsertMemoRequest;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\CancelAction;
use App\UseCases\Meeting\FetchAvailabilityAction;
use App\UseCases\Meeting\IndexAction;
use App\UseCases\Meeting\IndexAsCoachAction;
use App\UseCases\Meeting\ShowAction;
use App\UseCases\Meeting\StoreAction;
use App\UseCases\Meeting\UpsertMemoAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 面談の受付・認可委譲・Action呼び出し・レスポンス整形を行う。
 * 予約フォーム表示のcreate / createFallbackは既存実装を維持する。
 */
class MeetingController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        return view('meeting.index', $action($request->user(), $request->validated('filter')));
    }

    public function indexAsCoach(IndexAsCoachRequest $request, IndexAsCoachAction $action): View
    {
        return view('meeting.coach.index', $action($request->user(), $request->validated()));
    }

    public function show(Meeting $meeting, ShowAction $action): View
    {
        $this->authorize('view', $meeting);

        return view('meeting.show', ['meeting' => $action($meeting)]);
    }

    /**
     * 予約画面(受講生): URL に Enrollment を含む正規ルートで表示する。
     */
    public function create(Enrollment $enrollment, MeetingQuotaService $meetingQuota): View
    {
        $this->authorize('create', Meeting::class);

        abort_unless($enrollment->user_id === auth()->id(), 403);
        abort_unless($enrollment->status === EnrollmentStatus::Learning, 403);

        $enrollment->loadMissing('certification');

        return view('meeting.create', [
            'enrollment' => $enrollment,
            'meetingsRemaining' => $meetingQuota->remaining(auth()->user()),
        ]);
    }

    /**
     * 予約画面のエントリポイント(URL に Enrollment 無し)。
     * `resolve-default-enrollment` Middleware が default 資格に redirect するため、
     * 本 method に到達するのは default 未設定 + 残存 Enrollment が 0 件 or 2+ 件のケース。
     */
    public function createFallback(): View
    {
        $user = auth()->user();
        $enrollments = $user
            ?->enrollments()
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->with('certification')
            ->get();

        return view('meeting.empty-state', [
            'enrollments' => $enrollments ?? collect(),
        ]);
    }

    public function store(Enrollment $enrollment, StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $meeting = $action($enrollment, $request->validated());

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談を予約しました。');
    }

    public function cancel(Meeting $meeting, CancelAction $action): RedirectResponse
    {
        $this->authorize('cancel', $meeting);
        $action($meeting, auth()->user());

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談をキャンセルしました。面談回数を返却しました。');
    }

    public function upsertMemo(Meeting $meeting, UpsertMemoRequest $request, UpsertMemoAction $action): RedirectResponse
    {
        $action($meeting, $request->validated('body'));

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談メモを保存しました。');
    }

    public function fetchAvailability(Enrollment $enrollment, AvailabilityRequest $request, FetchAvailabilityAction $action): JsonResponse
    {
        $data = $action($enrollment, $request->validated('date'));

        return response()->json([
            'date' => $data['date']->toDateString(),
            'slots' => $data['slots']->map(fn (array $slot) => [
                'slot_start' => $slot['slot_start']->toIso8601String(),
                'slot_end' => $slot['slot_end']->toIso8601String(),
                'available_coach_count' => $slot['available_coach_count'],
            ])->all(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentNote\StoreRequest;
use App\Http\Requests\EnrollmentNote\UpdateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\UseCases\EnrollmentNote\DestroyAction;
use App\UseCases\EnrollmentNote\StoreAction;
use App\UseCases\EnrollmentNote\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EnrollmentNoteController extends Controller
{
    public function store(Enrollment $enrollment, StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $action($enrollment, $request->user(), $request->validated());

        return redirect()->route('enrollments.show', $enrollment)->with('success', 'メモを追加しました。');
    }

    public function edit(EnrollmentNote $note): View
    {
        $this->authorize('update', $note);

        return view('enrollment-note.edit', ['note' => $note]);
    }

    public function update(EnrollmentNote $note, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($note, $request->user(), $request->validated());

        return redirect()->route('enrollments.show', $note->enrollment_id)->with('success', 'メモを更新しました。');
    }

    public function destroy(EnrollmentNote $note, Request $request, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $note);
        $action($note, $request->user());

        return redirect()->route('enrollments.show', $note->enrollment_id)->with('success', 'メモを削除しました。');
    }
}

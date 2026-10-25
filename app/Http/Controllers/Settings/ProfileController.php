<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsProfile\UpdateRequest;
use App\UseCases\SettingsProfile\UpdateProfileAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $this->authorize('viewOwnProfile', $request->user());

        return view('settings.profile', ['user' => $request->user()]);
    }

    public function update(UpdateRequest $request, UpdateProfileAction $action): RedirectResponse
    {
        $action($request->user(), $request->validated());

        return redirect()->route('settings.profile.edit')->with('success', 'プロフィールを更新しました。');
    }
}

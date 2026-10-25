<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class PasswordController extends Controller
{
    public function update(Request $request, UpdatesUserPasswords $action): RedirectResponse
    {
        $this->authorize('updateOwnPassword', $request->user());

        try {
            $action->update($request->user(), $request->only([
                'current_password', 'password', 'password_confirmation',
            ]));
        } catch (ValidationException $e) {
            $e->redirectTo = route('settings.profile.edit', ['tab' => 'password']);

            throw $e;
        }

        return redirect()->route('settings.profile.edit', ['tab' => 'password'])
            ->with('success', 'パスワードを変更しました。');
    }
}

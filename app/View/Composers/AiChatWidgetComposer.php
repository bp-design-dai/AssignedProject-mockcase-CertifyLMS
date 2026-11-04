<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Services\AiChatContextService;
use Illuminate\Contracts\View\View;

final class AiChatWidgetComposer
{
    public function __construct(private readonly AiChatContextService $context) {}

    public function compose(View $view): void
    {
        $user = auth()->user();
        $visible = config('ai-chat.enabled', false) && $user?->role === UserRole::Student && $user?->status === UserStatus::InProgress && ! request()->routeIs('ai-chat.*');
        $data = $visible ? $this->context->fromPage($user, request()) : ['section' => null, 'enrollment' => null, 'explicit' => false];
        $view->with('aiChatVisible', $visible)->with('aiChatContext', $this->context->describe($data))->with('aiChatExplicitContext', $data['explicit']);
    }
}

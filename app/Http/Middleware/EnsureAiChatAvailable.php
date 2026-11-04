<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAiChatAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('ai-chat.enabled', false), 404);
        if (blank(config('ai-chat.gemini.api_key'))) {
            $message = 'AI相談は現在利用できません。接続設定が準備されるまでお待ちください。';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 503)
                : response()->view('ai-chat.unavailable', ['message' => $message], 503);
        }

        return $next($request);
    }
}

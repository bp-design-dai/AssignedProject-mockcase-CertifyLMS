{{--
    認証後の共通レイアウト。全ロール（受講生 / コーチ / 管理者）の画面がこれを継承する。
    構成: サイドバー（lg+ 固定 / lg 未満は drawer）→ TopBar → メインコンテンツ（@yield('content')）。
    body 直下に Flash トースト + AI 相談フローティングウィジェット（表示条件つき）を配置。
--}}
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        <meta name="auth-user-id" content="{{ auth()->id() }}">
    @endauth
    <title>@yield('title', config('app.name'))</title>

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface-canvas text-ink-900">
    <div class="lg:grid lg:grid-cols-[256px_1fr] min-h-screen">
        {{-- サイドバー: lg+ で固定 / lg 未満で drawer --}}
        <aside
            data-sidebar
            class="fixed lg:sticky lg:top-0 left-0 z-30 h-screen w-64 bg-surface-raised border-r border-subtle overflow-y-auto transform -translate-x-full lg:translate-x-0 transition-transform duration-normal ease-out-quint"
        >
            @auth
                @include('layouts._partials.sidebar-' . auth()->user()->role->value)
            @endauth
        </aside>

        {{-- モバイル: drawer 用バックドロップ --}}
        <div
            data-sidebar-backdrop
            class="hidden lg:hidden fixed inset-0 z-20 bg-ink-900/60 backdrop-blur-sm"
        ></div>

        <div class="flex flex-col min-w-0">
            @include('layouts._partials.topbar')

            <main class="flex-1">
                <div class="max-w-7xl mx-auto px-4 lg:px-8 py-6 lg:py-8">
                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    {{-- Toast: body 直下に置いて fixed 配置(コンテンツのレイアウトに影響しない) --}}
    <x-flash />

    {{-- AI専用Composerが、現在の教材→資格画面→最後に学習した資格の順で解決する。 --}}
    @if ($aiChatVisible ?? false)
        <x-ai-chat.floating-widget
            :section-id="$aiChatContext['section_id']"
            :section-title="$aiChatContext['section_title']"
            :certification-id="$aiChatContext['certification_id']"
            :certification-name="$aiChatContext['certification_name']"
            :explicit-context="$aiChatExplicitContext"
        />
    @endif

    @stack('scripts')
</body>
</html>

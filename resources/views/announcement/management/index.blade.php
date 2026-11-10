{{--
    管理者お知らせ配信履歴の一覧画面（管理者向け）。
    構成: ヘッダ（パンくず + 見出し + 新規配信ボタン）→ 配信履歴テーブル（0 件時は空状態）→ ページネーション。
    テーブルはタイトル（詳細リンク）/ 対象バッジ + 対象名 / 配信件数 / 配信日時 / 配信者。
    JS なし: 行クリックで詳細ページへ、新規配信ボタンで作成ページへリンク遷移。配信後の編集・取消は不可。
--}}
@extends('layouts.app')

@section('title', '管理者お知らせ')

@section('content')
    <div class="space-y-6">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <x-breadcrumb :items="[
                    ['label' => 'ダッシュボード', 'href' => route('dashboard.index')],
                    ['label' => '管理者お知らせ'],
                ]" />
                <h1 class="mt-2 text-2xl font-display font-bold text-ink-900">管理者お知らせ</h1>
                <p class="mt-1 text-sm text-ink-600">受講生集合にお知らせを配信します。配信後の編集・取消はできません。</p>
            </div>
            <x-link-button :href="route('admin.announcements.create')" variant="primary">+ 新規配信</x-link-button>
        </header>

        @if ($dispatches->isNotEmpty())
            <x-card padding="md" shadow="sm">
                <h2 class="font-semibold text-ink-900">最近の配信受付</h2>
                <p class="mt-1 text-xs text-ink-600">配信履歴は処理が完了し、1件以上の配信が成功した後に表示されます。更新して結果を確認してください。</p>
                @foreach ($dispatches as $dispatch)
                    <div class="mt-3 text-sm text-ink-700">
                        <span class="font-semibold">{{ $dispatch->payload['title'] }}</span>
                        <span class="ml-2">{{ ['queued' => '受付済み', 'processing' => '処理中', 'completed' => '処理完了', 'failed' => '配信失敗'][$dispatch->status] }}</span>
                        <span class="ml-2">成功 {{ $dispatch->succeeded_count }} 件 ／ 失敗 {{ $dispatch->failed_count }} 件</span>
                    </div>
                @endforeach
            </x-card>
        @endif

        <x-card padding="none" shadow="sm">
            @if ($announcements->isEmpty())
                <div class="px-6 py-12">
                    <x-empty-state
                        icon="megaphone"
                        title="まだ配信履歴がありません"
                        description="「+ 新規配信」から最初のお知らせを送ってみましょう。"
                    />
                </div>
            @else
                <x-table>
                    <x-slot:head>
                        <x-table.row>
                            <x-table.heading>タイトル</x-table.heading>
                            <x-table.heading>対象</x-table.heading>
                            <x-table.heading class="text-right">配信件数</x-table.heading>
                            <x-table.heading>配信日時</x-table.heading>
                            <x-table.heading>配信者</x-table.heading>
                        </x-table.row>
                    </x-slot:head>
                    @foreach ($announcements as $announcement)
                        <x-table.row>
                            <x-table.cell>
                                <a
                                    href="{{ route('admin.announcements.show', $announcement) }}"
                                    class="font-semibold text-primary-700 hover:underline"
                                >{{ $announcement->title }}</a>
                            </x-table.cell>
                            <x-table.cell>
                                <x-badge variant="info" size="sm">{{ $announcement->target_type->label() }}</x-badge>
                                @if ($announcement->targetCertification)
                                    <span class="ml-1 text-xs text-ink-600">{{ $announcement->targetCertification->name }}</span>
                                @elseif ($announcement->targetUser)
                                    <span class="ml-1 text-xs text-ink-600">{{ $announcement->targetUser->name }}</span>
                                @endif
                            </x-table.cell>
                            <x-table.cell class="text-right tnum">{{ $announcement->dispatched_count }}</x-table.cell>
                            <x-table.cell>{{ $announcement->dispatched_at?->format('Y/m/d H:i') ?? '—' }}</x-table.cell>
                            <x-table.cell>{{ $announcement->createdBy?->name ?? '—' }}</x-table.cell>
                        </x-table.row>
                    @endforeach
                </x-table>
            @endif
        </x-card>

        @if ($announcements->hasPages())
            <div class="flex justify-center">
                {{ $announcements->links() }}
            </div>
        @endif
    </div>
@endsection

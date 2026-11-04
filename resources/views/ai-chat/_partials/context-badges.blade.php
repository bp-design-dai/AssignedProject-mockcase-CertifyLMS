{{--
    会話ヘッダに出すコンテキストバッジ（教材セクション / 資格 / 全般のいずれか 1 つ）。
    構成: 📚 セクション名 → 🎓 資格名 → どちらも無ければ「全般相談」。
    JS なし（表示のみ）。
--}}
@php
    /** @var \App\Models\AiChatConversation $conversation */
    $sectionTitle = $conversationContext['section_title'];
    $certName = $conversationContext['certification_name'];
@endphp

<div class="flex items-center gap-2 flex-wrap text-[11px]">
    @if ($sectionTitle)
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-secondary-50 text-secondary-800 font-semibold">
            📚 {{ $sectionTitle }}
        </span>
    @endif
    @if ($certName)
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-primary-50 text-primary-800 font-semibold">
            🎓 {{ $certName }}
        </span>
    @endif
    @if (! $sectionTitle && ! $certName)
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-ink-50 text-ink-700 font-semibold">
            全般相談
        </span>
    @endif
</div>

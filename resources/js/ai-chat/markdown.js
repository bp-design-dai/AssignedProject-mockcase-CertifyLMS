import { Marked, Tokenizer } from 'marked';
import DOMPurify from 'dompurify';

/**
 * AI 相談メッセージの Markdown → サニタイズ済 HTML 変換。
 *
 * - 改行は <br> 化 (LLM が箇条書きの間に改行のみで段落を区切ることがあるため)
 * - GFM 有効 (テーブル / 取り消し線 / タスクリスト)
 * - HTML を含む解析結果は DOMPurify の許可リストでサニタイズする
 * - 受け取った内容が想定外でエラー化した場合は escape したプレーン文字列を返す
 */
const japanese = /[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}]/u;
const markdown = new Marked({
    gfm: true,
    breaks: true,
    tokenizer: {
        emStrong(src, maskedSrc, prevChar = '') {
            // Keep the normal tokenizer unless Japanese punctuation makes it skip a nearer closing pair.
            const standard = Tokenizer.prototype.emStrong.call(this, src, maskedSrc, prevChar);
            if (this.lexer.state.inRawBlock || !src.startsWith('**') || src.startsWith('***')) return standard || false;

            // Marked masks code, escaped punctuation and link destinations at identical offsets.
            const masked = maskedSrc.slice(-src.length);
            if (!masked.startsWith('**')) return standard || false;
            const end = masked.indexOf('**', 2);
            if (end < 0 || masked[end + 2] === '*') return standard || false;
            const text = src.slice(2, end);
            if (!text || /^(?:\s)|\s$|\n/u.test(text)) return standard || false;
            const next = [...src.slice(end + 2)][0] ?? '';
            const adjacentJapanesePunctuation =
                (japanese.test(prevChar) && /^\p{P}/u.test(text)) ||
                (/\p{P}$/u.test(text) && japanese.test(next));
            if (!adjacentJapanesePunctuation) return standard || false;

            if (standard && standard.raw.length <= end + 2) return standard;

            return { type: 'strong', raw: src.slice(0, end + 2), text, tokens: this.lexer.inlineTokens(text) };
        },
    },
});

function escapeHtml(str) {
    return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/**
 * Markdown 文字列をサニタイズ済 HTML に変換する。
 * 装飾的タグのみ許可し、`script` / `iframe` / イベントハンドラ属性等はすべて除去される。
 */
export function renderMarkdown(content) {
    if (content === null || content === undefined || content === '') return '';
    try {
        const html = markdown.parse(String(content));

        return DOMPurify.sanitize(html, {
            ALLOWED_TAGS: [
                'p', 'br', 'hr',
                'strong', 'b', 'em', 'i', 'del', 's', 'code', 'mark',
                'ul', 'ol', 'li',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'blockquote', 'pre',
                'a',
                'table', 'thead', 'tbody', 'tr', 'th', 'td',
            ],
            ALLOWED_ATTR: ['href', 'title', 'rel', 'target'],
            ALLOW_DATA_ATTR: false,
        });
    } catch (e) {
        return escapeHtml(content);
    }
}

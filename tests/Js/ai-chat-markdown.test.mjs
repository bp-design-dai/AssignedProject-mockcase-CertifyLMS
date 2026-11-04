import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { Marked } from 'marked';

const dom = new JSDOM('<!doctype html><html><body></body></html>');
globalThis.window = dom.window;
globalThis.document = dom.window.document;
const { renderMarkdown } = await import('../../resources/js/ai-chat/markdown.js');
const standard = new Marked({ gfm: true, breaks: true });
after(() => dom.window.close());
function render(source) {
    const root = document.createElement('div');
    root.innerHTML = renderMarkdown(source);
    return root;
}

for (const [source, expected] of [
    ['これは**用語（説明）**です。', '用語（説明）'],
    ['これは**「用語」**です。', '「用語」'],
    ['**「用語」**を確認します。', '「用語」'],
    ['この**（説明）** を確認します。', '（説明）'],
    ['この**【重要】**を確認します。', '【重要】'],
    ['この**API（補足）**を確認します。', 'API（補足）'],
    ['📚**用語（説明）**です。', '用語（説明）'],
    ['これは**用語（*補足*）**です。', '用語（補足）'],
    ['これは**用語（`**記号**`の説明）**です。', '用語（**記号**の説明）'],
    ['これは**用語\\*\\*（説明）**です。', '用語**（説明）'],
]) {
    test(`Japanese adjacent strong: ${source}`, () => {
        const root = render(source);
        assert.equal(root.querySelector('strong')?.textContent, expected);
        if (source.includes('*補足*')) assert.equal(root.querySelector('strong em')?.textContent, '補足');
        if (source.includes('`')) assert.equal(root.querySelector('strong code')?.textContent, '**記号**');
    });
}

test('multiple Japanese strong spans remain separate', () => {
    const root = render('**「先」**と**用語（後）**を確認。');
    assert.deepEqual([...root.querySelectorAll('strong')].map(e => e.textContent), ['「先」', '用語（後）']);
});

test('Japanese spans do not swallow later ordinary strong or emphasis', () => {
    const root = render('これは**用語（説明）**です。この**「重要」**を確認。通常の**太字**と*斜体*も維持。');
    assert.deepEqual([...root.querySelectorAll('strong')].map(e => e.textContent), ['用語（説明）', '「重要」', '太字']);
    assert.equal(root.querySelector('em')?.textContent, '斜体');
    assert.equal(root.textContent.trim(), 'これは用語（説明）です。この「重要」を確認。通常の太字と斜体も維持。');
});

for (const [name, source] of [
    ['ordinary strong and emphasis', 'これは**太字**と*斜体*、__別の太字__と_斜体_です。'],
    ['multiline standard strong', '**先頭\n続き** と ***強調***'],
    ['ASCII punctuation rules', 'a**(word)**b と **(word)** b'],
    ['unclosed and space-delimited stars', '**未終了 と ** 空白 ** です。'],
    ['escaped delimiters', '\\*\\*用語（説明）\\*\\*です。'],
    ['escaped opening', '\\**用語（説明）**です。'],
    ['escaped closing', '**用語（説明）\\**です。'],
    ['inline code', '`**用語（説明）**です。`'],
    ['double-backtick code', '`` **用語（説明）**です。 `記号` ``'],
    ['fenced code', '```markdown\n**用語（説明）**です。\n```'],
    ['tilde fenced code', '~~~markdown\n**用語（説明）**です。\n~~~'],
    ['indented code', '    **用語（説明）**です。'],
    ['links and destinations', '[通常のリンク](https://example.test/path**stars**) と https://example.test/a**b**'],
    ['headings lists tables', '# 見出し\n\n- **太字**\n- *斜体*\n\n|列|値|\n|---|---|\n|項目|内容|'],
]) {
    test(`existing Markdown unchanged: ${name}`, () => {
        // The existing sanitizer already removes fenced-code language classes.
        assert.equal(renderMarkdown(source), standard.parse(source).replace(/ class="language-[^"]*"/g, ''));
    });
}

test('strong in link text does not alter its destination', () => {
    const root = render('[**用語（説明）**です](https://example.test/path**stars**)');
    assert.equal(root.querySelector('a strong')?.textContent, '用語（説明）');
    assert.equal(root.querySelector('a')?.getAttribute('href'), 'https://example.test/path**stars**');
});

test('Japanese strong parsing keeps the sanitizer effective', () => {
    const root = render('これは**用語（説明）**です。<script>alert(1)</script><img src=x onerror="alert(1)"><iframe src="javascript:alert(1)"></iframe><a href="javascript:alert(1)" onclick="alert(1)">リンク</a>');
    assert.equal(root.querySelector('strong')?.textContent, '用語（説明）');
    assert.equal(root.querySelectorAll('script,img,iframe,[onclick],[onerror],[href^="javascript:"]').length, 0);
});

test('HTML inside a compatibility strong span is still sanitized', () => {
    const root = render('これは**用語<img src=x onerror="alert(1)">（説明）**です。');
    assert.equal(root.querySelector('strong')?.textContent, '用語（説明）');
    assert.equal(root.querySelectorAll('img,[onerror]').length, 0);
});

test('null and empty values remain empty', () => {
    for (const value of [null, undefined, '']) assert.equal(renderMarkdown(value), '');
});

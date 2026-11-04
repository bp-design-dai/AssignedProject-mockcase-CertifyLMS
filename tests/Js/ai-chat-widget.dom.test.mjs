import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', { url: 'http://localhost:8013/dashboard' });
globalThis.window = dom.window;
globalThis.document = dom.window.document;
globalThis.sessionStorage = dom.window.sessionStorage;
const { initAiChatWidget } = await import('../../resources/js/ai-chat/floating-widget.js');
const { renderFullScreenMessage } = await import('../../resources/js/ai-chat/message-renderer.js');
const { rememberConversation } = await import('../../resources/js/ai-chat/widget-state.js');
const blade = readFileSync(new URL('../../resources/views/ai-chat/show.blade.php', import.meta.url), 'utf8');
const template = blade.match(/<template data-ai-chat-message-template>[\s\S]*?<\/template>/)[0];
const cssPath = new URL('../../resources/css/ai-chat-widget.css', import.meta.url);
const content = 'これは**用語（説明）**です。\n\n## 見出し\n- **太字**と*斜体*\n\n```text\n' + 'long_code_'.repeat(30) + '\n**コード内の記号**\n```\n\n|列|値|\n|---|---|\n|'+ 'A'.repeat(80)+'|'+ 'B'.repeat(80)+'|';
after(() => dom.window.close());
async function settled(predicate) {
    for (let i = 0; i < 30 && !predicate(); i++) await new Promise(setImmediate);
    assert.ok(predicate(), 'production widget must finish its mocked HTTP work');
}
async function mount(messages = [{ role: 'user', status: 'completed', content: '**本人の入力**' }, { role: 'assistant', status: 'completed', content }], post = null) {
    window.sessionStorage.clear();
    rememberConversation(window.sessionStorage, 'student', 'saved-conversation', { section_id: null, certification_id: null });
    document.head.innerHTML = '<meta name="csrf-token" content="fake-csrf">';
    const style = document.createElement('style');
    style.textContent = '[data-row]{display:flex} pre{white-space:pre;overflow-x:auto}'+(existsSync(cssPath) ? readFileSync(cssPath, 'utf8') : '');
    document.head.appendChild(style);
    document.body.innerHTML = `<div data-ai-chat-widget data-user-id="student" data-available="1" data-store-url="/ai-chat/conversations" data-fullscreen-base-url="/ai-chat/conversations">
        <button data-ai-chat-fab>開く</button><div data-ai-chat-panel class="hidden" inert><button data-ai-chat-close>閉じる</button><a data-ai-chat-fullscreen>全画面</a>
        <div data-ai-chat-messages><div data-ai-chat-welcome>案内</div></div><textarea data-ai-chat-widget-input></textarea><button data-ai-chat-widget-send>送信</button></div></div>${template}<ul id="full-screen"></ul><div id="other-page" class="prose"><pre>other code</pre><table><tbody><tr><td>other table</td></tr></tbody></table></div>`;
    let posts = 0;
    globalThis.fetch = async (url, options = {}) => {
        if (options.method === 'POST') {
            posts++;
            assert.equal(url, '/ai-chat/conversations/saved-conversation/messages');
            return post ? post(posts, JSON.parse(options.body)) : new Response('{}', { status: 500 });
        }
        assert.equal(url, '/ai-chat/conversations/saved-conversation');
        return new Response(JSON.stringify({ conversation: { id: 'saved-conversation', context: {} }, messages }));
    };
    initAiChatWidget();
    const list = document.querySelector('[data-ai-chat-messages]');
    await settled(() => messages.length ? list.querySelector('.prose') !== null : list.querySelector('[data-ai-chat-welcome]') !== null);
    return { list, input: document.querySelector('textarea'), send: document.querySelector('[data-ai-chat-widget-send]'), posts: () => posts };
}

test('saved history and full-screen rendering agree on Japanese strong and code literals', async () => {
    const ui = await mount();
    const node = renderFullScreenMessage(document.querySelector('#full-screen'), { id: 'saved', role: 'assistant', status: 'completed', content });
    assert.equal(ui.list.querySelector('.prose strong')?.textContent, '用語（説明）');
    assert.equal(node.querySelector('strong')?.textContent, '用語（説明）');
    assert.equal(ui.list.querySelector('pre code').textContent, node.querySelector('pre code').textContent);
    assert.match(ui.list.querySelector('pre').textContent, /\*\*コード内の記号\*\*/);
    assert.equal(ui.list.querySelectorAll('h2,ul,table').length, 3);
    assert.equal(ui.posts(), 0);
});

test('saved widget body can shrink and normal text can wrap independently of code', async () => {
    const ui = await mount();
    const body = ui.list.querySelector('.prose').parentElement.parentElement;
    const css = window.getComputedStyle(body);
    assert.equal(Number.parseFloat(css.minWidth), 0);
    assert.equal(css.maxWidth, '100%');
    assert.equal(css.overflowWrap, 'anywhere');
    assert.equal(window.getComputedStyle(ui.list).overflowX, 'hidden');
});

test('wide code and tables have bounded local scrolling', async () => {
    const ui = await mount();
    const pre = window.getComputedStyle(ui.list.querySelector('pre'));
    const table = window.getComputedStyle(ui.list.querySelector('table'));
    assert.equal(pre.whiteSpace, 'pre');
    assert.equal(pre.overflowX, 'auto');
    assert.equal(pre.maxWidth, '100%');
    assert.equal(table.display, 'block');
    assert.equal(table.overflowX, 'auto');
    assert.equal(table.maxWidth, '100%');
    assert.notEqual(window.getComputedStyle(document.querySelector('#other-page table')).display, 'block');
    assert.notEqual(window.getComputedStyle(document.querySelector('#other-page pre')).maxWidth, '100%');
});

test('user text remains literal and shares the bounded wrapper', async () => {
    const ui = await mount();
    const user = ui.list.firstElementChild;
    assert.equal(user.querySelector('strong'), null);
    assert.match(user.textContent, /\*\*本人の入力\*\*/);
    const body = user.lastElementChild;
    assert.equal(Number.parseFloat(window.getComputedStyle(body).minWidth), 0);
    assert.equal(window.getComputedStyle(body).overflowWrap, 'anywhere');
});

test('new synchronous answers use the same parser and wrapping as restored answers', async () => {
    const ui = await mount([], () => new Response(JSON.stringify({ assistant_message: { role: 'assistant', status: 'completed', content } })));
    ui.input.value = '検証用質問';
    ui.send.click();
    await settled(() => ui.posts() === 1 && !ui.send.disabled && ui.list.querySelector('.prose'));
    assert.equal(ui.list.querySelector('.prose strong')?.textContent, '用語（説明）');
    assert.equal(Number.parseFloat(window.getComputedStyle(ui.list.querySelector('.prose').parentElement.parentElement).minWidth), 0);
    assert.equal(ui.list.querySelector('[data-typing]'), null);
});

test('error text stays escaped and retry succeeds without changing the question', async () => {
    const ui = await mount([], (count, request) => {
        assert.equal(request.content, '同じ検証用質問');
        return count === 1 ? new Response(JSON.stringify({ message: '<img src=x onerror="alert(1)">失敗' }), { status: 502 }) : new Response(JSON.stringify({ assistant_message: { role: 'assistant', status: 'completed', content } }));
    });
    ui.input.value = '同じ検証用質問';
    ui.send.click();
    await settled(() => ui.posts() === 1 && !ui.send.disabled && [...ui.list.querySelectorAll('button')].some(b => b.textContent === '同じ質問を再送'));
    assert.equal(ui.list.querySelectorAll('img,[onerror]').length, 0);
    assert.match(ui.list.textContent, /<img/);
    [...ui.list.querySelectorAll('button')].find(b => b.textContent === '同じ質問を再送').click();
    await settled(() => ui.posts() === 2 && !ui.send.disabled && ui.list.querySelector('.prose'));
    assert.equal(ui.list.querySelector('.prose strong')?.textContent, '用語（説明）');
});

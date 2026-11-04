import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', { url: 'http://localhost:8012/ai-chat/conversations/test-conversation' });
globalThis.window = dom.window;
globalThis.document = dom.window.document;
globalThis.sessionStorage = dom.window.sessionStorage;
const { initAiChatFullScreen } = await import('../../resources/js/ai-chat/full-screen.js');
const blade = readFileSync(new URL('../../resources/views/ai-chat/show.blade.php', import.meta.url), 'utf8');
const template = blade.match(/<template data-ai-chat-message-template>[\s\S]*?<\/template>/)[0];
after(() => dom.window.close());

function mount({ title = '新しい相談', auto = true, messages = 0, id = 'test-conversation' } = {}) {
    document.head.innerHTML = '<meta name="csrf-token" content="fake-csrf">';
    document.title = `${title} | Certify LMS`;
    document.body.innerHTML = `
        <div data-conversation-title></div>
        <form id="rename-ai-chat-form"><input name="title"><input type="hidden" name="auto_title" value="0"><input type="checkbox" name="auto_title" value="1"></form>
        <div data-message-scroller>${messages ? '' : '<div data-ai-chat-empty-state>まだメッセージはありません。</div>'}<ul data-message-list>${'<li data-saved-message>保存済み</li>'.repeat(messages)}</ul></div>
        <form data-ai-chat-form data-user-id="student" data-conversation-id="${id}" data-store-url="/ai-chat/conversations/${id}/messages"><textarea data-ai-chat-textarea></textarea><button data-ai-chat-submit>送信</button></form>
        ${template}`;
    const heading = document.querySelector('[data-conversation-title]');
    heading.textContent = title;
    const rename = document.getElementById('rename-ai-chat-form');
    rename.querySelector('[name="title"]').value = title;
    rename.querySelector('[type="checkbox"]').checked = auto;
    // Preserve normal form serialization without navigating outside this DOM test.
    rename.addEventListener('submit', event => event.preventDefault());
    initAiChatFullScreen();
    return { heading, rename, title: rename.querySelector('[name="title"]'), auto: rename.querySelector('[type="checkbox"]'), form: document.querySelector('[data-ai-chat-form]'), list: document.querySelector('[data-message-list]'), submit: document.querySelector('[data-ai-chat-submit]') };
}
function edit(field, value, event = 'input') {
    if (field.type === 'checkbox') field.checked = value;
    else field.value = value;
    field.dispatchEvent(new dom.window.Event(event, { bubbles: true }));
}
function serialize(ui) {
    ui.rename.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    const data = new dom.window.FormData(ui.rename);
    return { title: data.get('title'), auto: data.getAll('auto_title').at(-1) };
}
function start(ui, content = '最初の質問') {
    let finish;
    globalThis.fetch = (url, options) => {
        assert.equal(url, ui.form.dataset.storeUrl);
        assert.equal(JSON.parse(options.body).content, content);
        return new Promise(resolve => { finish = resolve; });
    };
    ui.form.querySelector('textarea').value = content;
    ui.form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    assert.ok(finish, 'production submit handler must reach the mocked HTTP boundary');
    return async ({ title = 'AI生成タイトル', auto = true, status = 200, answer = 'AI回答' } = {}) => {
        finish(new Response(JSON.stringify({ conversation: { id: ui.form.dataset.conversationId, title, auto_title: auto }, user_message: { role: 'user', content }, assistant_message: { id: 'answer', role: 'assistant', status: status === 200 ? 'completed' : 'error', content: answer, created_at: '2030-06-10T09:00:00Z' } }), { status, headers: { 'Content-Type': 'application/json' } }));
        for (let i = 0; i < 20 && ui.submit.disabled; i++) await new Promise(setImmediate);
        assert.equal(ui.submit.disabled, false, 'send must finish');
    };
}

test('AI title updates both the heading and unchanged edit field; untouched save omits title', async () => {
    const ui = mount();
    await start(ui)();
    assert.equal(ui.heading.textContent, 'AI生成タイトル');
    assert.equal(document.title, 'AI生成タイトル | Certify LMS');
    assert.equal(ui.title.value, 'AI生成タイトル');
    assert.equal(ui.auto.checked, true);
    assert.deepEqual(serialize(ui), { title: null, auto: '1' });
});

test('a later automatic title update stays synchronized without a page reload', async () => {
    const ui = mount({ title: '前回AIタイトル', messages: 10 });
    await start(ui)({ title: '5質問後のタイトル' });
    assert.equal(ui.title.value, '5質問後のタイトル');
    assert.deepEqual(serialize(ui), { title: null, auto: '1' });
});

test('manual title input survives an incoming AI title and is submitted as a manual change', async () => {
    const ui = mount();
    const finish = start(ui);
    edit(ui.title, '自分のタイトル');
    await finish();
    assert.equal(ui.title.value, '自分のタイトル');
    assert.deepEqual(serialize(ui), { title: '自分のタイトル', auto: '1' });
});

test('explicit OFF survives the response while the unchanged title follows the latest server value', async () => {
    const ui = mount();
    const finish = start(ui);
    edit(ui.auto, false, 'change');
    await finish();
    assert.equal(ui.title.value, 'AI生成タイトル');
    assert.equal(ui.auto.checked, false);
    assert.deepEqual(serialize(ui), { title: null, auto: '0' });
});

test('explicit ON survives a response with the previous OFF state', async () => {
    const ui = mount({ title: '手動タイトル', auto: false });
    const finish = start(ui);
    edit(ui.auto, true, 'change');
    await finish({ title: '手動タイトル', auto: false });
    assert.equal(ui.auto.checked, true);
    assert.deepEqual(serialize(ui), { title: null, auto: '1' });
});

test('no-change OFF save and reloaded ON/OFF settings preserve server state', () => {
    for (const auto of [false, true]) {
        const ui = mount({ title: '保存されたタイトル', auto, messages: 2 });
        assert.equal(ui.title.value, '保存されたタイトル');
        assert.equal(ui.auto.checked, auto);
        assert.deepEqual(serialize(ui), { title: null, auto: auto ? '1' : '0' });
    }
});

test('title edits that are returned to the latest value are not sent as manual changes', async () => {
    const ui = mount();
    await start(ui)();
    edit(ui.title, '一時的な編集');
    edit(ui.title, 'AI生成タイトル');
    assert.deepEqual(serialize(ui), { title: null, auto: '1' });
});

test('empty notice disappears as soon as the first question is rendered and stays absent after success', async () => {
    const ui = mount();
    assert.ok(document.querySelector('[data-ai-chat-empty-state]'));
    const finish = start(ui);
    assert.equal(document.querySelector('[data-ai-chat-empty-state]'), null);
    assert.match(ui.list.textContent, /最初の質問/);
    await finish();
    assert.equal(document.querySelector('[data-ai-chat-empty-state]'), null);
    assert.match(ui.list.textContent, /AI回答/);
    assert.equal(ui.list.children.length, 2);
});

test('failed AI response leaves the saved question and error without empty notice', async () => {
    const ui = mount();
    await start(ui)({ status: 502, title: '新しい相談', answer: '質問は保存しました。再送できます。' });
    assert.equal(document.querySelector('[data-ai-chat-empty-state]'), null);
    assert.match(ui.list.textContent, /最初の質問/);
    assert.match(ui.list.textContent, /質問は保存しました/);
    assert.equal(ui.list.querySelector('[data-ai-chat-retry]').textContent, '同じ質問を再送');
    assert.equal(ui.list.children.length, 2);
});

test('reload and switching between saved and empty conversations rebuild the correct state', async () => {
    await start(mount())();
    assert.equal(document.querySelector('[data-ai-chat-empty-state]'), null);
    mount({ title: '保存されたタイトル', messages: 2 });
    assert.equal(document.querySelector('[data-ai-chat-empty-state]'), null);
    mount({ id: 'another-empty-conversation' });
    assert.ok(document.querySelector('[data-ai-chat-empty-state]'));
    mount({ id: 'question-only-conversation', messages: 1 });
    assert.equal(document.querySelector('[data-ai-chat-empty-state]'), null);
});

test('an error response also synchronizes canonical title and keeps explicit form edits', async () => {
    const ui = mount({ title: '古い表示' });
    const finish = start(ui);
    edit(ui.auto, false, 'change');
    await finish({ status: 502, title: 'サーバーの最新タイトル', answer: '応答失敗' });
    assert.equal(ui.title.value, 'サーバーの最新タイトル');
    assert.deepEqual(serialize(ui), { title: null, auto: '0' });
});

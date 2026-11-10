import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { initNotificationPopovers, internalNotificationUrl } from '../../resources/js/notifications/popover.js';
import { notificationRequest } from '../../resources/js/notifications/api.js';

const partial = readFileSync(new URL('../../resources/views/notifications/_partials/notification-popover.blade.php', import.meta.url), 'utf8');

function mount(count = 2) {
    const dom = new JSDOM(`<!doctype html><meta name="csrf-token" content="initial-token"><div data-notification-popover-root data-index-url="/api/v1/notifications" data-read-all-url="/api/v1/notifications/read-all" data-read-url="/api/v1/notifications/__notification__/read"><button data-notification-popover-trigger aria-expanded="false"></button><span data-notification-popover-badge class="${count ? '' : 'hidden'}">${count > 99 ? '99+' : count}</span>${partial}</div>`, { url: 'http://localhost:8014/settings/profile' });
    globalThis.window = dom.window;
    globalThis.document = dom.window.document;
    const moved = [];
    initNotificationPopovers({ navigate: url => moved.push(url) });
    return { dom, moved, find: selector => document.querySelector(`[data-notification-popover-${selector}]`) };
}

const notice = (id = 'mine', isRead = false) => ({ id, title: '通知', preview: 'お知らせ', created_at_human: '1分前', is_read: isRead, url: '/notifications' });
const response = (data, status = 200) => new Response(status === 204 ? null : JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
async function settled(ui) {
    for (let i = 0; i < 40 && ui.find('panel').getAttribute('aria-busy') === 'true'; i++) await new Promise(setImmediate);
    assert.equal(ui.find('panel').getAttribute('aria-busy'), 'false');
}
async function open(ui, notifications = [notice()], count = 2) {
    globalThis.fetch = async () => response({ notifications, unread_count: count });
    ui.find('trigger').click();
    await settled(ui);
}

test('bell loads latest notifications without navigation and tabs fetch their own results', async t => {
    const ui = mount(); t.after(() => ui.dom.window.close());
    const calls = [];
    globalThis.fetch = async (url, options) => { calls.push([url, options]); return response({ notifications: [notice()], unread_count: 2 }); };
    ui.find('trigger').click(); await settled(ui);
    assert.equal(ui.find('trigger').getAttribute('aria-expanded'), 'true');
    assert.equal(ui.find('panel').style.display, 'flex');
    assert.equal(ui.find('items').children.length, 1);
    assert.equal(ui.find('row').dataset.unread, 'true');
    assert.deepEqual(ui.moved, []);
    document.querySelector('[data-notification-popover-tab="unread"]').click(); await settled(ui);
    assert.deepEqual(calls.map(call => call[0]), ['/api/v1/notifications?tab=all', '/api/v1/notifications?tab=unread']);
    assert.equal(calls[0][1].credentials, 'same-origin');
    assert.equal(calls[0][1].cache, 'no-store');
    assert.equal(document.querySelector('[data-notification-popover-tab="unread"]').getAttribute('aria-selected'), 'true');
    ui.find('trigger').dispatchEvent(new ui.dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(ui.find('trigger').getAttribute('aria-expanded'), 'false');
});

for (const count of [0, 1, 99, 100]) test(`badge updates only after successful response for ${count}`, async t => {
    const ui = mount(7); t.after(() => ui.dom.window.close());
    await open(ui, count ? [notice()] : [], count);
    assert.equal(ui.find('badge').textContent, count > 99 ? '99+' : String(count));
    assert.equal(ui.find('badge').classList.contains('hidden'), count === 0);
    assert.equal(ui.find('empty').classList.contains('hidden'), count !== 0);
});

test('titles and preview are text and cannot inject executable HTML', async t => {
    const ui = mount(); t.after(() => ui.dom.window.close());
    await open(ui, [{ ...notice(), title: '<img src=x onerror=alert(1)>', preview: '<script>alert(2)</script>' }]);
    assert.equal(ui.find('items').querySelector('img,script'), null);
    assert.equal(ui.find('row-title').textContent, '<img src=x onerror=alert(1)>');
    assert.equal(ui.find('row-message').textContent, '<script>alert(2)</script>');
});

for (const isRead of [false, true]) test(`read ${isRead ? 'existing' : 'unread'} notification then navigate after success`, async t => {
    const ui = mount(); t.after(() => ui.dom.window.close());
    await open(ui, [notice('mine', isRead)]);
    let finish;
    const calls = [];
    globalThis.fetch = (url, options) => { calls.push([url, options.method]); return new Promise(resolve => { finish = resolve; }); };
    ui.find('row').click(); ui.find('row').click();
    assert.deepEqual(ui.moved, []);
    assert.equal(ui.find('badge').textContent, '2');
    assert.deepEqual(calls, [['/api/v1/notifications/mine/read', 'POST']]);
    finish(response({ unread_count: 1, url: '/notifications/mine' })); await settled(ui);
    assert.deepEqual(ui.moved, ['/notifications/mine']);
    assert.equal(ui.find('badge').textContent, '1');
});

test('read failure keeps badge and page, retry completes the same operation', async t => {
    const ui = mount(); t.after(() => ui.dom.window.close()); await open(ui);
    globalThis.fetch = async () => response({}, 503);
    ui.find('row').click(); await settled(ui);
    assert.equal(ui.find('error').classList.contains('hidden'), false);
    assert.equal(ui.find('badge').textContent, '2'); assert.deepEqual(ui.moved, []);
    globalThis.fetch = async () => response({ unread_count: 1, url: '/notifications' });
    ui.find('retry').click(); await settled(ui); assert.deepEqual(ui.moved, ['/notifications']);
});

test('read all clears badge only on success and empties unread tab', async t => {
    const ui = mount(); t.after(() => ui.dom.window.close()); await open(ui);
    document.querySelector('[data-notification-popover-tab="unread"]').click(); await settled(ui);
    globalThis.fetch = async () => response({}, 500); ui.find('mark-all').click(); await settled(ui);
    assert.equal(ui.find('badge').textContent, '2'); assert.equal(ui.find('items').children.length, 1);
    globalThis.fetch = async () => response({ unread_count: 0 }); ui.find('retry').click(); await settled(ui);
    assert.equal(ui.find('badge').classList.contains('hidden'), true);
    assert.equal(ui.find('items').children.length, 0); assert.equal(ui.find('empty').classList.contains('hidden'), false);
});

test('failed tab loads preserve selection, rows and badge', async t => {
    const ui = mount(); t.after(() => ui.dom.window.close()); await open(ui);
    globalThis.fetch = async () => { throw new TypeError('network unavailable'); };
    document.querySelector('[data-notification-popover-tab="unread"]').click(); await settled(ui);
    assert.equal(document.querySelector('[data-notification-popover-tab="all"]').getAttribute('aria-selected'), 'true');
    assert.equal(ui.find('badge').textContent, '2'); assert.equal(ui.find('items').children.length, 1);
    assert.match(ui.find('error-text').textContent, /再試行/);
});

test('session expiration offers login and does not change existing data', async t => {
    const ui = mount(); t.after(() => ui.dom.window.close()); await open(ui);
    globalThis.fetch = async () => response({}, 401); ui.find('row').click(); await settled(ui);
    assert.equal(ui.find('login').classList.contains('hidden'), false); assert.match(ui.find('error-text').textContent, /再ログイン/);
    assert.equal(ui.find('badge').textContent, '2'); assert.deepEqual(ui.moved, []);
});

test('419 refreshes cookie and retries with the new encrypted XSRF token exactly once', async t => {
    const ui = mount(); t.after(() => ui.dom.window.close()); document.cookie = 'XSRF-TOKEN=old%3D';
    const calls = [];
    globalThis.fetch = async (url, options) => {
        calls.push({ url, headers: options.headers });
        if (url === '/sanctum/csrf-cookie') { document.cookie = 'XSRF-TOKEN=new%3D'; return response(null, 204); }
        return calls.length === 1 ? response({}, 419) : response({ unread_count: 0 });
    };
    await notificationRequest('/api/v1/notifications/read-all', 'POST');
    assert.equal(calls.length, 3); assert.equal(calls[0].headers['X-XSRF-TOKEN'], 'old=');
    assert.equal(calls[2].headers['X-XSRF-TOKEN'], 'new='); assert.equal(calls[2].headers['X-CSRF-TOKEN'], undefined);
});

test('repeated 419 stops after one retry and reports the error', async t => {
    const ui = mount(); t.after(() => ui.dom.window.close()); let calls = 0;
    globalThis.fetch = async url => { calls++; return response({}, url === '/sanctum/csrf-cookie' ? 204 : 419); };
    await assert.rejects(notificationRequest('/api/v1/notifications/read-all', 'POST'), error => error.status === 419);
    assert.equal(calls, 3);
});

test('failed CSRF refresh never repeats a write', async t => {
    const ui = mount(); t.after(() => ui.dom.window.close()); let calls = 0;
    globalThis.fetch = async () => response({}, ++calls === 1 ? 419 : 503);
    await assert.rejects(notificationRequest('/api/v1/notifications/read-all', 'POST'), error => error.status === 503);
    assert.equal(calls, 2);
});

test('unsafe destinations never navigate or change badge', async t => {
    const ui = mount(); t.after(() => ui.dom.window.close()); await open(ui);
    for (const value of ['https://evil.example', '//evil.example/x', '/\\evil.example/x', 'javascript:alert(1)', null]) {
        assert.equal(internalNotificationUrl(value), null);
    }
    assert.equal(internalNotificationUrl('/chat/room?tab=all#last'), '/chat/room?tab=all#last');
    globalThis.fetch = async () => response({ unread_count: 0, url: '//evil.example' });
    ui.find('row').click(); await settled(ui); assert.deepEqual(ui.moved, []); assert.equal(ui.find('badge').textContent, '2');
});

test('duplicate bell opens while loading do not send duplicate requests', async t => {
    const ui = mount(); t.after(() => ui.dom.window.close()); let finish; let calls = 0;
    globalThis.fetch = () => { calls++; return new Promise(resolve => { finish = resolve; }); };
    ui.find('trigger').click(); ui.find('trigger').click(); ui.find('trigger').click(); ui.find('mark-all').click();
    assert.equal(calls, 1); finish(response({ notifications: [], unread_count: 0 })); await settled(ui);
    assert.equal(ui.find('empty').classList.contains('hidden'), false);
});

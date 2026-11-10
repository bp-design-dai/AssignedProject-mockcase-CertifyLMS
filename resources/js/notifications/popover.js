import { notificationRequest } from './api.js';

export function internalNotificationUrl(value) {
    if (typeof value !== 'string' || !value.startsWith('/')) return null;
    const url = new URL(value, window.location.origin);
    return url.origin === window.location.origin ? url.pathname + url.search + url.hash : null;
}

export function initNotificationPopovers({ navigate = url => window.location.assign(url) } = {}) {
    document.querySelectorAll('[data-notification-popover-root]').forEach(root => {
        if (root.dataset.initialized) return;
        root.dataset.initialized = 'true';
        const trigger = root.querySelector('[data-notification-popover-trigger]');
        const panel = root.querySelector('[data-notification-popover-panel]');
        const badge = root.querySelector('[data-notification-popover-badge]');
        const unreadCount = root.querySelector('[data-notification-popover-unread-count]');
        const items = root.querySelector('[data-notification-popover-items]');
        const empty = root.querySelector('[data-notification-popover-empty]');
        const loading = root.querySelector('[data-notification-popover-loading]');
        const error = root.querySelector('[data-notification-popover-error]');
        const errorText = root.querySelector('[data-notification-popover-error-text]');
        const login = root.querySelector('[data-notification-popover-login]');
        const markAll = root.querySelector('[data-notification-popover-mark-all]');
        const tabs = [...root.querySelectorAll('[data-notification-popover-tab]')];
        let tab = 'all';
        let busy = false;
        let retry;

        function updateCount(count) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.classList.toggle('hidden', count === 0);
            unreadCount.textContent = String(count);
            trigger.setAttribute('aria-label', `通知 (${count} 件未読)`);
        }

        function setBusy(value) {
            busy = value;
            panel.setAttribute('aria-busy', String(value));
            loading.classList.toggle('hidden', !value);
            root.querySelectorAll('[data-notification-popover-tab], [data-notification-popover-mark-all], [data-notification-popover-retry]').forEach(button => { button.disabled = value; });
        }

        async function run(operation) {
            if (busy) return;
            retry = operation;
            setBusy(true);
            error.classList.add('hidden');
            try {
                await operation();
            } catch (failure) {
                const messages = {
                    401: 'ログインの有効期限が切れました。再ログインしてください。',
                    403: 'この通知の操作は許可されていません。',
                    404: '通知または移動先が見つかりません。',
                    419: '認証の確認に失敗しました。ページを再読み込みして再試行してください。',
                };
                errorText.textContent = messages[failure.status] ?? '通知を処理できませんでした。通信状況を確認して再試行してください。';
                login.classList.toggle('hidden', failure.status !== 401);
                error.classList.remove('hidden');
            } finally {
                setBusy(false);
            }
        }

        function render(notifications) {
            items.replaceChildren();
            empty.classList.toggle('hidden', notifications.length !== 0);
            for (const notification of notifications) {
                const row = root.querySelector('[data-notification-popover-row-template]').content.cloneNode(true);
                const link = row.querySelector('[data-notification-popover-row]');
                link.href = internalNotificationUrl(notification.url) ?? '#';
                link.dataset.unread = String(!notification.is_read);
                link.classList.toggle('bg-primary-50/30', !notification.is_read);
                row.querySelector('[data-notification-popover-row-dot]').classList.toggle('invisible', notification.is_read);
                row.querySelector('[data-notification-popover-row-title]').textContent = notification.title;
                row.querySelector('[data-notification-popover-row-message]').textContent = notification.preview;
                row.querySelector('[data-notification-popover-row-time]').textContent = notification.created_at_human;
                link.addEventListener('click', event => {
                    event.preventDefault();
                    run(async () => {
                        const url = root.dataset.readUrl.replace('__notification__', encodeURIComponent(notification.id));
                        const result = await notificationRequest(url, 'POST');
                        const destination = internalNotificationUrl(result.url);
                        if (!destination) throw new Error('Invalid notification destination');
                        updateCount(result.unread_count);
                        navigate(destination);
                    });
                });
                items.append(row);
            }
        }

        function load(nextTab) {
            return run(async () => {
                const result = await notificationRequest(`${root.dataset.indexUrl}?tab=${nextTab}`);
                render(result.notifications);
                updateCount(result.unread_count);
                tab = nextTab;
                tabs.forEach(button => button.setAttribute('aria-selected', String(button.dataset.notificationPopoverTab === tab)));
            });
        }

        function close() {
            panel.classList.add('hidden', 'opacity-0', '-translate-y-1');
            panel.style.display = 'none';
            trigger.setAttribute('aria-expanded', 'false');
        }

        trigger.addEventListener('click', () => {
            if (trigger.getAttribute('aria-expanded') === 'true') {
                close();
                return;
            }
            panel.classList.remove('hidden', 'opacity-0', '-translate-y-1');
            panel.style.display = 'flex';
            trigger.setAttribute('aria-expanded', 'true');
            load(tab);
        });
        tabs.forEach(button => button.addEventListener('click', () => load(button.dataset.notificationPopoverTab)));
        markAll.addEventListener('click', () => run(async () => {
            const result = await notificationRequest(root.dataset.readAllUrl, 'POST');
            updateCount(result.unread_count);
            if (tab === 'unread') render([]);
            else {
                items.querySelectorAll('[data-notification-popover-row]').forEach(link => {
                    link.dataset.unread = 'false';
                    link.classList.remove('bg-primary-50/30');
                });
                items.querySelectorAll('[data-notification-popover-row-dot]').forEach(dot => dot.classList.add('invisible'));
            }
        }));
        root.querySelector('[data-notification-popover-retry]').addEventListener('click', () => { if (retry) run(retry); });
        document.addEventListener('click', event => { if (!root.contains(event.target)) close(); });
        root.addEventListener('keydown', event => { if (event.key === 'Escape') { close(); trigger.focus(); } });
    });
}

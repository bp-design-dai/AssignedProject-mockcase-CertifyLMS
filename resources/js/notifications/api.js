function tokenHeaders() {
    const cookie = document.cookie.split(';').map(part => part.trim()).find(part => part.startsWith('XSRF-TOKEN='));
    if (cookie) return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) };
    return { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' };
}

export async function notificationRequest(url, method = 'GET') {
    for (let attempt = 0; attempt < 2; attempt++) {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...tokenHeaders() },
        });
        if (response.status === 419 && attempt === 0) {
            const refresh = await fetch('/sanctum/csrf-cookie', {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
            });
            if (!refresh.ok) throw Object.assign(new Error('CSRF refresh failed'), { status: refresh.status });
            continue;
        }
        if (!response.ok) throw Object.assign(new Error('Notification request failed'), { status: response.status });
        return response.json();
    }
}

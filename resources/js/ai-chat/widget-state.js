/** State belongs to the current learner and the current browser tab. */
export function widgetKeys(userId) {
    const prefix = `certify.aichat.${userId}`;
    return { open: `${prefix}.open`, conversation: `${prefix}.conversation`, section: `${prefix}.section`, certification: `${prefix}.certification` };
}

export function forgetConversation(storage, userId) {
    try {
        const keys = widgetKeys(userId);
        [keys.conversation, keys.section, keys.certification].forEach(key => storage.removeItem(key));
    } catch (_) {}
}

export function rememberConversation(storage, userId, id, context = {}) {
    try {
        const keys = widgetKeys(userId);
        storage.setItem(keys.conversation, id);
        storage.setItem(keys.section, context.section_id || '');
        storage.setItem(keys.certification, context.certification_id || '');
    } catch (_) {}
}

export function restoreConversation(storage, userId, context = {}) {
    try {
        const keys = widgetKeys(userId);
        const id = storage.getItem(keys.conversation);
        if (!id) return null;
        const differentSection = context.section_id && storage.getItem(keys.section) !== context.section_id;
        const differentCertification = context.certification_id && storage.getItem(keys.certification) !== context.certification_id;
        if (context.explicit && (differentSection || differentCertification)) {
            forgetConversation(storage, userId);
            return null;
        }
        return id;
    } catch (_) { return null; }
}

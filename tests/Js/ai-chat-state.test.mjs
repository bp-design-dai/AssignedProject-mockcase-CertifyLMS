import test from 'node:test';
import assert from 'node:assert/strict';
import { widgetKeys, rememberConversation, restoreConversation } from '../../resources/js/ai-chat/widget-state.js';

function storage() {
    const data = new Map();
    return { getItem: key => data.get(key) ?? null, setItem: (key, value) => data.set(key, value), removeItem: key => data.delete(key) };
}
test('same tab preserves open state and selected section conversation on a general page', () => {
    const store = storage();
    const keys = widgetKeys('student1');
    store.setItem(keys.open, '1');
    rememberConversation(store, 'student1', 'conversationA', { section_id: 'sectionA', certification_id: 'certA' });
    assert.equal(restoreConversation(store, 'student1', { explicit: false }), 'conversationA');
    assert.equal(store.getItem(keys.open), '1');
    assert.equal(restoreConversation(store, 'student1', { section_id: 'sectionA', certification_id: 'certA', explicit: true }), 'conversationA');
});
test('different section and explicitly different certification select a new conversation', () => {
    const store = storage();
    rememberConversation(store, 'student1', 'conversationA', { section_id: 'sectionA', certification_id: 'certA' });
    assert.equal(restoreConversation(store, 'student1', { section_id: 'sectionB', explicit: true }), null);
    rememberConversation(store, 'student1', 'conversationA', { certification_id: 'certA' });
    assert.equal(restoreConversation(store, 'student1', { certification_id: 'certB', explicit: true }), null);
});
test('another learner and another tab do not inherit conversation or open state', () => {
    const firstTab = storage();
    rememberConversation(firstTab, 'student1', 'privateConversation');
    firstTab.setItem(widgetKeys('student1').open, '1');
    assert.equal(restoreConversation(firstTab, 'student2'), null);
    assert.equal(firstTab.getItem(widgetKeys('student2').open), null);
    assert.equal(restoreConversation(storage(), 'student1'), null);
});
test('restricted browser storage does not prevent using the chat', () => {
    const denied = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } };
    assert.equal(restoreConversation(denied, 'student1'), null);
    assert.doesNotThrow(() => rememberConversation(denied, 'student1', 'conversationA'));
});

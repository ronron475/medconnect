'use strict';

const { createSoapDraftSaver } = require('../../public/assets/js/soap-draft-autosave.js');

let passed = 0;
let failed = 0;

function assert(ok, label) {
    if (ok) {
        passed += 1;
        console.log('PASS  ' + label);
        return;
    }
    failed += 1;
    console.log('FAIL  ' + label);
}

function harness(options) {
    const timers = [];
    let value = options.initial || '';
    const posts = [];
    const statuses = [];
    const saver = createSoapDraftSaver({
        delayMs: 2000,
        finalized: !!options.finalized,
        initialSnapshot: value,
        current: function () { return value; },
        setTimer: function (fn) {
            const item = { fn: fn, cleared: false };
            timers.push(item);
            return timers.length;
        },
        clearTimer: function (id) {
            if (timers[id - 1]) timers[id - 1].cleared = true;
        },
        post: function (payload) {
            const item = { payload: payload };
            posts.push(item);
            return new Promise(function (resolve, reject) {
                item.resolve = resolve;
                item.reject = reject;
            });
        },
        onStatus: function (state, extra) {
            statuses.push({ state: state, extra: extra || '' });
        },
    });
    return {
        saver: saver,
        posts: posts,
        statuses: statuses,
        set: function (next) { value = next; },
        fire: function () {
            const due = timers.filter(function (item) { return !item.cleared && !item.ran; });
            due.forEach(function (item) {
                item.ran = true;
                item.fn();
            });
        },
    };
}

async function tick() {
    await Promise.resolve();
    await Promise.resolve();
}

(async function () {
    const debounce = harness({ initial: '' });
    debounce.set('first');
    debounce.saver.noteEdited();
    debounce.set('latest');
    debounce.saver.noteEdited();
    assert(debounce.posts.length === 0, 'typing does not save before the debounce period');
    debounce.fire();
    await tick();
    assert(debounce.posts.length === 1 && debounce.posts[0].payload === 'latest', 'one autosave sends the latest edit');
    assert(debounce.statuses.some(function (item) { return item.state === 'unsaved'; }), 'the indicator shows unsaved changes');
    assert(debounce.statuses.some(function (item) { return item.state === 'saving'; }), 'the indicator shows saving');
    debounce.posts[0].resolve({ success: true, saved_at: '2026-10-19 01:45:00', finalized: false });
    await tick();
    assert(debounce.statuses[debounce.statuses.length - 1].state === 'saved', 'the indicator shows a successful draft save');
    assert(debounce.statuses[debounce.statuses.length - 1].extra === '2026-10-19 01:45:00', 'the successful save keeps the server timestamp');
    assert(debounce.saver.shouldWarn() === false, 'a successful save does not leave a leave-page warning');

    const stale = harness({ initial: 'saved' });
    stale.set('older');
    stale.saver.noteEdited();
    stale.fire();
    await tick();
    stale.set('newer');
    stale.saver.noteEdited();
    stale.posts[0].resolve({ success: true, saved_at: '2026-10-19 01:46:00', finalized: false });
    await tick();
    assert(stale.saver.status() === 'unsaved', 'an older response does not mark newer edits as saved');
    assert(stale.posts.length === 1, 'a newer edit waits for its own debounce instead of a second in-flight save');
    stale.fire();
    await tick();
    assert(stale.posts.length === 2 && stale.posts[1].payload === 'newer', 'the follow-up save contains the latest values');
    stale.posts[1].resolve({ success: true, saved_at: '2026-10-19 01:47:00', finalized: false });
    await tick();
    assert(stale.saver.shouldWarn() === false, 'the warning clears after the latest save succeeds');

    const failure = harness({ initial: '' });
    failure.set('keep');
    const failedSave = failure.saver.save(true);
    await tick();
    failure.posts[0].reject(new Error('network'));
    await failedSave;
    assert(failure.saver.status() === 'failed', 'a network failure shows the retry state');
    assert(failure.saver.shouldWarn() === true, 'a failed save still warns before leaving');
    const retried = failure.saver.save(true);
    await tick();
    assert(failure.posts.length === 2 && failure.posts[1].payload === 'keep', 'retry sends the unsaved draft again');
    failure.posts[1].resolve({ success: true, saved_at: '2026-10-19 01:48:00', finalized: false });
    await retried;
    assert(failure.saver.status() === 'saved', 'a retried save can succeed');

    const locked = harness({ initial: 'done', finalized: true });
    locked.set('changed');
    locked.saver.noteEdited();
    locked.fire();
    await locked.saver.save(true);
    assert(locked.posts.length === 0, 'a finalized note is not autosaved or edited');

    const queued = harness({ initial: '' });
    queued.set('one');
    const first = queued.saver.save(true);
    await tick();
    queued.set('two');
    const second = queued.saver.save(true);
    await second;
    assert(second, 'a repeated save while one is in flight is accepted');
    queued.posts[0].resolve({ success: true, saved_at: '2026-10-19 01:49:00', finalized: false });
    await tick();
    assert(queued.posts.length === 2 && queued.posts[1].payload === 'two', 'an in-flight save is followed by the newest manual save');
    queued.posts[1].resolve({ success: true, saved_at: '2026-10-19 01:50:00', finalized: false });
    await first;
    assert(queued.saver.shouldWarn() === false, 'the queued manual save clears the warning after it succeeds');

    console.log('\n' + passed + ' passed, ' + failed + ' failed');
    process.exit(failed > 0 ? 1 : 0);
})();

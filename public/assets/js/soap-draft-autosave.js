(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    root.MedconnectSoapDraft = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function createSoapDraftSaver(deps) {
        var delay = deps.delayMs || 2000;
        var timer = 0;
        var inFlight = false;
        var queued = false;
        var manualQueued = false;
        var held = false;
        var finalized = !!deps.finalized;
        var saved = String(deps.initialSnapshot || '');
        var status = finalized ? 'finalized' : 'idle';
        var idleWaiters = [];

        function current() {
            return String(deps.current());
        }

        function dirty() {
            return current() !== saved;
        }

        function setStatus(next, extra) {
            status = next;
            if (typeof deps.onStatus === 'function') {
                deps.onStatus(next, extra || '');
            }
        }

        function clearTimer() {
            if (!timer) {
                return;
            }
            deps.clearTimer(timer);
            timer = 0;
        }

        function settleIdle() {
            if (inFlight) {
                return;
            }
            var waiters = idleWaiters;
            idleWaiters = [];
            waiters.forEach(function (fn) { fn(); });
        }

        function noteEdited() {
            if (finalized || held || !dirty()) {
                return;
            }
            setStatus('unsaved');
            clearTimer();
            timer = deps.setTimer(function () {
                timer = 0;
                save(false);
            }, delay);
        }

        function save(manual) {
            if (finalized) {
                return Promise.resolve({ success: false, finalized: true });
            }
            if (held) {
                return Promise.resolve({ success: false, held: true });
            }
            if (!manual && !dirty()) {
                return Promise.resolve({ success: true, skipped: true });
            }
            if (inFlight) {
                queued = true;
                if (manual) {
                    manualQueued = true;
                }
                return Promise.resolve({ success: false, pending: true });
            }
            clearTimer();
            var payload = current();
            inFlight = true;
            queued = false;
            setStatus('saving');
            return Promise.resolve()
                .then(function () { return deps.post(payload); })
                .then(function (data) { return finish(data, payload); })
                .catch(function () { return finish({ success: false }, payload); });
        }

        function finish(data, payload) {
            inFlight = false;
            var now = current();
            if (data && data.finalized === true && data.success === false) {
                finalized = true;
                clearTimer();
                setStatus('finalized');
                manualQueued = false;
                queued = false;
                settleIdle();
                return data;
            }
            if (data && data.success && now === payload) {
                saved = payload;
                setStatus('saved', data.saved_at || data.savedAt || '');
            } else if (data && data.success) {
                saved = payload;
                if (!held) {
                    setStatus('unsaved');
                }
            } else if (!held) {
                setStatus('failed');
            }
            var followManual = manualQueued;
            var follow = !held && !finalized && (followManual || (queued && dirty() && !timer));
            manualQueued = false;
            queued = false;
            if (follow) {
                return save(followManual);
            }
            settleIdle();
            return data;
        }

        function shouldWarn() {
            return !finalized && (dirty() || inFlight);
        }

        function hold() {
            held = true;
            clearTimer();
        }

        function release() {
            held = false;
        }

        function whenIdle() {
            if (!inFlight) {
                return Promise.resolve();
            }
            return new Promise(function (resolve) {
                idleWaiters.push(resolve);
            });
        }

        return {
            noteEdited: noteEdited,
            save: save,
            shouldWarn: shouldWarn,
            hold: hold,
            release: release,
            whenIdle: whenIdle,
            status: function () { return status; },
        };
    }

    return { createSoapDraftSaver: createSoapDraftSaver };
});

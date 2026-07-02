/**
 * MedConnect — reusable draggable floating control (pointer + touch).
 * Used by Sign-In help FAB; role-agnostic (patient, provider, admin, BHW, superadmin).
 */
(function (global) {
  'use strict';

  function clamp(value, min, max) {
    return Math.max(min, Math.min(value, max));
  }

  function getViewportBounds() {
    const vv = global.visualViewport;
    if (!vv) {
      return {
        left: 0,
        top: 0,
        width: global.innerWidth,
        height: global.innerHeight,
      };
    }
    return {
      left: vv.offsetLeft,
      top: vv.offsetTop,
      width: vv.width,
      height: vv.height,
    };
  }

  /**
   * @param {object} options
   * @param {HTMLElement} options.handle - Element receiving pointer events (usually the button).
   * @param {HTMLElement} options.wrap - Positioned fixed wrapper moved during drag.
   * @param {string} [options.storageKey] - localStorage key for persisted position.
   * @param {number} [options.margin=8] - Minimum distance from viewport edges.
   * @param {number} [options.threshold=8] - Pixels before a gesture counts as drag.
   * @param {number} [options.clickSuppressMs=320] - Block ghost clicks after drag.
   * @param {() => boolean} [options.enabled] - Return false to ignore interaction.
   * @param {(ev: PointerEvent) => void} [options.onTap] - Called on tap/click without drag.
   * @param {string} [options.dragBodyClass] - Body class while dragging (scroll lock).
   */
  function init(options) {
    const handle = options.handle;
    const wrap = options.wrap;
    if (!handle || !wrap) {
      return { destroy() {}, reClamp() {}, restorePosition() {} };
    }

    const storageKey = options.storageKey || null;
    const margin = options.margin ?? 8;
    const threshold = options.threshold ?? 8;
    const clickSuppressMs = options.clickSuppressMs ?? 320;
    const enabled = typeof options.enabled === 'function' ? options.enabled : () => true;
    const onTap = typeof options.onTap === 'function' ? options.onTap : null;
    const dragBodyClass = options.dragBodyClass || '';

    let isDragging = false;
    let didDrag = false;
    let activePointerId = null;
    let dragStartX = 0;
    let dragStartY = 0;
    let grabOffsetX = 0;
    let grabOffsetY = 0;
    let pendingX = 0;
    let pendingY = 0;
    let rafId = null;
    let suppressClickUntil = 0;
    let blockTouchScrollHandler = null;

    function clampPosition(left, top) {
      const vp = getViewportBounds();
      const width = wrap.offsetWidth || handle.offsetWidth;
      const height = wrap.offsetHeight || handle.offsetHeight;
      const minLeft = vp.left + margin;
      const minTop = vp.top + margin;
      const maxLeft = vp.left + vp.width - width - margin;
      const maxTop = vp.top + vp.height - height - margin;
      return {
        left: clamp(left, minLeft, maxLeft),
        top: clamp(top, minTop, maxTop),
      };
    }

    function applyPosition(left, top) {
      const pos = clampPosition(left, top);
      wrap.style.left = `${pos.left}px`;
      wrap.style.top = `${pos.top}px`;
      wrap.style.right = 'auto';
      wrap.style.bottom = 'auto';
      wrap.style.transform = 'none';
      wrap.dataset.customPos = 'true';
    }

    function flushPosition() {
      rafId = null;
      applyPosition(pendingX, pendingY);
    }

    function schedulePosition(left, top) {
      pendingX = left;
      pendingY = top;
      if (rafId !== null) return;
      rafId = global.requestAnimationFrame(flushPosition);
    }

    function savePosition() {
      if (!storageKey) return;
      const rect = wrap.getBoundingClientRect();
      try {
        global.localStorage.setItem(storageKey, JSON.stringify({
          left: Math.round(rect.left),
          top: Math.round(rect.top),
        }));
      } catch (_) {
        /* ignore storage errors */
      }
    }

    function restorePosition() {
      if (!storageKey) return;
      try {
        const raw = global.localStorage.getItem(storageKey);
        if (!raw) return;
        const saved = JSON.parse(raw);
        if (typeof saved.left === 'number' && typeof saved.top === 'number') {
          applyPosition(saved.left, saved.top);
        }
      } catch (_) {
        /* ignore storage errors */
      }
    }

    function reClamp() {
      if (wrap.dataset.customPos !== 'true') return;
      const rect = wrap.getBoundingClientRect();
      applyPosition(rect.left, rect.top);
    }

    function enableScrollLock() {
      if (dragBodyClass) document.body.classList.add(dragBodyClass);
      if (blockTouchScrollHandler) return;
      blockTouchScrollHandler = (e) => {
        if (isDragging) e.preventDefault();
      };
      document.addEventListener('touchmove', blockTouchScrollHandler, { passive: false });
    }

    function disableScrollLock() {
      if (dragBodyClass) document.body.classList.remove(dragBodyClass);
      if (!blockTouchScrollHandler) return;
      document.removeEventListener('touchmove', blockTouchScrollHandler);
      blockTouchScrollHandler = null;
    }

    function beginDragVisuals() {
      handle.classList.add('is-dragging');
      wrap.classList.add('is-dragging');
    }

    function endDragVisuals() {
      handle.classList.remove('is-dragging');
      wrap.classList.remove('is-dragging');
      disableScrollLock();
    }

    function onPointerDown(e) {
      if (!enabled()) return;
      if (e.pointerType === 'mouse' && e.button !== 0) return;

      activePointerId = e.pointerId;
      isDragging = false;
      didDrag = false;
      dragStartX = e.clientX;
      dragStartY = e.clientY;

      const rect = wrap.getBoundingClientRect();
      grabOffsetX = e.clientX - rect.left;
      grabOffsetY = e.clientY - rect.top;

      handle.setPointerCapture(e.pointerId);
    }

    function onPointerMove(e) {
      if (activePointerId !== e.pointerId) return;

      const dx = e.clientX - dragStartX;
      const dy = e.clientY - dragStartY;

      if (!isDragging && (Math.abs(dx) > threshold || Math.abs(dy) > threshold)) {
        isDragging = true;
        didDrag = true;
        beginDragVisuals();
        enableScrollLock();
      }

      if (!isDragging) return;

      e.preventDefault();
      schedulePosition(e.clientX - grabOffsetX, e.clientY - grabOffsetY);
    }

    function onPointerEnd(e) {
      if (activePointerId !== e.pointerId) return;

      if (handle.hasPointerCapture(e.pointerId)) {
        handle.releasePointerCapture(e.pointerId);
      }

      if (rafId !== null) {
        global.cancelAnimationFrame(rafId);
        rafId = null;
        flushPosition();
      }

      if (isDragging) {
        endDragVisuals();
        savePosition();
        suppressClickUntil = Date.now() + clickSuppressMs;
        e.preventDefault();
      } else {
        disableScrollLock();
        if (!didDrag && onTap) onTap(e);
      }

      activePointerId = null;
      isDragging = false;
    }

    function onClick(e) {
      if (Date.now() < suppressClickUntil || isDragging) {
        e.preventDefault();
        e.stopImmediatePropagation();
      }
    }

    function onViewportChange() {
      reClamp();
    }

    handle.addEventListener('pointerdown', onPointerDown);
    handle.addEventListener('pointermove', onPointerMove);
    handle.addEventListener('pointerup', onPointerEnd);
    handle.addEventListener('pointercancel', onPointerEnd);
    handle.addEventListener('click', onClick, true);
    global.addEventListener('resize', onViewportChange);
    global.addEventListener('orientationchange', () => {
      global.setTimeout(onViewportChange, 100);
    });

    if (global.visualViewport) {
      global.visualViewport.addEventListener('resize', onViewportChange);
      global.visualViewport.addEventListener('scroll', onViewportChange);
    }

    restorePosition();

    function destroy() {
      handle.removeEventListener('pointerdown', onPointerDown);
      handle.removeEventListener('pointermove', onPointerMove);
      handle.removeEventListener('pointerup', onPointerEnd);
      handle.removeEventListener('pointercancel', onPointerEnd);
      handle.removeEventListener('click', onClick, true);
      global.removeEventListener('resize', onViewportChange);
      if (global.visualViewport) {
        global.visualViewport.removeEventListener('resize', onViewportChange);
        global.visualViewport.removeEventListener('scroll', onViewportChange);
      }
      disableScrollLock();
      endDragVisuals();
    }

    return { destroy, reClamp, restorePosition };
  }

  global.MedConnectDraggableFab = { init };
})(window);

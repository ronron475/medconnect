/**
 * medConnect — Dynamic fixed-header offset
 *
 * Ensures page content always starts below fixed headers on all devices.
 * This prevents dashboard cards from sliding under the header (stacking/overlap),
 * especially when the header wraps on small screens or safe-area insets apply.
 */
(function () {
  const CSS_VAR = "--mc-header-offset";

  function getHeaderEl() {
    // Portal header (patient/admin/bhw/superadmin)
    const topbar = document.querySelector("header.topbar");
    if (topbar) return topbar;
    // Provider header
    const pd = document.querySelector("header.pd-header");
    if (pd) return pd;
    return null;
  }

  function setOffsetPx(px) {
    const safe = Number.isFinite(px) ? Math.max(0, Math.round(px)) : 0;
    document.documentElement.style.setProperty(CSS_VAR, `${safe}px`);
  }

  function measureAndApply() {
    const header = getHeaderEl();
    if (!header) return;

    // getBoundingClientRect is resilient even when position:fixed.
    const rect = header.getBoundingClientRect();
    // height already includes padding-top safe-area applied in CSS.
    const h = rect.height || header.offsetHeight || 0;
    setOffsetPx(h);
  }

  function init() {
    measureAndApply();

    const header = getHeaderEl();
    if (!header) return;

    // Re-measure when header size changes (wrapping, font loading, menu changes).
    if ("ResizeObserver" in window) {
      const ro = new ResizeObserver(() => measureAndApply());
      ro.observe(header);
    }

    // Re-measure on viewport changes (mobile browser UI show/hide, rotation).
    window.addEventListener("resize", measureAndApply, { passive: true });
    window.addEventListener("orientationchange", measureAndApply, { passive: true });

    // iOS Safari / Chrome Android: visualViewport changes as toolbars collapse/expand.
    if (window.visualViewport) {
      window.visualViewport.addEventListener("resize", measureAndApply, { passive: true });
      window.visualViewport.addEventListener("scroll", measureAndApply, { passive: true });
    }

    // Late recalcs for font/layout settling.
    setTimeout(measureAndApply, 50);
    setTimeout(measureAndApply, 250);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();


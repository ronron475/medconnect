(function () {
  'use strict';
  function boot() {
    if (window.McChartTheme) {
      McChartTheme.mountWeeklyBarChartsFromDom();
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

<?php
/** Collapsible smart search — right side of premium navbar */
if (!isset($landing_search_announcements)) {
    return;
}
?>
<div class="nav-search-wrap" id="nav-search-wrap">
  <button
    type="button"
    class="nav-search-toggle"
    id="nav-search-toggle"
    aria-expanded="false"
    aria-controls="nav-search-panel"
    aria-label="Open search"
  >
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
    <span class="nav-search-toggle__label">Search</span>
  </button>

  <div class="nav-search-panel" id="nav-search-panel" role="search" aria-label="Search medConnect" hidden>
    <form class="hero-search__form" id="hero-search-form" action="#" method="get" autocomplete="off">
      <label class="visually-hidden" for="hero-search-input">Search medConnect services, health topics, and announcements</label>
      <div class="hero-search__combo">
        <div class="hero-search__field">
          <svg class="hero-search__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          <input
            type="search"
            id="hero-search-input"
            class="hero-search__input"
            name="q"
            role="combobox"
            aria-expanded="false"
            aria-controls="hero-search-results"
            aria-autocomplete="list"
            placeholder="Search services, symptoms, consultations, health topics, or announcements..."
            autocomplete="off"
            enterkeyhint="search"
          />
        </div>
        <div
          id="hero-search-results"
          class="hero-search__dropdown"
          role="listbox"
          aria-label="Search suggestions"
          hidden
        ></div>
      </div>
    </form>
  </div>
</div>

<script type="application/json" id="hero-search-index"><?php require __DIR__ . '/hero_search_index.php'; ?></script>

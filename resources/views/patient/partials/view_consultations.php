<?php /** My Sessions — consultation table (populated by patient-portal.js). */ ?>
<h2 class="text-h2 mb-md">Consultation Management</h2>
<div class="tab-filters">
  <button type="button" class="tab-btn active" onclick="filterSessions('upcoming')">Upcoming Sessions</button>
  <button type="button" class="tab-btn" onclick="filterSessions('past')">Past Sessions</button>
</div>
<div class="mc-card" style="padding: 0; overflow: hidden;">
  <div class="mc-table-wrap">
    <table class="mc-table">
      <thead><tr><th>Doctor Name</th><th>Specialty</th><th>Date &amp; Time</th><th>Status</th><th>Action</th></tr></thead>
      <tbody id="sessions-tbody"></tbody>
    </table>
  </div>
</div>

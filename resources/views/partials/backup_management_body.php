<?php
declare(strict_types=1);

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
}
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/superadmin/backup.php';

$backups = superadmin_list_backups($pdo);
$status = superadmin_backup_status_summary($pdo);
$settings = $status['settings'];
$latest = $status['latest'];
$lastAuto = $status['last_auto'];
$cronPath = BASE_PATH . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'backup_scheduled.php';
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isProductionHost = $host === 'medconnect.bccbsis.com' || str_ends_with($host, '.bccbsis.com');
$cronDisplayPath = $isProductionHost
    ? str_replace('\\', '/', $cronPath)
    : '/home/USER/domains/medconnect.bccbsis.com/public_html/scripts/cron/backup_scheduled.php';
$weekdayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

if (!function_exists('backup_format_filesize')) {
    function backup_format_filesize(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return number_format($bytes / 1048576, 2) . ' MB';
    }
}

if (!function_exists('backup_mgmt_status_badge')) {
    function backup_mgmt_status_badge(string $status): string
    {
        $s = strtolower($status);
        $cls = $s === 'success' ? 'badge-success' : ($s === 'failed' ? 'badge-danger' : 'badge-pending');
        return '<span class="backup-pill-badge ' . $cls . '">' . strtoupper(htmlspecialchars($status)) . '</span>';
    }
}
?>

<style>
.backup-mgmt-container {
  font-family: var(--mc-font, 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif);
  color: var(--text-primary, #0f172a);
  width: 100%;
  box-sizing: border-box;
}

/* Page Intro Header */
.backup-header-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 24px;
  flex-wrap: wrap;
}
.backup-page-title {
  font-size: 24px;
  font-weight: 800;
  color: #0f172a;
  margin: 0 0 6px;
  letter-spacing: -0.02em;
}
.backup-page-sub {
  font-size: 13px;
  color: #64748b;
  margin: 0;
  line-height: 1.5;
}
.backup-btn-teal {
  background: #0d9488 !important;
  border: 1px solid #0d9488 !important;
  color: #ffffff !important;
  font-size: 13px;
  font-weight: 700;
  padding: 9px 18px;
  border-radius: 6px;
  cursor: pointer;
  box-shadow: 0 1px 2px rgba(0,0,0,0.06);
  transition: all 0.15s ease;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
}
.backup-btn-teal:hover {
  background: #0f766e !important;
  border-color: #0f766e !important;
}

/* 3 Stat Cards Row */
.backup-stats-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 22px;
}
.backup-stat-box {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 18px 20px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.02);
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  min-height: 94px;
  box-sizing: border-box;
}
.backup-stat-title {
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #94a3b8;
  margin-bottom: 8px;
}
.backup-stat-val {
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
  word-break: break-all;
  line-height: 1.35;
}
.backup-stat-subtext {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  color: #64748b;
  margin-top: 6px;
  flex-wrap: wrap;
}

/* Two-column Layout */
.backup-content-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 300px;
  gap: 20px;
  align-items: start;
}
.backup-col-left {
  display: flex;
  flex-direction: column;
  gap: 20px;
  min-width: 0;
}
.backup-col-right {
  min-width: 0;
}

/* Shared Card Box */
.backup-ui-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 20px 22px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.02);
  box-sizing: border-box;
}
.backup-ui-card-title {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
  margin: 0 0 18px;
}

/* Automatic Backup Settings Grid */
.backup-settings-row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 1.2fr auto;
  gap: 12px;
  align-items: flex-end;
}
.backup-field-wrap {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.backup-field-label {
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #64748b;
}
.backup-select,
.backup-input {
  font-size: 12px;
  padding: 7px 10px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #ffffff;
  color: #0f172a;
  height: 38px;
  box-sizing: border-box;
  outline: none;
  width: 100%;
}
.backup-select:focus,
.backup-input:focus {
  border-color: #0d9488;
  box-shadow: 0 0 0 2px rgba(13,148,136,0.15);
}
.backup-save-btn {
  background: #0d9488;
  border: 1px solid #0d9488;
  color: #ffffff;
  font-size: 12px;
  font-weight: 700;
  padding: 0 22px;
  border-radius: 6px;
  height: 38px;
  cursor: pointer;
  box-shadow: 0 1px 2px rgba(0,0,0,0.05);
  transition: all 0.15s ease;
  white-space: nowrap;
}
.backup-save-btn:hover {
  background: #0f766e;
  border-color: #0f766e;
}

/* Hostinger Cron Box */
.backup-cron-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px;
  margin-top: 18px;
}
.backup-cron-title {
  font-size: 13px;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 4px;
}
.backup-cron-desc {
  font-size: 11px;
  color: #64748b;
  line-height: 1.45;
}
.backup-terminal-code {
  background: #0f172a;
  color: #38bdf8;
  padding: 12px 14px;
  border-radius: 6px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 11px;
  margin: 10px 0 8px;
  word-break: break-all;
  line-height: 1.5;
  letter-spacing: 0.02em;
}
.backup-cron-hint {
  font-size: 11px;
  color: #94a3b8;
  line-height: 1.4;
}

/* Backup History Table */
.backup-table-wrap {
  overflow-x: auto;
  margin-top: 4px;
}
.backup-history-tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.backup-history-tbl th {
  text-align: left;
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #94a3b8;
  padding: 10px 14px;
  border-bottom: 1px solid #e2e8f0;
  background: #ffffff;
}
.backup-history-tbl td {
  padding: 14px;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
}
.backup-history-tbl tr:last-child td {
  border-bottom: none;
}
.backup-file-cell {
  font-size: 12px;
  font-weight: 600;
  color: #1e293b;
  word-break: break-all;
  max-width: 200px;
}
.backup-muted-cell {
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
}
.backup-pill-badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 12px;
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  line-height: 1.25;
}
.backup-pill-badge.badge-success {
  background: #dcfce7;
  color: #15803d;
}
.backup-pill-badge.badge-danger {
  background: #fee2e2;
  color: #b91c1c;
}
.backup-pill-badge.badge-pending {
  background: #fef3c7;
  color: #b45309;
}
.backup-action-btns {
  display: flex;
  gap: 6px;
  align-items: center;
}
.backup-btn-outline {
  background: #ffffff;
  border: 1px solid #cbd5e1;
  color: #334155;
  font-size: 11px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s ease;
  white-space: nowrap;
}
.backup-btn-outline:hover {
  background: #f8fafc;
  border-color: #94a3b8;
  color: #0f172a;
}

/* Backup Security Card (Right Column) */
.backup-sec-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
}
.backup-sec-title {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}
.backup-sec-list {
  display: flex;
  flex-direction: column;
}
.backup-sec-row {
  padding: 11px 0;
  border-bottom: 1px solid #f1f5f9;
}
.backup-sec-row:last-child {
  border-bottom: none;
  padding-bottom: 0;
}
.backup-sec-k {
  font-size: 12px;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 2px;
}
.backup-sec-v {
  font-size: 12px;
  color: #64748b;
  line-height: 1.4;
}
.backup-restore-badge {
  background: #fee2e2;
  color: #ef4444;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 4px;
  letter-spacing: 0.02em;
}

/* Modals */
.backup-modal-backdrop {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(2px);
  z-index: 9999;
  align-items: center;
  justify-content: center;
}
.backup-modal-card {
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
  padding: 24px;
  width: min(460px, 92vw);
  box-sizing: border-box;
}

/* Responsive adjustments */
@media (max-width: 1080px) {
  .backup-content-layout {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 768px) {
  .backup-stats-row {
    grid-template-columns: 1fr;
  }
  .backup-settings-row {
    grid-template-columns: 1fr 1fr;
  }
}
@media (max-width: 480px) {
  .backup-settings-row {
    grid-template-columns: 1fr;
  }
}

/* Dark mode overrides */
html[data-theme-resolved='dark'] .backup-page-title,
html[data-theme-resolved='dark'] .backup-stat-val,
html[data-theme-resolved='dark'] .backup-ui-card-title,
html[data-theme-resolved='dark'] .backup-sec-title,
html[data-theme-resolved='dark'] .backup-sec-k,
html[data-theme-resolved='dark'] .backup-file-cell,
html[data-theme-resolved='dark'] .backup-cron-title,
html[data-theme-resolved='dark'] .backup-modal-card h3 {
  color: #f1f5f9 !important;
}
html[data-theme-resolved='dark'] .backup-stat-box,
html[data-theme-resolved='dark'] .backup-ui-card,
html[data-theme-resolved='dark'] .backup-modal-card {
  background: #1e293b !important;
  border-color: #334155 !important;
}
html[data-theme-resolved='dark'] .backup-history-tbl th {
  background: #1e293b !important;
  border-bottom-color: #334155 !important;
}
html[data-theme-resolved='dark'] .backup-history-tbl td,
html[data-theme-resolved='dark'] .backup-sec-row {
  border-bottom-color: #334155 !important;
}
html[data-theme-resolved='dark'] .backup-cron-box {
  background: #0f172a !important;
  border-color: #334155 !important;
}
html[data-theme-resolved='dark'] .backup-select,
html[data-theme-resolved='dark'] .backup-input {
  background: #0f172a !important;
  border-color: #475569 !important;
  color: #f1f5f9 !important;
}
html[data-theme-resolved='dark'] .backup-btn-outline {
  background: #0f172a !important;
  border-color: #475569 !important;
  color: #cbd5e1 !important;
}
html[data-theme-resolved='dark'] .backup-btn-outline:hover {
  background: #1e293b !important;
  border-color: #64748b !important;
  color: #ffffff !important;
}
</style>

<div class="backup-mgmt-container">

  <!-- Header Row -->
  <div class="backup-header-row">
    <div>
      <h1 class="backup-page-title">Database Backup &amp; Restore</h1>
      <p class="backup-page-sub">Manual and scheduled database backups, download, and authorized restore. Automatic restores never run.</p>
    </div>
    <button type="button" class="backup-btn-teal" id="btnBackup">Create Manual Backup</button>
  </div>

  <!-- 3 Stat Cards Row -->
  <div class="backup-stats-row">
    <!-- Card 1: Latest Backup -->
    <div class="backup-stat-box">
      <div class="backup-stat-title">LATEST BACKUP</div>
      <?php if ($latest): ?>
        <div class="backup-stat-val"><?= htmlspecialchars((string) ($latest['filename'] ?? '')) ?></div>
        <div class="backup-stat-subtext">
          <?= backup_mgmt_status_badge((string) ($latest['status'] ?? '')) ?>
          <span><?= !empty($latest['created_at']) ? date('M j, Y', strtotime((string) $latest['created_at'])) . ' • ' . date('g:i A', strtotime((string) $latest['created_at'])) : '—' ?></span>
        </div>
      <?php else: ?>
        <div class="backup-stat-val" style="font-weight:400;color:#94a3b8;font-style:italic;">No backups recorded yet</div>
      <?php endif; ?>
    </div>

    <!-- Card 2: Last Automatic Backup -->
    <div class="backup-stat-box">
      <div class="backup-stat-title">LAST AUTOMATIC BACKUP</div>
      <?php
        $autoStatus = $settings['last_auto_status'] ?: ($lastAuto['status'] ?? null);
        $autoAt = $settings['last_auto_at'] ?: ($lastAuto['created_at'] ?? null);
      ?>
      <?php if ($autoStatus): ?>
        <div class="backup-stat-val" style="display:flex;align-items:center;gap:8px;">
          <?= backup_mgmt_status_badge((string) $autoStatus) ?>
          <span><?= $autoAt ? date('M j, Y • g:i A', strtotime((string) $autoAt)) : '—' ?></span>
        </div>
        <?php if (!empty($lastAuto['filename'])): ?>
          <div class="backup-stat-subtext"><?= htmlspecialchars((string) $lastAuto['filename']) ?></div>
        <?php endif; ?>
      <?php else: ?>
        <div class="backup-stat-val" style="font-weight:400;color:#64748b;font-style:italic;">No automatic backup has run yet</div>
      <?php endif; ?>
    </div>

    <!-- Card 3: Schedule -->
    <div class="backup-stat-box">
      <div class="backup-stat-title">SCHEDULE</div>
      <div class="backup-stat-val">
        <?= $settings['enabled'] ? 'Enabled' : 'Disabled' ?> · <?= htmlspecialchars(ucfirst($settings['frequency'])) ?>
      </div>
      <div class="backup-stat-subtext">
        <?php if ($settings['frequency'] === 'hourly'): ?>
          Every hour (cron must run at least hourly)
        <?php elseif ($settings['frequency'] === 'weekly'): ?>
          <?= htmlspecialchars($weekdayLabels[$settings['weekday']] ?? 'Sunday') ?> at <?= sprintf('%02d:00', $settings['hour']) ?>
        <?php else: ?>
          Daily at <?= sprintf('%02d:00', $settings['hour']) ?>
        <?php endif; ?>
        • Keep last <?= (int) $settings['retention_count'] ?> backups
      </div>
    </div>
  </div>

  <!-- Main 2-Column Content Layout -->
  <div class="backup-content-layout">

    <!-- Left Column: Settings + History -->
    <div class="backup-col-left">

      <!-- Card: Automatic Backup Settings -->
      <div class="backup-ui-card">
        <h2 class="backup-ui-card-title">Automatic Backup Settings</h2>
        
        <form id="backupSettingsForm">
          <div class="backup-settings-row">
            <!-- Enabled -->
            <div class="backup-field-wrap">
              <label class="backup-field-label">ENABLED</label>
              <select name="enabled" class="backup-select">
                <option value="0"<?= !$settings['enabled'] ? ' selected' : '' ?>>Off</option>
                <option value="1"<?= $settings['enabled'] ? ' selected' : '' ?>>On</option>
              </select>
            </div>

            <!-- Frequency -->
            <div class="backup-field-wrap">
              <label class="backup-field-label">FREQUENCY</label>
              <select name="frequency" id="backupFrequency" class="backup-select">
                <option value="daily"<?= $settings['frequency'] === 'daily' ? ' selected' : '' ?>>Daily</option>
                <option value="hourly"<?= $settings['frequency'] === 'hourly' ? ' selected' : '' ?>>Hourly</option>
                <option value="weekly"<?= $settings['frequency'] === 'weekly' ? ' selected' : '' ?>>Weekly</option>
              </select>
            </div>

            <!-- Hour (0-23) -->
            <div class="backup-field-wrap" id="backupHourWrap">
              <label class="backup-field-label">HOUR (0-23)</label>
              <input type="number" name="hour" class="backup-input" min="0" max="23" value="<?= (int) $settings['hour'] ?>">
            </div>

            <!-- Weekday (for Weekly frequency) -->
            <div class="backup-field-wrap" id="backupWeekdayWrap" style="display:none;">
              <label class="backup-field-label">WEEKDAY</label>
              <select name="weekday" class="backup-select">
                <?php foreach ($weekdayLabels as $i => $label): ?>
                  <option value="<?= $i ?>"<?= (int) $settings['weekday'] === $i ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Retention (Last N) -->
            <div class="backup-field-wrap">
              <label class="backup-field-label">RETENTION (LAST N)</label>
              <input type="number" name="retention_count" class="backup-input" min="1" max="365" value="<?= (int) $settings['retention_count'] ?>">
            </div>

            <!-- Save Button -->
            <div>
              <button type="submit" class="backup-save-btn">Save</button>
            </div>
          </div>
        </form>

        <!-- Hostinger Cron Box -->
        <div class="backup-cron-box">
          <div class="backup-cron-title">Hostinger cron (required for automatic backups)</div>
          <div class="backup-cron-desc">
            Railway only runs the Python AI service — do not put this cron on Railway. Create the job in <strong>hPanel → Advanced → Cron Jobs</strong>:
          </div>
          <div class="backup-terminal-code">5 * * * * /usr/bin/php <?= htmlspecialchars($cronDisplayPath) ?></div>
          <div class="backup-cron-hint">
            Replace <code>USER</code> / <code>path</code> with your Hostinger account path after deploy.
          </div>
        </div>
      </div>

      <!-- Card: Backup History -->
      <div class="backup-ui-card">
        <h2 class="backup-ui-card-title">Backup History</h2>
        
        <div class="backup-table-wrap">
          <table class="backup-history-tbl">
            <thead>
              <tr>
                <th>FILE</th>
                <th>TYPE</th>
                <th>STATUS</th>
                <th>SIZE</th>
                <th>CREATED</th>
                <th>ACTIONS</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($backups)): ?>
                <tr>
                  <td colspan="6" style="text-align:center;padding:32px;color:#94a3b8;">
                    No backups recorded yet. Click "Create Manual Backup" to generate one.
                  </td>
                </tr>
              <?php else: foreach ($backups as $b):
                $bType = (string) ($b['backup_type'] ?? '');
                $isDump = in_array($bType, ['manual', 'scheduled', 'pre_restore'], true);
                $canAct = $isDump && ($b['status'] ?? '') === 'success';
              ?>
                <tr>
                  <td class="backup-file-cell"><?= htmlspecialchars((string) ($b['filename'] ?? '')) ?></td>
                  <td class="backup-muted-cell"><?= htmlspecialchars($bType) ?></td>
                  <td><?= backup_mgmt_status_badge((string) ($b['status'] ?? '')) ?></td>
                  <td class="backup-muted-cell"><?= backup_format_filesize(isset($b['file_size']) ? (int) $b['file_size'] : null) ?></td>
                  <td class="backup-muted-cell">
                    <?= !empty($b['created_at']) ? date('M j, Y', strtotime((string) $b['created_at'])) . ' • ' . date('g:i A', strtotime((string) $b['created_at'])) : '—' ?>
                  </td>
                  <td>
                    <?php if ($canAct): ?>
                      <div class="backup-action-btns">
                        <button type="button" class="backup-btn-outline btn-validate" data-id="<?= (int) $b['id'] ?>">Validate</button>
                        <button type="button" class="backup-btn-outline btn-test-recovery" data-id="<?= (int) $b['id'] ?>">Test recovery</button>
                        <button type="button" class="backup-btn-outline btn-download" data-id="<?= (int) $b['id'] ?>" data-filename="<?= htmlspecialchars((string) ($b['filename'] ?? 'backup.sql'), ENT_QUOTES, 'UTF-8') ?>">Download</button>
                        <button type="button" class="backup-btn-outline btn-restore" data-id="<?= (int) $b['id'] ?>">Restore</button>
                      </div>
                    <?php else: ?>
                      <span class="backup-muted-cell">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <!-- Right Column: Backup Security -->
    <div class="backup-col-right">
      <div class="backup-ui-card">
        <div class="backup-sec-header">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0d9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
          <span class="backup-sec-title">Backup Security</span>
        </div>

        <div class="backup-sec-list">
          <div class="backup-sec-row">
            <div class="backup-sec-k">Role access</div>
            <div class="backup-sec-v">Admin &amp; Super Admin protected</div>
          </div>

          <div class="backup-sec-row">
            <div class="backup-sec-k">POST actions</div>
            <div class="backup-sec-v">CSRF protected</div>
          </div>

          <div class="backup-sec-row">
            <div class="backup-sec-k">Backup path</div>
            <div class="backup-sec-v">Protected</div>
          </div>

          <div class="backup-sec-row">
            <div class="backup-sec-k">Restore</div>
            <div class="backup-sec-v">Password re-authentication required</div>
          </div>

          <div class="backup-sec-row">
            <div class="backup-sec-k">Restore confirmation</div>
            <div class="backup-sec-v">
              <span class="backup-restore-badge">RESTORE DATABASE</span> required
            </div>
          </div>

          <div class="backup-sec-row">
            <div class="backup-sec-k">Backup integrity</div>
            <div class="backup-sec-v">SHA-256 verified (Validate / Test recovery never touch live data)</div>
          </div>

          <div class="backup-sec-row">
            <div class="backup-sec-k">Active consultation guard</div>
            <div class="backup-sec-v">Blocks restore during in-progress patient visits</div>
          </div>

          <div class="backup-sec-row">
            <div class="backup-sec-k">Download rate limit</div>
            <div class="backup-sec-v">Protected (3 downloads per 15 min)</div>
          </div>

          <div class="backup-sec-row">
            <div class="backup-sec-k">Pre-restore snapshot</div>
            <div class="backup-sec-v">Automatic safety backup created before restore</div>
          </div>
        </div>
      </div>
    </div>

  </div>

</div>

<!-- Restore Modal -->
<div id="backupRestoreModal" class="backup-modal-backdrop">
  <div class="backup-modal-card">
    <h3 style="font-size:16px;font-weight:700;margin:0 0 12px;color:#0f172a;">Restore database</h3>
    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:12px;margin-bottom:14px;color:#b45309;font-size:12px;line-height:1.45;font-weight:600;">
      WARNING: Restoring this backup will overwrite the current live database. This action is manual and is not automatically reversible.
    </div>
    <form id="backupRestoreForm" style="display:flex;flex-direction:column;gap:14px;">
      <input type="hidden" name="backup_id" id="restoreBackupId" value="">
      <label class="backup-field-wrap">
        <span class="backup-field-label">Current <?= htmlspecialchars($roleName ?? 'Admin') ?> Password</span>
        <input type="password" name="current_password" id="restorePassword" class="backup-input" autocomplete="current-password" required placeholder="Enter your password">
      </label>
      <label class="backup-field-wrap">
        <span class="backup-field-label">Type RESTORE DATABASE</span>
        <input type="text" name="confirm_text" id="restoreConfirmText" class="backup-input" autocomplete="off" required placeholder="RESTORE DATABASE">
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:6px;">
        <button type="button" class="backup-btn-outline" id="backupRestoreClose" style="padding:8px 16px;">Cancel</button>
        <button type="submit" class="backup-btn-teal" style="padding:8px 18px;">Restore</button>
      </div>
    </form>
  </div>
</div>

<!-- Download Modal -->
<div id="backupDownloadModal" class="backup-modal-backdrop">
  <div class="backup-modal-card">
    <h3 style="font-size:16px;font-weight:700;margin:0 0 12px;color:#0f172a;">Download backup</h3>
    <p style="font-size:12px;color:#64748b;margin:0 0 14px;line-height:1.45;">Re-enter your password. The file is sent securely only after server-side verification.</p>
    <form id="backupDownloadForm" style="display:flex;flex-direction:column;gap:14px;">
      <input type="hidden" name="backup_id" id="downloadBackupId" value="">
      <input type="hidden" name="filename" id="downloadBackupFilename" value="">
      <label class="backup-field-wrap">
        <span class="backup-field-label">Current <?= htmlspecialchars($roleName ?? 'Admin') ?> Password</span>
        <input type="password" name="current_password" id="downloadPassword" class="backup-input" autocomplete="current-password" required placeholder="Enter your password">
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:6px;">
        <button type="button" class="backup-btn-outline" id="backupDownloadClose" style="padding:8px 16px;">Cancel</button>
        <button type="submit" class="backup-btn-teal" style="padding:8px 18px;">Download</button>
      </div>
    </form>
  </div>
</div>

<!-- Test Recovery Modal (disposable DB only — never live restore) -->
<div id="backupTestRecoveryModal" class="backup-modal-backdrop">
  <div class="backup-modal-card">
    <h3 style="font-size:16px;font-weight:700;margin:0 0 12px;color:#0f172a;">Test recovery</h3>
    <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;padding:12px;margin-bottom:14px;color:#065f46;font-size:12px;line-height:1.45;font-weight:600;">
      Safe test only: restores into a temporary disposable database when the host allows it. Live patient data is never overwritten. On Hostinger single-DB plans this may validate integrity only.
    </div>
    <form id="backupTestRecoveryForm" style="display:flex;flex-direction:column;gap:14px;">
      <input type="hidden" name="backup_id" id="testRecoveryBackupId" value="">
      <label class="backup-field-wrap">
        <span class="backup-field-label">Current <?= htmlspecialchars($roleName ?? 'Admin') ?> Password</span>
        <input type="password" name="current_password" id="testRecoveryPassword" class="backup-input" autocomplete="current-password" required placeholder="Enter your password">
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:6px;">
        <button type="button" class="backup-btn-outline" id="backupTestRecoveryClose" style="padding:8px 16px;">Cancel</button>
        <button type="submit" class="backup-btn-teal" style="padding:8px 18px;">Run safe test</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var api = <?= json_encode($api) ?>;
  var freq = document.getElementById('backupFrequency');
  var hourWrap = document.getElementById('backupHourWrap');
  var weekWrap = document.getElementById('backupWeekdayWrap');
  var restoreModal = document.getElementById('backupRestoreModal');
  var downloadModal = document.getElementById('backupDownloadModal');
  var testRecoveryModal = document.getElementById('backupTestRecoveryModal');
  var restoreForm = document.getElementById('backupRestoreForm');
  var downloadForm = document.getElementById('backupDownloadForm');
  var testRecoveryForm = document.getElementById('backupTestRecoveryForm');

  function getCsrfToken() {
    return document.body.dataset.csrf || '';
  }

  function syncScheduleFields() {
    var v = freq ? freq.value : 'daily';
    if (hourWrap) hourWrap.style.display = (v === 'hourly') ? 'none' : 'flex';
    if (weekWrap) weekWrap.style.display = (v === 'weekly') ? 'flex' : 'none';
  }
  if (freq) freq.addEventListener('change', syncScheduleFields);
  syncScheduleFields();

  function closeModals() {
    if (restoreModal) restoreModal.style.display = 'none';
    if (downloadModal) downloadModal.style.display = 'none';
    if (testRecoveryModal) testRecoveryModal.style.display = 'none';
    if (restoreForm) restoreForm.reset();
    if (downloadForm) downloadForm.reset();
    if (testRecoveryForm) testRecoveryForm.reset();
  }

  var btnBackup = document.getElementById('btnBackup');
  if (btnBackup) {
    btnBackup.onclick = function () {
      var fd = new FormData();
      fd.append('action', 'create');
      var csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);
      btnBackup.disabled = true;
      var originalText = btnBackup.textContent;
      btnBackup.textContent = 'Creating Backup...';
      fetch(api, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          alert(j.message || 'Done');
          if (j.success) location.reload();
        })
        .catch(function (err) {
          alert('Network or server error while creating backup.');
        })
        .finally(function () {
          btnBackup.disabled = false;
          btnBackup.textContent = originalText;
        });
    };
  }

  document.querySelectorAll('.btn-validate').forEach(function (b) {
    b.onclick = function () {
      var fd = new FormData();
      fd.append('action', 'validate');
      fd.append('backup_id', b.dataset.id || '');
      var csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);
      b.disabled = true;
      fetch(api, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          alert(j.message || 'Done');
        })
        .catch(function () {
          alert('Network or server error while validating backup.');
        })
        .finally(function () {
          b.disabled = false;
        });
    };
  });

  document.querySelectorAll('.btn-test-recovery').forEach(function (b) {
    b.onclick = function () {
      document.getElementById('testRecoveryBackupId').value = b.dataset.id || '';
      document.getElementById('testRecoveryPassword').value = '';
      testRecoveryModal.style.display = 'flex';
      document.getElementById('testRecoveryPassword').focus();
    };
  });

  document.querySelectorAll('.btn-restore').forEach(function (b) {
    b.onclick = function () {
      if (!confirm('Restore will overwrite the current live database. This is manual only and cannot be undone easily. Continue?')) return;
      document.getElementById('restoreBackupId').value = b.dataset.id || '';
      document.getElementById('restorePassword').value = '';
      document.getElementById('restoreConfirmText').value = '';
      restoreModal.style.display = 'flex';
      document.getElementById('restorePassword').focus();
    };
  });

  document.querySelectorAll('.btn-download').forEach(function (b) {
    b.onclick = function () {
      document.getElementById('downloadBackupId').value = b.dataset.id || '';
      document.getElementById('downloadBackupFilename').value = b.dataset.filename || 'medconnect_backup.sql';
      document.getElementById('downloadPassword').value = '';
      downloadModal.style.display = 'flex';
      document.getElementById('downloadPassword').focus();
    };
  });

  var rClose = document.getElementById('backupRestoreClose');
  if (rClose) rClose.onclick = closeModals;
  var dClose = document.getElementById('backupDownloadClose');
  if (dClose) dClose.onclick = closeModals;
  var tClose = document.getElementById('backupTestRecoveryClose');
  if (tClose) tClose.onclick = closeModals;

  // Click outside to close modals
  window.addEventListener('click', function (e) {
    if (e.target === restoreModal || e.target === downloadModal || e.target === testRecoveryModal) {
      closeModals();
    }
  });

  if (testRecoveryForm) {
    testRecoveryForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(testRecoveryForm);
      fd.append('action', 'test_recovery');
      var csrf = getCsrfToken();
      if (csrf && !fd.has('csrf_token')) fd.append('csrf_token', csrf);
      fetch(api, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          alert(j.message || 'Done');
          if (j.success) closeModals();
        })
        .catch(function () {
          alert('Error running safe recovery test.');
        })
        .finally(function () {
          document.getElementById('testRecoveryPassword').value = '';
        });
    });
  }

  if (restoreForm) {
    restoreForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(restoreForm);
      fd.append('action', 'restore');
      var csrf = getCsrfToken();
      if (csrf && !fd.has('csrf_token')) fd.append('csrf_token', csrf);
      fetch(api, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          alert(j.message || 'Done');
          if (j.success) location.reload();
        })
        .catch(function () {
          alert('Error executing database restore request.');
        })
        .finally(function () {
          document.getElementById('restorePassword').value = '';
        });
    });
  }

  if (downloadForm) {
    downloadForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(downloadForm);
      fd.append('action', 'download');
      var csrf = getCsrfToken();
      if (csrf && !fd.has('csrf_token')) fd.append('csrf_token', csrf);
      var filename = document.getElementById('downloadBackupFilename').value || 'medconnect_backup.sql';
      fetch(api, { method: 'POST', body: fd })
        .then(function (r) {
          var ct = (r.headers.get('Content-Type') || '').toLowerCase();
          if (ct.indexOf('application/sql') !== -1 || ct.indexOf('octet-stream') !== -1) {
            return r.blob().then(function (blob) {
              var url = URL.createObjectURL(blob);
              var a = document.createElement('a');
              a.href = url;
              a.download = filename;
              document.body.appendChild(a);
              a.click();
              a.remove();
              URL.revokeObjectURL(url);
              closeModals();
            });
          }
          return r.json().then(function (j) {
            alert(j.message || 'Download failed.');
          });
        })
        .catch(function () {
          alert('Network or server error during download.');
        })
        .finally(function () {
          document.getElementById('downloadPassword').value = '';
        });
    });
  }

  var form = document.getElementById('backupSettingsForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      fd.append('action', 'save_settings');
      var csrf = getCsrfToken();
      if (csrf && !fd.has('csrf_token')) fd.append('csrf_token', csrf);
      fetch(api, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          alert(j.message || 'Saved');
          if (j.success) location.reload();
        })
        .catch(function () {
          alert('Network or server error saving settings.');
        });
    });
  }
})();
</script>

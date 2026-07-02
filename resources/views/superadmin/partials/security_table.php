<?php
/**
 * Shared security data table for Super Admin security pages.
 *
 * @var string $page_title
 * @var string $page_desc
 * @var array<int, array<string, mixed>> $rows
 * @var array<int, string> $columns
 */
?>
<div class="header-row" style="margin-bottom:24px;">
  <h2 class="text-h2"><?= htmlspecialchars($page_title) ?></h2>
  <?php if (!empty($page_desc)): ?><p class="text-muted"><?= htmlspecialchars($page_desc) ?></p><?php endif; ?>
</div>
<div class="mc-card" style="padding:0;overflow:hidden;overflow-x:auto;">
  <table class="mc-table">
    <thead><tr><?php foreach ($columns as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= count($columns) ?>"><div class="mc-table-empty"><p>No records found.</p></div></td></tr>
      <?php else: foreach ($rows as $row): ?>
        <tr>
          <?php foreach (array_keys($columns) as $key): ?>
            <td class="text-sm"><?= htmlspecialchars((string) ($row[$key] ?? '—')) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

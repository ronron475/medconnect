<?php
// ── Residency Document — sample data, replace with real DB query later ──
// e.g. SELECT * FROM residency_documents WHERE patient_id = ? ORDER BY uploaded_at DESC LIMIT 1
$rd = $residency_doc ?? [
    'file_name'    => 'barangay_clearance_juan_delacruz.jpg',
    'uploaded_at'  => 'March 10, 2026 at 9:42 AM',
    'file_size'    => '1.2 MB',
    'file_type'    => 'image/jpeg',
    'status'       => 'verified',   // 'verified' | 'pending' | 'needs_review' | 'mismatch'
    'reviewed_by'  => 'CHO Bago City — Document Verification',
    'reviewed_at'  => 'March 11, 2026',
    'notes'        => null,
];

// Flash feedback from residency_upload.controller.php
$rd_success = $_SESSION['residency_success'] ?? null;
$rd_error   = $_SESSION['residency_error']   ?? null;
unset($_SESSION['residency_success'], $_SESSION['residency_error']);

// Status config map
$status_map = [
    'verified'     => [
        'label' => 'Verified',
        'class' => 'rd-status--verified',
        'icon'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'desc'  => 'Your residency document has been verified. Bago City residency confirmed.',
    ],
    'pending'      => [
        'label' => 'Pending Review',
        'class' => 'rd-status--pending',
        'icon'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'desc'  => 'Your document has been submitted and is awaiting review by the health office.',
    ],
    'needs_review' => [
        'label' => 'Needs Review',
        'class' => 'rd-status--review',
        'icon'  => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'desc'  => 'Your document requires additional review. Some services may be temporarily restricted.',
    ],
    'mismatch'     => [
        'label' => 'Residency Mismatch',
        'class' => 'rd-status--mismatch',
        'icon'  => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'desc'  => 'The submitted document could not confirm Bago City residency. Your account remains active. Please upload a valid document or contact the health office.',
    ],
];

$status_key = $rd['status'] ?? 'pending';
$status     = $status_map[$status_key] ?? $status_map['pending'];

// File type icon helper
$ext = strtolower(pathinfo($rd['file_name'], PATHINFO_EXTENSION));
$is_pdf = $ext === 'pdf';
?>

<div class="rd-section">

  <!-- ── Header ── -->
  <div class="rd-header">
    <div class="rd-header-left">
      <div class="rd-header-icon">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <path d="M9 13h6M9 17h4"/>
        </svg>
      </div>
      <div>
        <h2 class="rd-title">Residency Document</h2>
        <p class="rd-sub">Manage your Bago City residency verification document</p>
      </div>
    </div>
    <span class="rd-status-badge <?= $status['class'] ?>">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <?= $status['icon'] ?>
      </svg>
      <?= $status['label'] ?>
    </span>
  </div>

  <div class="rd-body">

    <?php if ($rd_success): ?>
    <div class="rd-alert rd-alert--success" role="alert" style="grid-column:1/-1;margin-bottom:4px;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
      </svg>
      <?= htmlspecialchars($rd_success) ?>
    </div>
    <?php endif; ?>

    <?php if ($rd_error): ?>
    <div class="rd-alert rd-alert--error" role="alert" style="grid-column:1/-1;margin-bottom:4px;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <?= htmlspecialchars($rd_error) ?>
    </div>
    <?php endif; ?>

    <!-- ── Left: current document info ── -->
    <div class="rd-current">

      <p class="rd-section-label">Current Document on File</p>

      <div class="rd-doc-card">
        <!-- File type icon -->
        <div class="rd-doc-icon <?= $is_pdf ? 'rd-doc-icon--pdf' : 'rd-doc-icon--img' ?>">
          <?php if ($is_pdf): ?>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="9" y1="13" x2="15" y2="13"/>
            <line x1="9" y1="17" x2="13" y2="17"/>
          </svg>
          <?php else: ?>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
            <circle cx="8.5" cy="8.5" r="1.5"/>
            <polyline points="21 15 16 10 5 21"/>
          </svg>
          <?php endif; ?>
        </div>

        <div class="rd-doc-info">
          <span class="rd-doc-name"><?= htmlspecialchars($rd['file_name']) ?></span>
          <div class="rd-doc-meta">
            <span><?= htmlspecialchars($rd['file_size']) ?></span>
            <span class="rd-meta-dot" aria-hidden="true"></span>
            <span><?= strtoupper($ext) ?></span>
            <span class="rd-meta-dot" aria-hidden="true"></span>
            <span>Uploaded <?= htmlspecialchars($rd['uploaded_at']) ?></span>
          </div>
        </div>
      </div>

      <!-- Status detail block -->
      <div class="rd-status-detail <?= $status['class'] ?>--bg">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <?= $status['icon'] ?>
        </svg>
        <div>
          <p class="rd-status-detail-title"><?= $status['label'] ?></p>
          <p class="rd-status-detail-desc"><?= $status['desc'] ?></p>
          <?php if (!empty($rd['reviewed_by'])): ?>
          <p class="rd-status-detail-meta">
            Reviewed by <?= htmlspecialchars($rd['reviewed_by']) ?>
            <?php if (!empty($rd['reviewed_at'])): ?>
              &middot; <?= htmlspecialchars($rd['reviewed_at']) ?>
            <?php endif; ?>
          </p>
          <?php endif; ?>
          <?php if (!empty($rd['notes'])): ?>
          <p class="rd-status-detail-note"><?= htmlspecialchars($rd['notes']) ?></p>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- ── Vertical divider ── -->
    <div class="rd-divider" aria-hidden="true"></div>

    <!-- ── Right: upload new document ── -->
    <div class="rd-upload">

      <p class="rd-section-label">Replace Document</p>

      <form
        class="rd-form"
        method="POST"
        action="<?= ASSET_BASE ?>/app/controllers/patient/residency_upload.controller.php"
        enctype="multipart/form-data"
        novalidate
      >
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"/>

        <!-- Drop zone -->
        <label class="rd-dropzone" for="residency_file" id="rd-dropzone">
          <div class="rd-dropzone-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polyline points="16 16 12 12 8 16"/>
              <line x1="12" y1="12" x2="12" y2="21"/>
              <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
            </svg>
          </div>
          <p class="rd-dropzone-primary">Click to upload or drag &amp; drop</p>
          <p class="rd-dropzone-secondary">JPG, JPEG, PNG, or PDF &middot; Max 5 MB</p>
          <input
            type="file"
            id="residency_file"
            name="residency_file"
            class="rd-file-input"
            accept=".jpg,.jpeg,.png,.pdf"
            aria-label="Upload residency document"
          />
        </label>

        <!-- Selected file preview (shown via JS) -->
        <div class="rd-file-preview" id="rd-file-preview" hidden>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
          </svg>
          <span id="rd-file-name" class="rd-file-preview-name"></span>
          <button type="button" class="rd-file-clear" id="rd-file-clear" aria-label="Remove selected file">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>

        <!-- Instructions -->
        <ul class="rd-instructions">
          <li>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            Upload a valid Barangay Certificate, Voter's ID, or any government-issued ID showing your Bago City address.
          </li>
          <li>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            The document must clearly show your full name and Bago City address.
          </li>
          <li>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            Accepted formats: JPG, JPEG, PNG, PDF. Maximum file size: 5 MB.
          </li>
        </ul>

        <!-- Re-validation notice -->
        <div class="rd-revalidation-notice">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <p>Uploading a new document will set your verification status to <strong>Pending Review</strong> and may temporarily restrict some services until re-verified by the health office.</p>
        </div>

        <button type="submit" class="rd-btn-upload" id="rd-btn-upload" disabled>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="16 16 12 12 8 16"/>
            <line x1="12" y1="12" x2="12" y2="21"/>
            <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
          </svg>
          Upload New Document
        </button>

      </form>
    </div>

  </div><!-- /rd-body -->

  <!-- ── Footer ── -->
  <div class="rd-footer">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
    </svg>
    Your residency document is stored securely and is only accessible to authorized City Health Office personnel. Uploading a new document does not delete your account.
  </div>

</div>

<script>
(function () {
  const input    = document.getElementById('residency_file');
  const preview  = document.getElementById('rd-file-preview');
  const nameEl   = document.getElementById('rd-file-name');
  const clearBtn = document.getElementById('rd-file-clear');
  const submitBtn= document.getElementById('rd-btn-upload');
  const dropzone = document.getElementById('rd-dropzone');

  function showFile(file) {
    if (!file) return;
    nameEl.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
    preview.hidden = false;
    submitBtn.disabled = false;
    dropzone.classList.add('rd-dropzone--has-file');
  }

  function clearFile() {
    input.value = '';
    preview.hidden = true;
    submitBtn.disabled = true;
    dropzone.classList.remove('rd-dropzone--has-file');
  }

  input.addEventListener('change', function () {
    showFile(this.files[0] || null);
  });

  clearBtn.addEventListener('click', clearFile);

  // Drag-and-drop
  dropzone.addEventListener('dragover', function (e) {
    e.preventDefault();
    this.classList.add('rd-dropzone--drag');
  });
  dropzone.addEventListener('dragleave', function () {
    this.classList.remove('rd-dropzone--drag');
  });
  dropzone.addEventListener('drop', function (e) {
    e.preventDefault();
    this.classList.remove('rd-dropzone--drag');
    const file = e.dataTransfer.files[0];
    if (file) {
      // Transfer to input for form submission
      const dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
      showFile(file);
    }
  });
}());
</script>

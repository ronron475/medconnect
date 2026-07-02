<?php
/**
 * Announcement Management Service — schema, CRUD, public feed, notifications, audit.
 */

require_once BASE_PATH . '/app/includes/audit_log.php';

final class AnnouncementService
{
  public const CATEGORIES = [
    'general'              => 'General Announcement',
    'health_advisory'      => 'Health Advisory',
    'medical_mission'      => 'Medical Mission',
    'vaccination'          => 'Vaccination Campaign',
    'emergency'            => 'Emergency Alert',
    'maintenance'          => 'System Maintenance',
    'provider_schedule'    => 'Provider Schedule',
    'barangay_program'     => 'Barangay Health Program',
    'cho_advisory'         => 'City Health Office Advisory',
    'other'                => 'Other',
  ];

  public const AUDIENCES = [
    'all'      => 'All Users',
    'patient'  => 'Patients',
    'provider' => 'Providers',
    'bhw'      => 'Barangay Health Workers',
    'admin'    => 'Administrators',
  ];

  public const STATUSES = ['draft', 'scheduled', 'published', 'archived', 'expired'];

  private static bool $schemaReady = false;

  public static function ensureSchema(PDO $pdo): void
  {
    if (self::$schemaReady) {
      return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      title VARCHAR(255) NOT NULL,
      message TEXT NOT NULL,
      target_roles JSON NOT NULL,
      status ENUM('draft','scheduled','published','archived','expired') NOT NULL DEFAULT 'draft',
      publish_at DATETIME NULL,
      expire_at DATETIME NULL,
      created_by INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_ann_status (status),
      KEY idx_ann_publish (publish_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = $pdo->query('SHOW COLUMNS FROM announcements')->fetchAll(PDO::FETCH_COLUMN);
    $additions = [
      'subtitle'          => "ALTER TABLE announcements ADD COLUMN subtitle VARCHAR(255) NULL AFTER title",
      'category'          => "ALTER TABLE announcements ADD COLUMN category VARCHAR(80) NOT NULL DEFAULT 'general' AFTER subtitle",
      'short_description' => "ALTER TABLE announcements ADD COLUMN short_description VARCHAR(500) NULL AFTER category",
      'content'           => "ALTER TABLE announcements ADD COLUMN content LONGTEXT NULL AFTER short_description",
      'banner_image'      => "ALTER TABLE announcements ADD COLUMN banner_image VARCHAR(512) NULL AFTER content",
      'attachment'        => "ALTER TABLE announcements ADD COLUMN attachment VARCHAR(512) NULL AFTER banner_image",
      'author_id'         => "ALTER TABLE announcements ADD COLUMN author_id INT UNSIGNED NULL AFTER attachment",
      'target_audience'   => "ALTER TABLE announcements ADD COLUMN target_audience JSON NULL AFTER target_roles",
      'priority'          => "ALTER TABLE announcements ADD COLUMN priority ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal' AFTER target_audience",
      'is_pinned'         => "ALTER TABLE announcements ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER priority",
      'is_featured'       => "ALTER TABLE announcements ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER is_pinned",
      'view_count'        => "ALTER TABLE announcements ADD COLUMN view_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_featured",
      'deleted_at'        => "ALTER TABLE announcements ADD COLUMN deleted_at DATETIME NULL AFTER updated_at",
    ];
    foreach ($additions as $col => $sql) {
      if (!in_array($col, $columns, true)) {
        try { $pdo->exec($sql); } catch (PDOException $e) { error_log('Announcement schema: ' . $e->getMessage()); }
      }
    }

    try {
      $pdo->exec("ALTER TABLE announcements MODIFY status ENUM('draft','scheduled','published','archived','expired') NOT NULL DEFAULT 'draft'");
    } catch (PDOException $e) { /* non-fatal */ }

    $pdo->exec('UPDATE announcements SET content = message WHERE (content IS NULL OR content = "") AND message IS NOT NULL');

    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_barangays (
      announcement_id BIGINT UNSIGNED NOT NULL,
      barangay_id INT UNSIGNED NOT NULL,
      PRIMARY KEY (announcement_id, barangay_id),
      KEY idx_ab_barangay (barangay_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS media_library (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      file_name VARCHAR(255) NOT NULL,
      file_path VARCHAR(512) NOT NULL,
      file_type ENUM('image','document') NOT NULL DEFAULT 'image',
      mime_type VARCHAR(100) NOT NULL,
      file_size INT UNSIGNED NOT NULL DEFAULT 0,
      alt_text VARCHAR(255) NULL,
      uploaded_by INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_media_type (file_type),
      KEY idx_media_uploaded (uploaded_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach (['announcements', 'media_library'] as $dir) {
      $path = STORAGE_PATH . '/uploads/' . $dir;
      if (!is_dir($path)) {
        mkdir($path, 0755, true);
      }
    }

    self::$schemaReady = true;
  }

  public static function syncStatuses(PDO $pdo): void
  {
    self::ensureSchema($pdo);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE announcements SET status = 'published' WHERE status = 'scheduled' AND deleted_at IS NULL AND publish_at IS NOT NULL AND publish_at <= ?")
      ->execute([$now]);
    $pdo->prepare("UPDATE announcements SET status = 'expired' WHERE status = 'published' AND deleted_at IS NULL AND expire_at IS NOT NULL AND expire_at <= ?")
      ->execute([$now]);
  }

  /** @return array{items: array<int, array>, total: int} */
  public static function listAdmin(PDO $pdo, array $filters = []): array
  {
    self::ensureSchema($pdo);
    self::syncStatuses($pdo);

    $where = ['a.deleted_at IS NULL'];
    $params = [];

    if (!empty($filters['status'])) {
      $where[] = 'a.status = ?';
      $params[] = $filters['status'];
    }
    if (!empty($filters['category'])) {
      $where[] = 'a.category = ?';
      $params[] = $filters['category'];
    }
    if (!empty($filters['author_id'])) {
      $where[] = 'a.author_id = ?';
      $params[] = (int) $filters['author_id'];
    }
    if (isset($filters['is_pinned']) && $filters['is_pinned'] !== '') {
      $where[] = 'a.is_pinned = ?';
      $params[] = (int) $filters['is_pinned'];
    }
    if (!empty($filters['audience'])) {
      $where[] = '(JSON_CONTAINS(a.target_audience, ?) OR JSON_CONTAINS(a.target_roles, ?))';
      $json = json_encode($filters['audience']);
      $params[] = $json;
      $params[] = $json;
    }
    if (!empty($filters['search'])) {
      $where[] = '(a.title LIKE ? OR a.short_description LIKE ? OR a.content LIKE ?)';
      $q = '%' . $filters['search'] . '%';
      $params[] = $q;
      $params[] = $q;
      $params[] = $q;
    }
    if (!empty($filters['date_from'])) {
      $where[] = 'DATE(a.publish_at) >= ?';
      $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
      $where[] = 'DATE(a.publish_at) <= ?';
      $params[] = $filters['date_to'];
    }

    $sqlWhere = implode(' AND ', $where);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM announcements a WHERE $sqlWhere");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));

    $stmt = $pdo->prepare("
      SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS author_name
      FROM announcements a
      LEFT JOIN users u ON u.id = COALESCE(a.author_id, a.created_by)
      WHERE $sqlWhere
      ORDER BY a.is_pinned DESC, a.updated_at DESC
      LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$row) {
      $row = self::normalizeRow($row);
    }

    return ['items' => $items, 'total' => $total];
  }

  /** @return array<int, array> */
  public static function listPublic(PDO $pdo, int $limit = 6, int $offset = 0): array
  {
    self::ensureSchema($pdo);
    self::syncStatuses($pdo);

    $limit = max(1, min(50, $limit));
    $offset = max(0, $offset);
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
      SELECT a.*
      FROM announcements a
      WHERE a.deleted_at IS NULL
        AND a.status = 'published'
        AND (a.publish_at IS NULL OR a.publish_at <= ?)
        AND (a.expire_at IS NULL OR a.expire_at > ?)
      ORDER BY a.is_pinned DESC, COALESCE(a.publish_at, a.created_at) DESC
      LIMIT $limit OFFSET $offset
    ");
    $stmt->execute([$now, $now]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map([self::class, 'normalizeRow'], $items);
  }

  public static function countPublic(PDO $pdo): int
  {
    self::syncStatuses($pdo);
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM announcements
      WHERE deleted_at IS NULL AND status = 'published'
        AND (publish_at IS NULL OR publish_at <= ?)
        AND (expire_at IS NULL OR expire_at > ?)
    ");
    $stmt->execute([$now, $now]);
    return (int) $stmt->fetchColumn();
  }

  public static function findById(PDO $pdo, int $id, bool $includeDeleted = false): ?array
  {
    self::ensureSchema($pdo);
    $sql = 'SELECT a.*, CONCAT(u.first_name, \' \', u.last_name) AS author_name
            FROM announcements a
            LEFT JOIN users u ON u.id = COALESCE(a.author_id, a.created_by)
            WHERE a.id = ?';
    if (!$includeDeleted) {
      $sql .= ' AND a.deleted_at IS NULL';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return null;
    }
    $row = self::normalizeRow($row);
    $row['barangay_ids'] = self::getBarangayIds($pdo, $id);
    return $row;
  }

  public static function findPublicById(PDO $pdo, int $id): ?array
  {
    self::syncStatuses($pdo);
    $row = self::findById($pdo, $id);
    if (!$row || $row['status'] !== 'published') {
      return null;
    }
    $now = time();
    if (!empty($row['publish_at']) && strtotime($row['publish_at']) > $now) {
      return null;
    }
    if (!empty($row['expire_at']) && strtotime($row['expire_at']) <= $now) {
      return null;
    }
    return $row;
  }

  public static function incrementViewCount(PDO $pdo, int $id): void
  {
    $pdo->prepare('UPDATE announcements SET view_count = view_count + 1 WHERE id = ?')->execute([$id]);
  }

  /** @param array<string, mixed> $data */
  public static function create(PDO $pdo, array $data, int $adminId): array
  {
    self::ensureSchema($pdo);
    $payload = self::buildPayload($data, $adminId);
    $stmt = $pdo->prepare('
      INSERT INTO announcements
        (title, subtitle, category, short_description, content, message, banner_image, attachment,
         author_id, target_roles, target_audience, priority, status, is_pinned, is_featured,
         publish_at, expire_at, created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $stmt->execute([
      $payload['title'], $payload['subtitle'], $payload['category'], $payload['short_description'],
      $payload['content'], $payload['content'], $payload['banner_image'], $payload['attachment'],
      $adminId, json_encode($payload['target_roles']), json_encode($payload['target_audience']),
      $payload['priority'], $payload['status'], $payload['is_pinned'], $payload['is_featured'],
      $payload['publish_at'], $payload['expire_at'], $adminId,
    ]);
    $id = (int) $pdo->lastInsertId();
    self::syncBarangays($pdo, $id, $payload['barangay_ids']);

    self::audit($pdo, $adminId, AuditAction::ANNOUNCEMENT_CREATED, $id, $payload['title'], 'Created announcement.');
    if ($payload['status'] === 'published') {
      self::dispatchNotifications($pdo, $id, $adminId, $payload);
      self::audit($pdo, $adminId, AuditAction::ANNOUNCEMENT_PUBLISHED, $id, $payload['title'], 'Published on create.');
    }

    return ['success' => true, 'id' => $id, 'message' => 'Announcement created.'];
  }

  /** @param array<string, mixed> $data */
  public static function update(PDO $pdo, int $id, array $data, int $adminId): array
  {
    $existing = self::findById($pdo, $id);
    if (!$existing) {
      return ['success' => false, 'message' => 'Announcement not found.'];
    }

    $payload = self::buildPayload($data, $adminId, $existing);
    $wasPublished = $existing['status'] === 'published';

    $stmt = $pdo->prepare('
      UPDATE announcements SET
        title=?, subtitle=?, category=?, short_description=?, content=?, message=?,
        banner_image=?, attachment=?, author_id=?, target_roles=?, target_audience=?,
        priority=?, status=?, is_pinned=?, is_featured=?, publish_at=?, expire_at=?
      WHERE id=? AND deleted_at IS NULL
    ');
    $stmt->execute([
      $payload['title'], $payload['subtitle'], $payload['category'], $payload['short_description'],
      $payload['content'], $payload['content'], $payload['banner_image'], $payload['attachment'],
      $adminId, json_encode($payload['target_roles']), json_encode($payload['target_audience']),
      $payload['priority'], $payload['status'], $payload['is_pinned'], $payload['is_featured'],
      $payload['publish_at'], $payload['expire_at'], $id,
    ]);
    self::syncBarangays($pdo, $id, $payload['barangay_ids']);

    self::audit($pdo, $adminId, AuditAction::ANNOUNCEMENT_EDITED, $id, $payload['title'], 'Edited announcement.');
    if (!$wasPublished && $payload['status'] === 'published') {
      self::dispatchNotifications($pdo, $id, $adminId, $payload);
      self::audit($pdo, $adminId, AuditAction::ANNOUNCEMENT_PUBLISHED, $id, $payload['title'], 'Published on update.');
    }

    return ['success' => true, 'id' => $id, 'message' => 'Announcement updated.'];
  }

  public static function publish(PDO $pdo, int $id, int $adminId): array
  {
    $row = self::findById($pdo, $id);
    if (!$row) {
      return ['success' => false, 'message' => 'Announcement not found.'];
    }
    $wasPublished = $row['status'] === 'published';
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
      UPDATE announcements SET
        status = 'published',
        publish_at = ?,
        expire_at = IF(expire_at IS NOT NULL AND expire_at <= ?, NULL, expire_at)
      WHERE id = ?
    ")->execute([$now, $now, $id]);
    $row['status'] = 'published';
    $row['publish_at'] = $now;
    if (!empty($row['expire_at']) && strtotime((string) $row['expire_at']) <= strtotime($now)) {
      $row['expire_at'] = null;
    }
    $row['barangay_ids'] = self::getBarangayIds($pdo, $id);
    if (!$wasPublished) {
      self::dispatchNotifications($pdo, $id, $adminId, $row);
      self::audit($pdo, $adminId, AuditAction::ANNOUNCEMENT_PUBLISHED, $id, $row['title'], 'Published announcement.');
    }
    return ['success' => true, 'message' => 'Announcement published and is now live on the landing page.'];
  }

  public static function unpublish(PDO $pdo, int $id, int $adminId): array
  {
    $row = self::findById($pdo, $id);
    if (!$row) {
      return ['success' => false, 'message' => 'Announcement not found.'];
    }
    $pdo->prepare("UPDATE announcements SET status='draft' WHERE id=?")->execute([$id]);
    self::audit($pdo, $adminId, AuditAction::ANNOUNCEMENT_UNPUBLISHED, $id, $row['title'], 'Unpublished announcement.');
    return ['success' => true, 'message' => 'Announcement unpublished.'];
  }

  public static function archive(PDO $pdo, int $id, int $adminId): array
  {
    $row = self::findById($pdo, $id);
    if (!$row) {
      return ['success' => false, 'message' => 'Announcement not found.'];
    }
    $pdo->prepare("UPDATE announcements SET status='archived' WHERE id=?")->execute([$id]);
    self::audit($pdo, $adminId, AuditAction::ANNOUNCEMENT_ARCHIVED, $id, $row['title'], 'Archived announcement.');
    return ['success' => true, 'message' => 'Announcement archived.'];
  }

  public static function restore(PDO $pdo, int $id, int $adminId): array
  {
    $row = self::findById($pdo, $id, true);
    if (!$row) {
      return ['success' => false, 'message' => 'Announcement not found.'];
    }
    if ($row['deleted_at']) {
      $pdo->prepare('UPDATE announcements SET deleted_at=NULL, status=? WHERE id=?')
        ->execute(['draft', $id]);
      self::audit($pdo, $adminId, AuditAction::ANNOUNCEMENT_RESTORED, $id, $row['title'], 'Restored deleted announcement.');
    } else {
      $pdo->prepare("UPDATE announcements SET status='draft' WHERE id=?")->execute([$id]);
      self::audit($pdo, $adminId, AuditAction::ANNOUNCEMENT_RESTORED, $id, $row['title'], 'Restored archived announcement.');
    }
    return ['success' => true, 'message' => 'Announcement restored.'];
  }

  public static function delete(PDO $pdo, int $id, int $adminId): array
  {
    $row = self::findById($pdo, $id);
    if (!$row) {
      return ['success' => false, 'message' => 'Announcement not found.'];
    }
    $pdo->prepare('UPDATE announcements SET deleted_at=NOW(), status=? WHERE id=?')
      ->execute(['archived', $id]);
    self::audit($pdo, $adminId, AuditAction::ANNOUNCEMENT_DELETED, $id, $row['title'], 'Soft-deleted announcement.');
    return ['success' => true, 'message' => 'Announcement deleted.'];
  }

  public static function togglePin(PDO $pdo, int $id, int $adminId): array
  {
    $row = self::findById($pdo, $id);
    if (!$row) {
      return ['success' => false, 'message' => 'Announcement not found.'];
    }
    $pinned = $row['is_pinned'] ? 0 : 1;
    $pdo->prepare('UPDATE announcements SET is_pinned=? WHERE id=?')->execute([$pinned, $id]);
    self::audit($pdo, $adminId, AuditAction::ANNOUNCEMENT_EDITED, $id, $row['title'], $pinned ? 'Pinned announcement.' : 'Unpinned announcement.');
    return ['success' => true, 'message' => $pinned ? 'Announcement pinned.' : 'Announcement unpinned.', 'is_pinned' => $pinned];
  }

  /** @return array{success:bool,message:string,path?:string,url?:string} */
  public static function handleUpload(array $file, string $type = 'banner'): array
  {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
      return ['success' => false, 'message' => 'No file uploaded.'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
      return ['success' => false, 'message' => 'File exceeds 5 MB limit.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    $imageMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $docMimes = ['application/pdf'];

    if ($type === 'banner') {
      if (!in_array($mime, $imageMimes, true)) {
        return ['success' => false, 'message' => 'Invalid image type.'];
      }
      $ext = match ($mime) {
        'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => 'jpg',
      };
      $subdir = 'announcements';
    } else {
      if (!in_array($mime, array_merge($imageMimes, $docMimes), true)) {
        return ['success' => false, 'message' => 'Invalid file type.'];
      }
      $ext = $mime === 'application/pdf' ? 'pdf' : 'jpg';
      $subdir = $type === 'media' ? 'media_library' : 'announcements';
    }

    $name = 'ann_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dir = STORAGE_PATH . '/uploads/' . $subdir;
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
      return ['success' => false, 'message' => 'Upload failed.'];
    }

    $relative = 'uploads/' . $subdir . '/' . $name;
    return [
      'success' => true,
      'message' => 'File uploaded.',
      'path'    => $relative,
      'url'     => self::publicUrl($relative),
    ];
  }

  public static function publicUrl(?string $relativePath): ?string
  {
    if (!$relativePath) {
      return null;
    }
  $safe = ltrim(str_replace(['..', '\\'], ['', '/'], $relativePath), '/');
    return ASSET_BASE . '/app/api/public/media.php?f=' . rawurlencode($safe);
  }

  /** @return array<int, array> */
  public static function listMedia(PDO $pdo, ?string $type = null): array
  {
    self::ensureSchema($pdo);
    $sql = 'SELECT m.*, CONCAT(u.first_name, \' \', u.last_name) AS uploader_name FROM media_library m LEFT JOIN users u ON u.id = m.uploaded_by';
    $params = [];
    if ($type) {
      $sql .= ' WHERE m.file_type = ?';
      $params[] = $type;
    }
    $sql .= ' ORDER BY m.created_at DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
      $r['url'] = self::publicUrl($r['file_path']);
    }
    return $rows;
  }

  public static function saveMediaRecord(PDO $pdo, string $path, string $mime, int $size, int $adminId, string $alt = ''): int
  {
    self::ensureSchema($pdo);
    $type = str_starts_with($mime, 'image/') ? 'image' : 'document';
    $stmt = $pdo->prepare('INSERT INTO media_library (file_name, file_path, file_type, mime_type, file_size, alt_text, uploaded_by) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([basename($path), $path, $type, $mime, $size, $alt, $adminId]);
    return (int) $pdo->lastInsertId();
  }

  /** @return array{success:bool,message:string} */
  public static function deleteMedia(PDO $pdo, int $id): array
  {
    self::ensureSchema($pdo);
    $stmt = $pdo->prepare('SELECT id, file_path FROM media_library WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return ['success' => false, 'message' => 'Media file not found.'];
    }

    $fullPath = STORAGE_PATH . '/' . ltrim((string) $row['file_path'], '/');
    if (is_file($fullPath)) {
      @unlink($fullPath);
    }

    $pdo->prepare('DELETE FROM media_library WHERE id = ?')->execute([$id]);
    return ['success' => true, 'message' => 'Media file deleted.'];
  }

  /** @return array{success:bool,message:string} */
  public static function updateMediaAlt(PDO $pdo, int $id, string $alt): array
  {
    self::ensureSchema($pdo);
    $stmt = $pdo->prepare('UPDATE media_library SET alt_text = ? WHERE id = ?');
    $stmt->execute([$alt, $id]);
    if ($stmt->rowCount() === 0) {
      return ['success' => false, 'message' => 'Media file not found.'];
    }
    return ['success' => true, 'message' => 'Alt text updated.'];
  }

  /** @return array<int, array{name:string,id:int}> */
  public static function listBarangays(PDO $pdo): array
  {
    try {
      return $pdo->query('SELECT id, name FROM barangays WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
      return [];
    }
  }

  /** @param array<string, mixed> $row */
  private static function normalizeRow(array $row): array
  {
    $row['target_roles'] = json_decode($row['target_roles'] ?? '[]', true) ?: [];
    $row['target_audience'] = json_decode($row['target_audience'] ?? '[]', true) ?: $row['target_roles'];
    $row['content'] = $row['content'] ?? $row['message'] ?? '';
    $row['short_description'] = $row['short_description'] ?? '';
    $row['banner_url'] = self::publicUrl($row['banner_image'] ?? null);
    $row['attachment_url'] = self::publicUrl($row['attachment'] ?? null);
    $row['category_label'] = self::CATEGORIES[$row['category'] ?? 'general'] ?? 'General Announcement';
    return $row;
  }

  /** @return array<int> */
  private static function getBarangayIds(PDO $pdo, int $id): array
  {
    try {
      $stmt = $pdo->prepare('SELECT barangay_id FROM announcement_barangays WHERE announcement_id = ?');
      $stmt->execute([$id]);
      return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
      return [];
    }
  }

  /** @param array<int> $ids */
  private static function syncBarangays(PDO $pdo, int $announcementId, array $ids): void
  {
    $pdo->prepare('DELETE FROM announcement_barangays WHERE announcement_id = ?')->execute([$announcementId]);
    if (empty($ids)) {
      return;
    }
    $ins = $pdo->prepare('INSERT INTO announcement_barangays (announcement_id, barangay_id) VALUES (?,?)');
    foreach (array_unique(array_map('intval', $ids)) as $bid) {
      if ($bid > 0) {
        $ins->execute([$announcementId, $bid]);
      }
    }
  }

  /** @param array<string, mixed> $data @param array<string, mixed>|null $existing */
  private static function buildPayload(array $data, int $adminId, ?array $existing = null): array
  {
    $title = trim((string) ($data['title'] ?? $existing['title'] ?? ''));
    $content = trim((string) ($data['content'] ?? $data['message'] ?? $existing['content'] ?? ''));
    if ($title === '' || $content === '') {
      throw new InvalidArgumentException('Title and content are required.');
    }

    $audience = $data['target_audience'] ?? $data['targets'] ?? $existing['target_audience'] ?? ['all'];
    if (!is_array($audience)) {
      $audience = [$audience];
    }
    $audience = array_values(array_unique(array_filter($audience)));
    if (empty($audience)) {
      $audience = ['all'];
    }

    $roles = array_values(array_filter($audience, fn($a) => $a !== 'all'));
    if (in_array('all', $audience, true)) {
      $roles = ['patient', 'provider', 'bhw', 'admin'];
    }

    $saveAction = $data['save_action'] ?? $data['action'] ?? null;
    $status = $existing['status'] ?? 'draft';
    if ($saveAction === 'publish') {
      $status = 'published';
    } elseif ($saveAction === 'schedule') {
      $status = 'scheduled';
    } elseif ($saveAction === 'draft') {
      $status = 'draft';
    } elseif (!empty($data['status']) && in_array($data['status'], self::STATUSES, true)) {
      $status = $data['status'];
    }

    $publishAt = self::normalizeDatetime($data['publish_at'] ?? $existing['publish_at'] ?? null);
    $expireAt = self::normalizeDatetime($data['expire_at'] ?? $existing['expire_at'] ?? null);

    if ($status === 'published' && !$publishAt) {
      $publishAt = date('Y-m-d H:i:s');
    }
    if ($status === 'published' && $saveAction === 'publish') {
      $publishAt = date('Y-m-d H:i:s');
    }
    if ($status === 'published' && $expireAt && strtotime($expireAt) <= time()) {
      $expireAt = null;
    }
    if ($status === 'scheduled' && !$publishAt) {
      throw new InvalidArgumentException('Publish date is required for scheduled announcements.');
    }

    $category = (string) ($data['category'] ?? $existing['category'] ?? 'general');
    if (!isset(self::CATEGORIES[$category])) {
      $category = 'general';
    }

    return [
      'title'             => $title,
      'subtitle'          => trim((string) ($data['subtitle'] ?? $existing['subtitle'] ?? '')) ?: null,
      'category'          => $category,
      'short_description' => trim((string) ($data['short_description'] ?? $existing['short_description'] ?? '')) ?: null,
      'content'           => $content,
      'banner_image'      => trim((string) ($data['banner_image'] ?? $existing['banner_image'] ?? '')) ?: null,
      'attachment'        => trim((string) ($data['attachment'] ?? $existing['attachment'] ?? '')) ?: null,
      'target_audience'   => $audience,
      'target_roles'      => $roles,
      'barangay_ids'      => array_map('intval', (array) ($data['barangay_ids'] ?? $existing['barangay_ids'] ?? [])),
      'priority'          => in_array($data['priority'] ?? $existing['priority'] ?? 'normal', ['low','normal','high','critical'], true)
        ? ($data['priority'] ?? $existing['priority'] ?? 'normal') : 'normal',
      'status'            => $status,
      'is_pinned'         => isset($data['is_pinned'])
        ? (!empty($data['is_pinned']) ? 1 : 0)
        : (int) ($existing['is_pinned'] ?? 0),
      'is_featured'       => isset($data['is_featured'])
        ? (!empty($data['is_featured']) ? 1 : 0)
        : (int) ($existing['is_featured'] ?? 0),
      'publish_at'        => $publishAt,
      'expire_at'         => $expireAt,
    ];
  }

  private static function normalizeDatetime(?string $value): ?string
  {
    if (!$value) {
      return null;
    }
    $value = str_replace('T', ' ', trim($value));
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
  }

  /** @param array<string, mixed> $payload */
  private static function dispatchNotifications(PDO $pdo, int $id, int $adminId, array $payload): void
  {
    $roles = $payload['target_roles'] ?? [];
    $short = $payload['short_description'] ?: mb_substr(strip_tags($payload['content']), 0, 200);
    $link = ASSET_BASE . '/public/announcements.php?id=' . $id;
    $notifPriority = match ($payload['priority'] ?? 'normal') {
      'critical' => 'critical', 'high' => 'high', 'low' => 'low', default => 'normal',
    };

    $options = [
      'sender_id'      => $adminId,
      'type'           => 'announcement',
      'title'          => $payload['title'],
      'message'        => $short,
      'priority'       => $notifPriority,
      'related_table'  => 'announcements',
      'related_id'     => $id,
      'action_url'     => $link,
      'expires_at'     => $payload['expire_at'] ?? null,
    ];

    foreach ($roles as $role) {
      if ($role === 'bhw' && !empty($payload['barangay_ids'])) {
        self::notifyBhwBarangays($pdo, $payload['barangay_ids'], $options);
      } else {
        NotificationManager::notifyRole($pdo, $role, $options);
      }
    }
  }

  /** @param array<int> $barangayIds @param array<string, mixed> $options */
  private static function notifyBhwBarangays(PDO $pdo, array $barangayIds, array $options): void
  {
    if (empty($barangayIds)) {
      NotificationManager::notifyRole($pdo, 'bhw', $options);
      return;
    }
    $placeholders = implode(',', array_fill(0, count($barangayIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role='bhw' AND is_active=1 AND barangay_id IN ($placeholders)");
    $stmt->execute($barangayIds);
    while ($uid = $stmt->fetchColumn()) {
      NotificationManager::create($pdo, (int) $uid, $options);
    }
  }

  private static function audit(PDO $pdo, int $adminId, string $action, int $annId, string $title, string $desc): void
  {
    audit_log($pdo, [
      'patient_id'  => $adminId,
      'action_type' => $action,
      'description' => $desc,
      'meta'        => [
        'announcement_id'    => $annId,
        'announcement_title' => $title,
        'admin_name'         => trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
      ],
    ]);
  }
}

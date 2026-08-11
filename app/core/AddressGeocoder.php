<?php
/**
 * Cached forward geocoding for Bago City patient addresses (Nominatim).
 */
final class AddressGeocoder
{
  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
    $this->ensureSchema();
  }

  public function ensureSchema(): void
  {
    static $done = false;
    if ($done) {
      return;
    }

    $this->pdo->exec("
      CREATE TABLE IF NOT EXISTS address_geocode_cache (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        address_hash CHAR(64) NOT NULL,
        query_text VARCHAR(500) NOT NULL,
        latitude DECIMAL(10, 8) NULL,
        longitude DECIMAL(11, 8) NULL,
        confidence VARCHAR(20) NULL,
        provider VARCHAR(40) NOT NULL DEFAULT 'nominatim',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_address_geocode_hash (address_hash)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
  }

  /**
   * @return array{lat: float, lng: float}|null
   */
  public function geocode(string $query, string $confidence = 'MEDIUM'): ?array
  {
    $query = trim($query);
    if ($query === '' || !in_array($confidence, ['HIGH', 'MEDIUM'], true)) {
      return null;
    }

    $hash = hash('sha256', strtolower($query));
    $cached = $this->readCache($hash);
    if ($cached !== null) {
      return $cached;
    }

    $result = $this->requestNominatim($query);
    $this->writeCache($hash, $query, $result, $confidence);

    return $result;
  }

  /**
   * @return array{lat: float, lng: float}|null
   */
  private function readCache(string $hash): ?array
  {
    $stmt = $this->pdo->prepare(
      'SELECT latitude, longitude FROM address_geocode_cache WHERE address_hash = ? LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['latitude'] === null || $row['longitude'] === null) {
      return null;
    }

    $lat = (float) $row['latitude'];
    $lng = (float) $row['longitude'];
    if (!$this->validCoordinate($lat, $lng)) {
      return null;
    }

    return ['lat' => $lat, 'lng' => $lng];
  }

  /**
   * @param array{lat: float, lng: float}|null $coords
   */
  private function writeCache(string $hash, string $query, ?array $coords, string $confidence): void
  {
    $stmt = $this->pdo->prepare("
      INSERT INTO address_geocode_cache (address_hash, query_text, latitude, longitude, confidence)
      VALUES (?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        query_text = VALUES(query_text),
        latitude = VALUES(latitude),
        longitude = VALUES(longitude),
        confidence = VALUES(confidence),
        updated_at = NOW()
    ");
    $stmt->execute([
      $hash,
      mb_substr($query, 0, 500),
      $coords['lat'] ?? null,
      $coords['lng'] ?? null,
      $confidence,
    ]);
  }

  /**
   * @return array{lat: float, lng: float}|null
   */
  private function requestNominatim(string $query): ?array
  {
    if (!function_exists('curl_init')) {
      return null;
    }

    $url = 'https://nominatim.openstreetmap.org/search?'
      . http_build_query([
        'q'            => $query,
        'format'       => 'json',
        'limit'        => 1,
        'countrycodes' => 'ph',
      ]);

    $ch = curl_init($url);
    if ($ch === false) {
      return null;
    }

    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 8,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_HTTPHEADER     => [
        'User-Agent: MedConnectGIS/1.0 (Bago City health mapping)',
        'Accept: application/json',
      ],
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
      return null;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || $decoded === []) {
      return null;
    }

    $first = $decoded[0] ?? null;
    if (!is_array($first)) {
      return null;
    }

    $lat = isset($first['lat']) ? (float) $first['lat'] : null;
    $lng = isset($first['lon']) ? (float) $first['lon'] : null;
    if ($lat === null || $lng === null || !$this->validCoordinate($lat, $lng)) {
      return null;
    }

    require_once dirname(__DIR__) . '/core/BagoBarangayCentroids.php';
    $bounds = BagoBarangayCentroids::cityBounds();
    if ($lat < $bounds['south'] || $lat > $bounds['north'] || $lng < $bounds['west'] || $lng > $bounds['east']) {
      return null;
    }

    return ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
  }

  private function validCoordinate(float $lat, float $lng): bool
  {
    return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
      && !($lat == 0.0 && $lng == 0.0);
  }
}

<?php
/**
 * Smart patient location resolution — GPS, geocoded address, barangay center, or unavailable.
 */
final class PatientLocationResolver
{
  private PDO $pdo;

  private ?AddressGeocoder $geocoder = null;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
    require_once dirname(__DIR__) . '/core/PatientAddressFormatter.php';
    require_once dirname(__DIR__) . '/core/BagoBarangayCentroids.php';
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  public function resolve(array $row, bool $allowLiveGeocode = false): array
  {
    $canonicalBarangay = $this->resolveCanonicalBarangay($row);
    $row['canonical_barangay'] = $canonicalBarangay;
    $row['barangay'] = $canonicalBarangay !== '' ? $canonicalBarangay : PatientAddressFormatter::cleanPart($row['barangay'] ?? '');

    $displayAddress = PatientAddressFormatter::build($row);
    $addressConfidence = PatientAddressFormatter::confidence($row);

    $storedLat = $this->parseCoordinate($row['latitude'] ?? null);
    $storedLng = $this->parseCoordinate($row['longitude'] ?? null);
    $storedSource = $this->normalizeStoredSource((string) ($row['location_source'] ?? ''));

    if ($storedLat !== null && $storedLng !== null && $this->validCoordinate($storedLat, $storedLng)) {
      if (in_array($storedSource, ['gps', 'manual', 'imported'], true)) {
        return $this->result(
          $storedLat,
          $storedLng,
          'gps',
          'exact',
          $displayAddress,
          $addressConfidence,
          $canonicalBarangay,
          'Verified patient GPS coordinates.'
        );
      }

      if ($storedSource === 'address_geocoded') {
        return $this->result(
          $storedLat,
          $storedLng,
          'address_geocoded',
          'geocoded',
          $displayAddress,
          $addressConfidence,
          $canonicalBarangay,
          'Location derived from the registered address.'
        );
      }

      if ($storedSource === 'barangay_centroid' && $canonicalBarangay !== '') {
        return $this->barangayResult($canonicalBarangay, $displayAddress, $addressConfidence, $storedLat, $storedLng);
      }

      if ($storedSource === 'barangay_centroid' && $canonicalBarangay === '') {
        // Ignore legacy centroid rows tied to invalid barangay values (e.g. city-center fallbacks).
      } elseif ($storedSource === 'barangay_center' && $canonicalBarangay !== '') {
        return $this->barangayResult($canonicalBarangay, $displayAddress, $addressConfidence, $storedLat, $storedLng);
      }
    }

    if ($allowLiveGeocode && in_array($addressConfidence, ['HIGH', 'MEDIUM'], true) && $displayAddress !== '') {
      $geocoded = $this->geocoder()->geocode($displayAddress . ', Philippines', $addressConfidence);
      if ($geocoded !== null) {
        return $this->result(
          $geocoded['lat'],
          $geocoded['lng'],
          'address_geocoded',
          'geocoded',
          $displayAddress,
          $addressConfidence,
          $canonicalBarangay,
          'Location derived from the registered address.'
        );
      }
    }

    if ($canonicalBarangay !== '') {
      $coords = $this->lookupBarangayCoordinates($canonicalBarangay, (string) ($row['city_municipality'] ?? ($row['municipality'] ?? 'Bago City')));
      if ($coords !== null) {
        return $this->barangayResult($canonicalBarangay, $displayAddress, $addressConfidence, $coords['lat'], $coords['lng']);
      }
    }

    return $this->result(
      null,
      null,
      'unavailable',
      'unavailable',
      $displayAddress,
      $addressConfidence,
      $canonicalBarangay,
      'Exact patient location is unavailable.'
    );
  }

  /**
   * @param array<string, mixed> $row
   */
  private function resolveCanonicalBarangay(array $row): string
  {
    $candidates = [
      (string) ($row['barangay'] ?? ''),
      (string) ($row['pl_barangay'] ?? ''),
      (string) ($row['pr_barangay'] ?? ''),
      (string) ($row['address'] ?? ''),
      (string) ($row['full_address'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
      $candidate = trim($candidate);
      if ($candidate === '' || $this->isInvalidBarangayValue($candidate)) {
        continue;
      }

      $canonical = BagoBarangayCentroids::canonicalName($candidate);
      if ($canonical !== null) {
        return $canonical;
      }
    }

    foreach ($candidates as $candidate) {
      $fromText = $this->extractBarangayFromText($candidate);
      if ($fromText !== '') {
        return $fromText;
      }
    }

    return '';
  }

  private function isInvalidBarangayValue(string $value): bool
  {
    $lower = strtolower(trim($value));
    $blocked = [
      'population', 'male', 'female', 'single', 'married', 'widowed', 'divorced',
      'employed', 'unemployed', 'student', 'unknown', 'null', 'undefined',
    ];

    return in_array($lower, $blocked, true);
  }

  private function extractBarangayFromText(string $text): string
  {
    $text = trim($text);
    if ($text === '') {
      return '';
    }

    if (preg_match('/(?:barangay|brgy\.?)\s+([A-Za-z0-9\- ]+)/i', $text, $m)) {
      $canonical = BagoBarangayCentroids::canonicalName(trim($m[1]));
      if ($canonical !== null) {
        return $canonical;
      }
    }

    foreach (BagoBarangayCentroids::barangayNames() as $name) {
      if (preg_match('/\b' . preg_quote($name, '/') . '\b/i', $text)) {
        return $name;
      }
    }

    return '';
  }

  /**
   * @return array{lat: float, lng: float}|null
   */
  private function lookupBarangayCoordinates(string $canonicalBarangay, string $city): ?array
  {
    $coords = BagoBarangayCentroids::resolveBarangayCenter($canonicalBarangay);
    if ($coords !== null) {
      return $coords;
    }

    if ($this->tableExists('barangays')) {
      $activeClause = $this->columnExists('barangays', 'is_active') ? ' AND is_active = 1' : '';
      $stmt = $this->pdo->prepare(
        'SELECT latitude, longitude
         FROM barangays
         WHERE LOWER(name) = LOWER(?)
           AND (city = ? OR city LIKE ?)' . $activeClause . '
         LIMIT 1'
      );
      $stmt->execute([$canonicalBarangay, $city, 'Bago%']);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row && $row['latitude'] !== null && $row['longitude'] !== null) {
        $lat = (float) $row['latitude'];
        $lng = (float) $row['longitude'];
        if ($this->validCoordinate($lat, $lng)) {
          return ['lat' => $lat, 'lng' => $lng];
        }
      }
    }

    return null;
  }

  /**
   * @return array<string, mixed>
   */
  private function barangayResult(
    string $canonicalBarangay,
    string $displayAddress,
    string $addressConfidence,
    float $lat,
    float $lng
  ): array {
    $result = $this->result(
      $lat,
      $lng,
      'barangay_center',
      'approximate',
      $displayAddress,
      $addressConfidence,
      $canonicalBarangay,
      'Exact patient location is unavailable. Marker shows the verified barangay center.'
    );
    $result['barangay_center_label'] = 'Barangay ' . $canonicalBarangay . ' center';

    return $result;
  }

  /**
   * @return array<string, mixed>
   */
  private function result(
    ?float $lat,
    ?float $lng,
    string $locationSource,
    string $locationAccuracy,
    string $displayAddress,
    string $addressConfidence,
    string $canonicalBarangay,
    string $locationNote
  ): array {
    return [
      'latitude'              => $lat,
      'longitude'             => $lng,
      'location_source'       => $locationSource,
      'location_accuracy'     => $locationAccuracy,
      'display_address'       => $displayAddress,
      'address'               => $displayAddress,
      'address_confidence'    => $addressConfidence,
      'canonical_barangay'    => $canonicalBarangay,
      'barangay'              => $canonicalBarangay,
      'location_note'         => $locationNote,
      'barangay_center_label' => null,
      'has_map_marker'        => $lat !== null && $lng !== null,
    ];
  }

  private function geocoder(): AddressGeocoder
  {
    if ($this->geocoder === null) {
      require_once dirname(__DIR__) . '/core/AddressGeocoder.php';
      $this->geocoder = new AddressGeocoder($this->pdo);
    }

    return $this->geocoder;
  }

  private function normalizeStoredSource(string $source): string
  {
    $source = strtolower(trim($source));

    return match ($source) {
      'gps', 'manual', 'imported', 'address_geocoded', 'barangay_centroid', 'barangay_center', 'unavailable' => $source,
      default => 'barangay_centroid',
    };
  }

  private function parseCoordinate(mixed $value): ?float
  {
    if ($value === null || $value === '') {
      return null;
    }

    return is_numeric($value) ? (float) $value : null;
  }

  private function validCoordinate(float $lat, float $lng): bool
  {
    return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
      && !($lat == 0.0 && $lng == 0.0);
  }

  private function tableExists(string $table): bool
  {
    $table = preg_replace('/[^a-z0-9_]/i', '', $table);
    if ($table === '') {
      return false;
    }

    $stmt = $this->pdo->query(
      'SELECT 1 FROM information_schema.tables
       WHERE table_schema = DATABASE() AND table_name = '
      . $this->pdo->quote($table)
      . ' LIMIT 1'
    );

    return (bool) $stmt?->fetchColumn();
  }

  private function columnExists(string $table, string $column): bool
  {
    $table = preg_replace('/[^a-z0-9_]/i', '', $table);
    $column = preg_replace('/[^a-z0-9_]/i', '', $column);
    if ($table === '' || $column === '') {
      return false;
    }

    $stmt = $this->pdo->prepare(
      'SELECT 1 FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $stmt->execute([$table, $column]);

    return (bool) $stmt->fetchColumn();
  }
}

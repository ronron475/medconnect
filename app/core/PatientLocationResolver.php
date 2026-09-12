<?php
/**
 * Smart patient location resolution — GPS, geocoded address, purok/barangay center, or unavailable.
 *
 * Priority (never invent nearest-map barangay/purok):
 *   Verified patient coordinates → Registered Purok → Registered Barangay → unavailable
 *
 * Registered barangay/purok from the patient record always drive the displayed place name.
 * City-center coordinates are never used as a fallback for non-matching barangays.
 */
final class PatientLocationResolver
{
  private PDO $pdo;

  private ?AddressGeocoder $geocoder = null;

  /** Max km from registered barangay center before GPS is treated as a mismatch. */
  private const MISMATCH_KM = 3.5;

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

    $purok = $this->resolvePurokLabel($row);
    $displayAddress = PatientAddressFormatter::build($row);
    $addressConfidence = PatientAddressFormatter::confidence($row);

    $storedLat = $this->parseCoordinate($row['latitude'] ?? null);
    $storedLng = $this->parseCoordinate($row['longitude'] ?? null);
    $storedSource = $this->normalizeStoredSource((string) ($row['location_source'] ?? ''));
    $invalidStored = false;

    if ($storedLat !== null && $storedLng !== null) {
      if (!$this->validCoordinate($storedLat, $storedLng) || !$this->inCityBounds($storedLat, $storedLng)) {
        $invalidStored = true;
        $storedLat = null;
        $storedLng = null;
      }
    }

    if ($storedLat !== null && $storedLng !== null) {
      if (in_array($storedSource, ['gps', 'manual', 'imported'], true)) {
        $mismatch = $this->coordinateBarangayMismatch($storedLat, $storedLng, $canonicalBarangay);
        if ($mismatch !== null) {
          return $this->needsVerificationResult(
            $canonicalBarangay,
            $purok,
            $displayAddress,
            $addressConfidence,
            $invalidStored,
            'Stored coordinates fall nearer to Barangay '
              . $mismatch['inferred']
              . ' than registered Barangay '
              . $canonicalBarangay
              . '. Flagged for validation; map uses registered barangay/purok reference.'
          );
        }

        return $this->result(
          $storedLat,
          $storedLng,
          'gps',
          'exact',
          $displayAddress,
          $addressConfidence,
          $canonicalBarangay,
          'Verified patient GPS coordinates.',
          'EXACT_LOCATION',
          $purok,
          'Exact Location — Verified Coordinates'
        );
      }

      if ($storedSource === 'address_geocoded') {
        $mismatch = $this->coordinateBarangayMismatch($storedLat, $storedLng, $canonicalBarangay);
        if ($mismatch !== null) {
          return $this->needsVerificationResult(
            $canonicalBarangay,
            $purok,
            $displayAddress,
            $addressConfidence,
            $invalidStored,
            'Geocoded coordinates conflict with registered Barangay '
              . $canonicalBarangay
              . ' (closer to '
              . $mismatch['inferred']
              . '). Flagged for validation.'
          );
        }

        return $this->result(
          $storedLat,
          $storedLng,
          'address_geocoded',
          'geocoded',
          $displayAddress,
          $addressConfidence,
          $canonicalBarangay,
          'Location derived from the registered address and validated against the registered barangay.',
          'GEOCODED_LOCATION',
          $purok,
          $purok !== ''
            ? 'Purok Location — Approximate'
            : 'Barangay Location — Approximate'
        );
      }

      if (in_array($storedSource, ['purok_center', 'purok_centroid'], true) && $canonicalBarangay !== '') {
        return $this->purokOrBarangayFallback(
          $canonicalBarangay,
          $purok,
          $displayAddress,
          $addressConfidence,
          $storedLat,
          $storedLng,
          $invalidStored
        );
      }

      if ($storedSource === 'barangay_centroid' && $canonicalBarangay !== '') {
        return $this->purokOrBarangayFallback(
          $canonicalBarangay,
          $purok,
          $displayAddress,
          $addressConfidence,
          $storedLat,
          $storedLng,
          $invalidStored
        );
      }

      if ($storedSource === 'barangay_centroid' && $canonicalBarangay === '') {
        // Ignore legacy centroid rows tied to invalid barangay values (e.g. city-center fallbacks).
      } elseif ($storedSource === 'barangay_center' && $canonicalBarangay !== '') {
        return $this->purokOrBarangayFallback(
          $canonicalBarangay,
          $purok,
          $displayAddress,
          $addressConfidence,
          $storedLat,
          $storedLng,
          $invalidStored
        );
      } elseif ($storedSource === 'needs_verification' && $canonicalBarangay !== '') {
        return $this->needsVerificationResult(
          $canonicalBarangay,
          $purok,
          $displayAddress,
          $addressConfidence,
          $invalidStored,
          (string) ($row['location_note'] ?? 'Location needs verification against registered barangay/purok.')
        );
      }
    }

    // Live geocode is constrained to the registered barangay — never accept a nearer wrong barangay.
    if ($allowLiveGeocode && in_array($addressConfidence, ['HIGH', 'MEDIUM'], true) && $displayAddress !== '' && $canonicalBarangay !== '') {
      $geocoded = $this->geocoder()->geocode(
        $displayAddress . ', Philippines',
        $addressConfidence,
        $canonicalBarangay
      );
      if ($geocoded !== null) {
        $mismatch = $this->coordinateBarangayMismatch($geocoded['lat'], $geocoded['lng'], $canonicalBarangay);
        if ($mismatch === null) {
          return $this->result(
            $geocoded['lat'],
            $geocoded['lng'],
            'address_geocoded',
            'geocoded',
            $displayAddress,
            $addressConfidence,
            $canonicalBarangay,
            'Location derived from the registered address and validated against the registered barangay.',
            'GEOCODED_LOCATION',
            $purok,
            $purok !== ''
              ? 'Purok Location — Approximate'
              : 'Barangay Location — Approximate'
          );
        }
      }
    }

    if ($canonicalBarangay !== '') {
      $coords = $this->lookupBarangayCoordinates($canonicalBarangay, (string) ($row['city_municipality'] ?? ($row['municipality'] ?? 'Bago City')));
      if ($coords !== null) {
        // Never substitute city-center coords for a non-Poblacion barangay.
        if ($this->isCityCenterFallback($coords['lat'], $coords['lng'], $canonicalBarangay)) {
          return $this->result(
            null,
            null,
            'unavailable',
            'unavailable',
            $displayAddress,
            $addressConfidence,
            $canonicalBarangay,
            'Barangay reference coordinates were unavailable; city center was not used as a substitute.',
            'MISSING_LOCATION',
            $purok,
            'Location Needs Verification'
          );
        }

        return $this->purokOrBarangayFallback(
          $canonicalBarangay,
          $purok,
          $displayAddress,
          $addressConfidence,
          $coords['lat'],
          $coords['lng'],
          $invalidStored
        );
      }
    }

    return $this->result(
      null,
      null,
      'unavailable',
      $invalidStored ? 'invalid' : 'unavailable',
      $displayAddress,
      $addressConfidence,
      $canonicalBarangay,
      $invalidStored
        ? 'Stored coordinates were invalid or outside Bago City and were not plotted.'
        : 'Exact patient location is unavailable.',
      $invalidStored ? 'INVALID_LOCATION' : 'MISSING_LOCATION',
      $purok,
      $invalidStored ? 'Location Needs Verification' : 'Location unavailable'
    );
  }

  /**
   * @param array<string, mixed> $row
   */
  private function resolveCanonicalBarangay(array $row): string
  {
    // Registered patient barangay first — never prefer map-inferred names from patient_locations.
    $candidates = [
      (string) ($row['pr_barangay'] ?? ''),
      (string) ($row['barangay'] ?? ''),
      (string) ($row['canonical_barangay'] ?? ''),
      (string) ($row['pl_barangay'] ?? ''),
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

  /**
   * @param array<string, mixed> $row
   */
  private function resolvePurokLabel(array $row): string
  {
    $purok = PatientAddressFormatter::cleanPart($row['purok'] ?? '');
    if ($purok === '') {
      $parts = PatientAddressFormatter::parts($row);
      foreach ($parts as $part) {
        if (preg_match('/^purok\s+/i', $part)) {
          return $part;
        }
      }

      return '';
    }

    if (preg_match('/^purok\s+/i', $purok)) {
      return $purok;
    }

    return 'Purok ' . $purok;
  }

  private function isInvalidBarangayValue(string $value): bool
  {
    $lower = strtolower(trim($value));
    $blocked = [
      'population', 'male', 'female', 'single', 'married', 'widowed', 'divorced',
      'employed', 'unemployed', 'student', 'unknown', 'null', 'undefined',
      'bago city', 'bago', 'negros occidental',
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

    // Prefer longer official names first so "Ma-ao" is not matched inside other tokens incorrectly.
    $names = BagoBarangayCentroids::barangayNames();
    usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    foreach ($names as $name) {
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
        if ($this->validCoordinate($lat, $lng) && $this->inCityBounds($lat, $lng)) {
          return ['lat' => $lat, 'lng' => $lng];
        }
      }
    }

    return null;
  }

  /**
   * Detect when stored/geocoded coords clearly belong to a different official barangay.
   *
   * @return array{inferred: string, distance_km: float}|null
   */
  private function coordinateBarangayMismatch(float $lat, float $lng, string $canonicalBarangay): ?array
  {
    if ($canonicalBarangay === '') {
      return null;
    }

    $nearest = BagoBarangayCentroids::nearestBarangay($lat, $lng);
    if ($nearest === null) {
      return null;
    }

    if (strcasecmp($nearest['name'], $canonicalBarangay) === 0) {
      return null;
    }

    $registered = BagoBarangayCentroids::resolveBarangayCenter($canonicalBarangay);
    if ($registered === null) {
      return [
        'inferred' => $nearest['name'],
        'distance_km' => $nearest['distance_km'],
      ];
    }

    $toRegistered = BagoBarangayCentroids::distanceKm($lat, $lng, $registered['lat'], $registered['lng']);
    // Mismatch only when another barangay is clearly closer AND registered center is far.
    if ($nearest['distance_km'] + 0.35 < $toRegistered && $toRegistered > self::MISMATCH_KM) {
      return [
        'inferred' => $nearest['name'],
        'distance_km' => $toRegistered,
      ];
    }

    return null;
  }

  private function isCityCenterFallback(float $lat, float $lng, string $canonicalBarangay): bool
  {
    if (strcasecmp($canonicalBarangay, 'Poblacion') === 0) {
      return false;
    }

    $center = BagoBarangayCentroids::cityCenter();
    $dist = BagoBarangayCentroids::distanceKm($lat, $lng, $center['lat'], $center['lng']);

    return $dist < 0.05;
  }

  /**
   * @return array<string, mixed>
   */
  private function purokOrBarangayFallback(
    string $canonicalBarangay,
    string $purok,
    string $displayAddress,
    string $addressConfidence,
    float $lat,
    float $lng,
    bool $invalidStored = false
  ): array {
    if ($purok !== '') {
      $note = 'Exact patient GPS is unavailable. Marker shows the registered purok under verified Barangay '
        . $canonicalBarangay
        . ' (approximate barangay reference).';
      if ($invalidStored) {
        $note = 'Stored coordinates were invalid or outside Bago City. Marker shows registered Purok / Barangay '
          . $canonicalBarangay
          . ' reference.';
      }

      $result = $this->result(
        $lat,
        $lng,
        'purok_center',
        'approximate',
        $displayAddress,
        $addressConfidence,
        $canonicalBarangay,
        $note,
        'PUROK_LOCATION',
        $purok,
        'Purok Location — Approximate'
      );
      $result['barangay_center_label'] = $purok . ', Barangay ' . $canonicalBarangay;

      return $result;
    }

    return $this->barangayResult($canonicalBarangay, $displayAddress, $addressConfidence, $lat, $lng, $invalidStored, $purok);
  }

  /**
   * @return array<string, mixed>
   */
  private function needsVerificationResult(
    string $canonicalBarangay,
    string $purok,
    string $displayAddress,
    string $addressConfidence,
    bool $invalidStored,
    string $note
  ): array {
    $lat = null;
    $lng = null;
    $hasMarker = false;

    if ($canonicalBarangay !== '') {
      $coords = $this->lookupBarangayCoordinates($canonicalBarangay, 'Bago City');
      if ($coords !== null && !$this->isCityCenterFallback($coords['lat'], $coords['lng'], $canonicalBarangay)) {
        $lat = $coords['lat'];
        $lng = $coords['lng'];
        $hasMarker = true;
      }
    }

    $result = $this->result(
      $lat,
      $lng,
      'needs_verification',
      'needs_verification',
      $displayAddress,
      $addressConfidence,
      $canonicalBarangay,
      $note,
      'NEEDS_VERIFICATION',
      $purok,
      'Location Needs Verification'
    );
    $result['has_map_marker'] = $hasMarker;
    if ($hasMarker && $canonicalBarangay !== '') {
      $result['barangay_center_label'] = ($purok !== '' ? $purok . ', ' : '')
        . 'Barangay ' . $canonicalBarangay . ' (needs verification)';
    }
    unset($invalidStored);

    return $result;
  }

  /**
   * @return array<string, mixed>
   */
  private function barangayResult(
    string $canonicalBarangay,
    string $displayAddress,
    string $addressConfidence,
    float $lat,
    float $lng,
    bool $invalidStored = false,
    string $purok = ''
  ): array {
    $note = 'Exact patient location is unavailable. Marker shows the verified barangay center for registered Barangay '
      . $canonicalBarangay . '.';
    if ($invalidStored) {
      $note = 'Stored coordinates were invalid or outside Bago City. Marker shows the verified barangay center for '
        . $canonicalBarangay . '.';
    }
    $result = $this->result(
      $lat,
      $lng,
      'barangay_center',
      'approximate',
      $displayAddress,
      $addressConfidence,
      $canonicalBarangay,
      $note,
      'BARANGAY_LOCATION',
      $purok,
      'Barangay Location — Approximate'
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
    string $locationNote,
    string $locationQuality = 'MISSING_LOCATION',
    string $purok = '',
    string $accuracyLabel = ''
  ): array {
    if ($accuracyLabel === '') {
      $accuracyLabel = match ($locationQuality) {
        'EXACT_LOCATION' => 'Exact Location — Verified Coordinates',
        'PUROK_LOCATION' => 'Purok Location — Approximate',
        'BARANGAY_LOCATION', 'GEOCODED_LOCATION' => 'Barangay Location — Approximate',
        'NEEDS_VERIFICATION', 'INVALID_LOCATION' => 'Location Needs Verification',
        default => 'Location unavailable',
      };
    }

    return [
      'latitude'                => $lat,
      'longitude'               => $lng,
      'location_source'         => $locationSource,
      'location_accuracy'       => $locationAccuracy,
      'location_quality'        => $locationQuality,
      'location_accuracy_label' => $accuracyLabel,
      'display_address'         => $displayAddress,
      'address'                 => $displayAddress,
      'address_confidence'      => $addressConfidence,
      'canonical_barangay'      => $canonicalBarangay,
      'barangay'                => $canonicalBarangay,
      'purok'                   => $purok,
      'location_note'           => $locationNote,
      'barangay_center_label'   => null,
      'has_map_marker'          => $lat !== null && $lng !== null,
      'location_needs_verification' => $locationQuality === 'NEEDS_VERIFICATION',
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
      'gps', 'manual', 'imported', 'address_geocoded', 'barangay_centroid', 'barangay_center',
      'purok_center', 'purok_centroid', 'needs_verification', 'unavailable' => $source,
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

  private function inCityBounds(float $lat, float $lng): bool
  {
    $bounds = BagoBarangayCentroids::cityBounds();

    return $lat >= $bounds['south'] && $lat <= $bounds['north']
      && $lng >= $bounds['west'] && $lng <= $bounds['east'];
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

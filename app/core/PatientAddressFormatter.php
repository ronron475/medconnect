<?php
/**
 * Build normalized patient display addresses from registration fields.
 */
final class PatientAddressFormatter
{
  private const DEFAULT_CITY = 'Bago City';
  private const DEFAULT_PROVINCE = 'Negros Occidental';

  /**
   * @param array<string, mixed> $row
   */
  public static function build(array $row): string
  {
    $parts = self::parts($row);

    return implode(', ', $parts);
  }

  /**
   * @param array<string, mixed> $row
   * @return list<string>
   */
  public static function parts(array $row): array
  {
    $house = self::cleanPart($row['house_number'] ?? '');
    $street = self::cleanPart($row['street'] ?? ($row['street_address'] ?? ''));
    $purok = self::normalizePurok(self::cleanPart($row['purok'] ?? ''));
    $sitio = self::normalizeSitio(self::cleanPart($row['sitio'] ?? ''));
    $barangay = self::formatBarangayLabel(self::cleanPart($row['canonical_barangay'] ?? ($row['barangay'] ?? '')));
    $city = self::formatCityLabel(self::cleanPart($row['city_municipality'] ?? ($row['municipality'] ?? self::DEFAULT_CITY)));
    $province = self::formatProvinceLabel(self::cleanPart($row['province'] ?? self::DEFAULT_PROVINCE));

    if ($street === '' && !empty($row['address'])) {
      $street = self::extractStreetFromAddress((string) $row['address'], $barangay, $city, $province);
    }

    if ($purok === '' && $sitio === '' && !empty($row['address'])) {
      self::extractPurokSitioFromAddress((string) $row['address'], $purok, $sitio);
    }

    $ordered = [$house, $street, $purok, $sitio, $barangay, $city, $province];
    $seen = [];
    $parts = [];

    foreach ($ordered as $part) {
      if ($part === '') {
        continue;
      }
      $key = self::normalizeKey($part);
      if ($key === '' || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $parts[] = $part;
    }

    return $parts;
  }

  /**
   * @param array<string, mixed> $row
   * @return 'HIGH'|'MEDIUM'|'LOW'|'INVALID'
   */
  public static function confidence(array $row): string
  {
    require_once dirname(__DIR__) . '/core/BagoBarangayCentroids.php';

    $canonicalBarangay = self::cleanPart($row['canonical_barangay'] ?? '');
    if ($canonicalBarangay === '') {
      $canonicalBarangay = BagoBarangayCentroids::canonicalName((string) ($row['barangay'] ?? '')) ?? '';
    }

    $row['canonical_barangay'] = $canonicalBarangay;
    $parts = self::parts($row);
    if ($parts === []) {
      return 'INVALID';
    }

    $street = self::cleanPart($row['street'] ?? ($row['street_address'] ?? ''));
    $house = self::cleanPart($row['house_number'] ?? '');
    $purok = self::cleanPart($row['purok'] ?? '');
    $sitio = self::cleanPart($row['sitio'] ?? '');

    if ($canonicalBarangay === '') {
      return 'INVALID';
    }

    if ($house !== '' || $street !== '') {
      return 'HIGH';
    }

    if ($purok !== '' || $sitio !== '') {
      return 'MEDIUM';
    }

    if (count($parts) >= 3) {
      return 'LOW';
    }

    return 'INVALID';
  }

  public static function cleanPart(mixed $value): string
  {
    $value = trim((string) $value);
    if ($value === '' || in_array(strtolower($value), ['null', 'undefined', 'n/a', 'na', 'none'], true)) {
      return '';
    }

    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return self::titleCase($value);
  }

  public static function formatBarangayLabel(string $barangay): string
  {
    $barangay = self::cleanPart($barangay);
    if ($barangay === '') {
      return '';
    }

    if (preg_match('/^barangay\s+/i', $barangay)) {
      return self::titleCase($barangay);
    }

    return 'Barangay ' . self::titleCase(preg_replace('/^(brgy\.?|barangay)\s+/i', '', $barangay) ?? $barangay);
  }

  private static function formatCityLabel(string $city): string
  {
    $city = self::cleanPart($city);
    if ($city === '') {
      return self::DEFAULT_CITY;
    }

    if (preg_match('/city$/i', $city)) {
      return self::titleCase($city);
    }

    return self::titleCase($city) . ' City';
  }

  private static function formatProvinceLabel(string $province): string
  {
    $province = self::cleanPart($province);

    return $province !== '' ? self::titleCase($province) : self::DEFAULT_PROVINCE;
  }

  private static function normalizePurok(string $value): string
  {
    if ($value === '') {
      return '';
    }

    if (preg_match('/^purok\s+/i', $value)) {
      return self::titleCase($value);
    }

    return 'Purok ' . self::titleCase($value);
  }

  private static function normalizeSitio(string $value): string
  {
    if ($value === '') {
      return '';
    }

    if (preg_match('/^sitio\s+/i', $value)) {
      return self::titleCase($value);
    }

    return 'Sitio ' . self::titleCase($value);
  }

  private static function extractStreetFromAddress(
    string $address,
    string $barangay,
    string $city,
    string $province
  ): string {
    $chunks = self::splitAddress($address);
    $skip = array_map(
      static fn (string $part): string => self::normalizeKey($part),
      array_filter([$barangay, $city, $province])
    );

    foreach ($chunks as $chunk) {
      $key = self::normalizeKey($chunk);
      if ($key === '' || in_array($key, $skip, true)) {
        continue;
      }
      if (preg_match('/^(barangay|brgy|purok|sitio|bago city|negros occidental)/i', $chunk)) {
        continue;
      }

      return self::cleanPart($chunk);
    }

    return '';
  }

  /**
   * @param-out string $purok
   * @param-out string $sitio
   */
  private static function extractPurokSitioFromAddress(string $address, string &$purok, string &$sitio): void
  {
    foreach (self::splitAddress($address) as $chunk) {
      if ($purok === '' && preg_match('/^purok\s+(.+)$/i', $chunk, $m)) {
        $purok = self::normalizePurok(self::cleanPart($m[1]));
      }
      if ($sitio === '' && preg_match('/^sitio\s+(.+)$/i', $chunk, $m)) {
        $sitio = self::normalizeSitio(self::cleanPart($m[1]));
      }
    }
  }

  /**
   * @return list<string>
   */
  private static function splitAddress(string $address): array
  {
    $chunks = array_map('trim', preg_split('/[,;]+/', $address) ?: []);

    return array_values(array_filter($chunks, static fn (string $part): bool => $part !== ''));
  }

  private static function normalizeKey(string $value): string
  {
    $value = strtolower(trim($value));
    $value = preg_replace('/\s*(brgy\.?|barangay|purok|sitio)\s*/', '', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;

    return $value;
  }

  private static function titleCase(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
  }
}

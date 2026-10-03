<?php
/**
 * Encryption at rest for patient-uploaded files (AES-256-GCM).
 *
 * Key source (first match wins), never stored in Git, the database, or storage/:
 *   - MEDCONNECT_FILE_ENCRYPTION_KEY_FILE: absolute path to a file holding the base64 key
 *   - MEDCONNECT_FILE_ENCRYPTION_KEY: base64 key (optionally prefixed "base64:")
 * The decoded key must be exactly 32 bytes. Generate one with
 *   php scripts/security/generate_file_encryption_key.php
 *
 * On-disk format:
 *   magic "MCENC" + version 0x01 (6) | key id (8) | salt (16) | iv (12) | tag (16) | ciphertext
 * Per-file key = HKDF-SHA256(master, salt). The header is authenticated as AAD.
 *
 * Files without the magic header are legacy plaintext and are returned unchanged.
 */
declare(strict_types=1);

const MC_FILE_ENC_MAGIC = "MCENC\x01";
const MC_FILE_ENC_CIPHER = 'aes-256-gcm';
const MC_FILE_ENC_HKDF_INFO = 'medconnect-file-encryption-v1';
const MC_FILE_ENC_KEY_ID_LEN = 8;
const MC_FILE_ENC_SALT_LEN = 16;
const MC_FILE_ENC_IV_LEN = 12;
const MC_FILE_ENC_TAG_LEN = 16;

final class MedconnectFileEncryptionException extends RuntimeException
{
}

function mc_file_encryption_header_len(): int
{
    return strlen(MC_FILE_ENC_MAGIC) + MC_FILE_ENC_KEY_ID_LEN + MC_FILE_ENC_SALT_LEN + MC_FILE_ENC_IV_LEN + MC_FILE_ENC_TAG_LEN;
}

/**
 * putenv() values are process-wide and cleared at request end, so under threaded
 * Apache (XAMPP on Windows) a concurrent request can lose them mid-request.
 * Fall back to reading the project .env directly for the encryption settings.
 */
function mc_file_encryption_dotenv_value(string $name): string
{
    static $values = null;
    if ($values === null) {
        $values = [];
        $lines = @file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (!str_starts_with($key, 'MEDCONNECT_FILE_ENCRYPTION_')) {
                continue;
            }
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }
    }
    return $values[$name] ?? '';
}

function mc_file_encryption_env(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        $value = $_ENV[$name] ?? '';
    }
    $value = trim((string) $value);
    return $value !== '' ? $value : trim(mc_file_encryption_dotenv_value($name));
}

/** Log a failure reason only; never key material, file contents, filenames, or patient data. */
function mc_file_encryption_log(string $reason): void
{
    error_log('medconnect file_encryption: ' . $reason);
}

function mc_file_encryption_decode_key(string $raw): ?string
{
    $raw = trim($raw);
    if (str_starts_with($raw, 'base64:')) {
        $raw = substr($raw, 7);
    }
    $key = base64_decode($raw, true);
    if ($key === false || strlen($key) !== 32) {
        return null;
    }
    return $key;
}

/** True when $path resolves inside the uploaded-file storage tree. */
function mc_file_encryption_path_in_storage(string $path): bool
{
    $real = realpath($path);
    if ($real === false) {
        return false;
    }
    $roots = [];
    if (defined('STORAGE_PATH')) {
        $roots[] = STORAGE_PATH;
    }
    $roots[] = dirname(__DIR__, 2) . '/storage';
    $file = strtolower(str_replace('\\', '/', $real));
    foreach ($roots as $root) {
        $rootReal = realpath($root);
        if ($rootReal === false) {
            continue;
        }
        $rootNorm = rtrim(strtolower(str_replace('\\', '/', $rootReal)), '/') . '/';
        if (str_starts_with($file, $rootNorm)) {
            return true;
        }
    }
    return false;
}

/**
 * Master key (32 bytes) or null when not configured / invalid.
 */
function mc_file_encryption_key(): ?string
{
    static $resolved = false;
    static $key = null;
    if ($resolved) {
        return $key;
    }
    $resolved = true;

    $keyFile = mc_file_encryption_env('MEDCONNECT_FILE_ENCRYPTION_KEY_FILE');
    if ($keyFile !== '') {
        if (mc_file_encryption_path_in_storage($keyFile)) {
            mc_file_encryption_log('key file must not be inside the storage directory');
            return $key = null;
        }
        if (!is_file($keyFile) || !is_readable($keyFile)) {
            mc_file_encryption_log('key file is missing or unreadable');
            return $key = null;
        }
        $key = mc_file_encryption_decode_key((string) file_get_contents($keyFile));
        if ($key === null) {
            mc_file_encryption_log('key file does not contain a valid base64 32-byte key');
        }
        return $key;
    }

    $envKey = mc_file_encryption_env('MEDCONNECT_FILE_ENCRYPTION_KEY');
    if ($envKey !== '') {
        $key = mc_file_encryption_decode_key($envKey);
        if ($key === null) {
            mc_file_encryption_log('MEDCONNECT_FILE_ENCRYPTION_KEY is not a valid base64 32-byte key');
        }
    }
    return $key;
}

function mc_file_encryption_available(): bool
{
    return mc_file_encryption_key() !== null && in_array(MC_FILE_ENC_CIPHER, openssl_get_cipher_methods(), true);
}

function mc_file_encryption_key_id(string $masterKey): string
{
    return substr(hash_hmac('sha256', 'medconnect-file-key-id', $masterKey, true), 0, MC_FILE_ENC_KEY_ID_LEN);
}

function mc_file_is_encrypted_blob(string $data): bool
{
    return strncmp($data, MC_FILE_ENC_MAGIC, strlen(MC_FILE_ENC_MAGIC)) === 0;
}

function mc_file_is_encrypted(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    $head = (string) fread($fh, strlen(MC_FILE_ENC_MAGIC));
    fclose($fh);
    return mc_file_is_encrypted_blob($head);
}

/**
 * @throws MedconnectFileEncryptionException
 */
function mc_file_encrypt_bytes(string $plaintext, ?string $masterKey = null): string
{
    $masterKey = $masterKey ?? mc_file_encryption_key();
    if ($masterKey === null) {
        throw new MedconnectFileEncryptionException('File encryption key is not configured.');
    }
    $salt = random_bytes(MC_FILE_ENC_SALT_LEN);
    $iv = random_bytes(MC_FILE_ENC_IV_LEN);
    $fileKey = hash_hkdf('sha256', $masterKey, 32, MC_FILE_ENC_HKDF_INFO, $salt);
    $aadHeader = MC_FILE_ENC_MAGIC . mc_file_encryption_key_id($masterKey) . $salt . $iv;

    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, MC_FILE_ENC_CIPHER, $fileKey, OPENSSL_RAW_DATA, $iv, $tag, $aadHeader, MC_FILE_ENC_TAG_LEN);
    if ($ciphertext === false || strlen($tag) !== MC_FILE_ENC_TAG_LEN) {
        throw new MedconnectFileEncryptionException('Encryption failed.');
    }
    return $aadHeader . $tag . $ciphertext;
}

/**
 * @throws MedconnectFileEncryptionException
 */
function mc_file_decrypt_bytes(string $blob, ?string $masterKey = null): string
{
    $masterKey = $masterKey ?? mc_file_encryption_key();
    if ($masterKey === null) {
        throw new MedconnectFileEncryptionException('File encryption key is not configured.');
    }
    if (!mc_file_is_encrypted_blob($blob) || strlen($blob) < mc_file_encryption_header_len()) {
        throw new MedconnectFileEncryptionException('Encrypted file is malformed.');
    }

    $offset = strlen(MC_FILE_ENC_MAGIC);
    $keyId = substr($blob, $offset, MC_FILE_ENC_KEY_ID_LEN);
    $offset += MC_FILE_ENC_KEY_ID_LEN;
    $salt = substr($blob, $offset, MC_FILE_ENC_SALT_LEN);
    $offset += MC_FILE_ENC_SALT_LEN;
    $iv = substr($blob, $offset, MC_FILE_ENC_IV_LEN);
    $offset += MC_FILE_ENC_IV_LEN;
    $tag = substr($blob, $offset, MC_FILE_ENC_TAG_LEN);
    $offset += MC_FILE_ENC_TAG_LEN;
    $ciphertext = substr($blob, $offset);

    if (!hash_equals(mc_file_encryption_key_id($masterKey), $keyId)) {
        throw new MedconnectFileEncryptionException('File was encrypted with a different key.');
    }

    $fileKey = hash_hkdf('sha256', $masterKey, 32, MC_FILE_ENC_HKDF_INFO, $salt);
    $aadHeader = MC_FILE_ENC_MAGIC . $keyId . $salt . $iv;
    $plaintext = openssl_decrypt($ciphertext, MC_FILE_ENC_CIPHER, $fileKey, OPENSSL_RAW_DATA, $iv, $tag, $aadHeader);
    if ($plaintext === false) {
        throw new MedconnectFileEncryptionException('Encrypted file failed integrity verification.');
    }
    return $plaintext;
}

/**
 * Encrypt $sourcePath and write it to $destPath (temp file + verified round trip + rename).
 * The source file is left in place; callers own its cleanup.
 *
 * @throws MedconnectFileEncryptionException
 */
function mc_file_store_encrypted(string $sourcePath, string $destPath, int $mode = 0640): void
{
    $plaintext = @file_get_contents($sourcePath);
    if ($plaintext === false) {
        throw new MedconnectFileEncryptionException('Source file is unreadable.');
    }
    $blob = mc_file_encrypt_bytes($plaintext);
    if (!hash_equals(hash('sha256', $plaintext), hash('sha256', mc_file_decrypt_bytes($blob)))) {
        throw new MedconnectFileEncryptionException('Encryption verification failed.');
    }

    $tmp = $destPath . '.mcenc-' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $blob, LOCK_EX) !== strlen($blob)) {
        @unlink($tmp);
        throw new MedconnectFileEncryptionException('Could not write encrypted file.');
    }
    if (!@rename($tmp, $destPath)) {
        @unlink($tmp);
        throw new MedconnectFileEncryptionException('Could not finalize encrypted file.');
    }
    @chmod($destPath, $mode);
}

/**
 * Contents of a stored patient file: decrypted when encrypted, unchanged when legacy plaintext.
 *
 * @throws MedconnectFileEncryptionException
 */
function mc_file_read_decrypted(string $path): string
{
    $data = @file_get_contents($path);
    if ($data === false) {
        throw new MedconnectFileEncryptionException('Stored file is unreadable.');
    }
    if (!mc_file_is_encrypted_blob($data)) {
        return $data;
    }
    return mc_file_decrypt_bytes($data);
}

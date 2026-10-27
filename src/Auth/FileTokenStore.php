<?php
namespace agumil\SatuSehatSDK\Auth;

use agumil\SatuSehatSDK\Exception\SSEnvException;

/**
 * Store berbasis file untuk dipakai lintas proses (PHP-FPM, CLI worker).
 *
 * Token disimpan sebagai JSON dengan permission 0600 dan ditulis secara atomik
 * (tulis ke file sementara lalu rename), sehingga proses lain tidak pernah
 * membaca file setengah jadi. Refresh dilakukan di bawah flock eksklusif agar
 * hanya satu proses yang meminta token baru saat token kedaluwarsa.
 */
class FileTokenStore implements TokenStoreInterface
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function get(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        $raw = file_get_contents($this->path);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['token']) || !isset($data['expires_at'])) {
            return null;
        }

        return [
            'token' => (string) $data['token'],
            'expires_at' => (int) $data['expires_at'],
        ];
    }

    public function set(string $token, int $expiresAt): void
    {
        $this->ensureDirectory();

        $payload = json_encode([
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        $tmp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($tmp, $payload) === false) {
            throw new SSEnvException("Unable to write token store at {$this->path}.");
        }

        // Access token adalah kredensial: batasi baca hanya untuk pemilik proses.
        // Windows tidak mengenal bit permission POSIX, chmod() di sana hanya
        // mengatur flag read-only, jadi proteksi bergantung pada ACL direktori.
        if (DIRECTORY_SEPARATOR === '\\') {
            @chmod($tmp, 0600);
        } elseif (!chmod($tmp, 0600)) {
            // Menulis kredensial dengan permission yang tidak diketahui lebih
            // berbahaya daripada gagal, jadi file sementara dibuang.
            unlink($tmp);

            throw new SSEnvException("Unable to restrict permission of token store at {$this->path} to 0600.");
        }

        if (!rename($tmp, $this->path)) {
            unlink($tmp);

            throw new SSEnvException("Unable to replace token store at {$this->path}.");
        }
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function refresh(callable $fetch): array
    {
        $this->ensureDirectory();

        $lock = fopen($this->path . '.lock', 'c');

        // Tanpa file kunci tetap jalan, hanya kehilangan jaminan single-flight.
        if ($lock === false) {
            return $fetch();
        }

        try {
            flock($lock, LOCK_EX);

            // Proses lain mungkin sudah memperbarui token selagi kita menunggu
            // kunci, jadi store dibaca ulang sebelum memanggil endpoint token.
            $cached = $this->get();

            if ($cached !== null && time() < $cached['expires_at']) {
                return $cached;
            }

            $data = $fetch();
            $this->set($data['token'], $data['expires_at']);

            return $data;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->path);

        if ($dir !== '' && $dir !== '.' && !is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new SSEnvException("Unable to create token store directory {$dir}.");
        }
    }
}

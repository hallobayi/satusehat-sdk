<?php
namespace agumil\SatuSehatSDK\Helper;

/**
 * Penyaring data pribadi pasien (PHI) sebelum masuk ke log.
 *
 * SDK ini mengirim payload FHIR yang berisi nama, NIK, tanggal lahir, alamat,
 * dan kontak. Bila objek itu dicatat apa adanya, log aplikasi berubah menjadi
 * salinan data pasien — persis yang dihindari UU No. 27/2022 (PDP).
 */
class PhiRedactor
{
    const MASK = '[REDACTED]';

    /**
     * Dicocokkan sebagai substring pada nama kunci, tanpa peduli besar/kecil
     * huruf, supaya `birthDate`, `birthdate`, dan `birthdate.from` sama-sama
     * tertutup.
     */
    const SENSITIVE_KEYS = [
        'identifier',
        'nik',
        'name',
        'birthdate',
        'deceaseddatetime',
        'telecom',
        'address',
        'photo',
        'contact',
    ];

    /**
     * Mengganti nilai pada kunci sensitif dengan [REDACTED], menyisakan
     * struktur payload agar log tetap bisa dipakai menelusuri masalah.
     *
     * @param mixed $data
     *
     * @return mixed
     */
    public static function redact($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $result[$key] = self::MASK;

                continue;
            }

            $result[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $result;
    }

    /**
     * Menyamarkan nilai query string yang sensitif, mis. `?identifier=nik|...`
     * pada pencarian Patient.
     */
    public static function redactUrl(string $url): string
    {
        $query_start = strpos($url, '?');

        if ($query_start === false) {
            return $url;
        }

        parse_str(substr($url, $query_start + 1), $params);

        return substr($url, 0, $query_start) . '?' . http_build_query(self::redact($params));
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (strpos($key, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}

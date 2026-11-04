<?php

namespace App\Support;

use RuntimeException;

/**
 * Cadangan database sebelum tindakan berbahaya (mis. kosongkan data).
 * SQLite: salinan berkas. MySQL/MariaDB: mysqldump/mariadb-dump terkompresi gzip.
 * Disimpan di storage/app/cadangan/ (tidak bisa diakses dari web).
 */
final class CadanganDb
{
    /** @return string jalur berkas cadangan */
    public static function buat(string $label): string
    {
        $c = config('database.connections.'.config('database.default'));
        $dir = storage_path('app/cadangan');
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true)) {
            throw new RuntimeException("Folder cadangan tidak bisa dibuat: $dir");
        }
        $dasar = $dir.'/'.$label.'-'.now()->format('Ymd-His');

        if ($c['driver'] === 'sqlite') {
            if ($c['database'] === ':memory:') {
                return '(database memori — tanpa cadangan)';
            }
            if (! @copy($c['database'], $dasar.'.sqlite')) {
                throw new RuntimeException('Gagal menyalin berkas database SQLite.');
            }

            @chmod($dasar.'.sqlite', 0640);

            return $dasar.'.sqlite';
        }
        if (! in_array($c['driver'], ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Cadangan otomatis hanya untuk SQLite/MySQL/MariaDB.');
        }
        $bin = trim((string) shell_exec('command -v mariadb-dump || command -v mysqldump'));
        if ($bin === '') {
            throw new RuntimeException('mysqldump/mariadb-dump tidak ditemukan di server.');
        }
        $cnf = tempnam(sys_get_temp_dir(), 'cdb');
        chmod($cnf, 0600);
        $q = fn ($v) => '"'.addcslashes((string) $v, '"\\').'"';
        $isi = "[client]\nuser={$q($c['username'])}\npassword={$q($c['password'])}\n";
        if (! empty($c['unix_socket'])) {
            $isi .= "socket={$q($c['unix_socket'])}\n";
        } else {
            $isi .= "host={$q($c['host'])}\nport=".(int) ($c['port'] ?: 3306)."\n";
        }
        file_put_contents($cnf, $isi);
        $berkas = $dasar.'.sql.gz';
        $perintah = 'set -o pipefail; '.escapeshellarg($bin).' --defaults-extra-file='.escapeshellarg($cnf)
            .' --single-transaction --quick --routines --no-tablespaces '.escapeshellarg($c['database'])
            .' | gzip > '.escapeshellarg($berkas);
        exec('bash -c '.escapeshellarg($perintah).' 2>&1', $keluaran, $kode);
        @unlink($cnf);
        if ($kode !== 0 || ! is_file($berkas) || filesize($berkas) < 100) {
            @unlink($berkas);
            throw new RuntimeException('Cadangan gagal: '.mb_substr(implode(' ', $keluaran), 0, 300));
        }
        @chmod($berkas, 0640);

        return $berkas;
    }
}

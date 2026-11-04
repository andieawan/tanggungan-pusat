<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class BuatAdmin extends Command
{
    protected $signature = 'tanggungan:buat-admin {username} {--nama=Administrator Tanggungan} {--password=}';

    protected $description = 'Buat/atur admin lokal darurat (login dengan password; login normal lewat Pusat)';

    public function handle(): int
    {
        $username = strtolower(trim((string) $this->argument('username')));
        $password = (string) ($this->option('password') ?: $this->secret('Password (minimal 12 karakter)'));
        if (mb_strlen($password) < 12) {
            $this->error('Password minimal 12 karakter.');

            return self::FAILURE;
        }
        $u = User::firstOrNew(['username' => $username]);
        $u->fill(['nama' => $u->exists ? $u->nama : (string) $this->option('nama'), 'roles' => ['admin'], 'aktif' => true, 'password' => $password])->save();
        $this->info("Admin lokal \"$username\" siap.");

        return self::SUCCESS;
    }
}

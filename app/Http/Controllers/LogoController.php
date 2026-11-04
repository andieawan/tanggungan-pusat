<?php

namespace App\Http\Controllers;

use App\Services\ProfilPusat;

/** Logo sekolah untuk kop surat: dari Profil Sekolah di Pusat bila ada, selain itu logo bawaan. */
class LogoController extends Controller
{
    public function __invoke()
    {
        $f = ProfilPusat::adaLogo() ? ProfilPusat::logoBerkas() : public_path('assets/logo-ibu.png');
        $tipe = function_exists('mime_content_type') ? (mime_content_type($f) ?: 'image/png') : 'image/png';

        return response()->file($f, ['Content-Type' => str_starts_with($tipe, 'image/') ? $tipe : 'image/png', 'Cache-Control' => 'public, max-age=3600']);
    }
}

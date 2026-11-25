<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** v1.1: login passa a ser sempre minúsculo. Em colisão (raro), o registro mais novo é mantido como está. */
return new class extends Migration
{
    public function up(): void
    {
        $existentes = DB::table('usuario')->pluck('id', 'login');
        foreach ($existentes as $login => $id) {
            $baixo = mb_strtolower(trim((string) $login));
            if ($baixo === $login) {
                continue;
            }
            $ocupado = DB::table('usuario')->whereRaw('LOWER(login) = ?', [$baixo])->where('id', '<>', $id)->exists();
            if (! $ocupado) {
                DB::table('usuario')->where('id', $id)->update(['login' => $baixo]);
            }
        }
    }

    public function down(): void
    {
    }
};

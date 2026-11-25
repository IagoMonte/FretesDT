<?php

namespace Database\Seeders;

use App\Models\Grupo;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Primeiro administrador (API e painel /admin). Login e senha vêm do ambiente:
 * FRETE_ADMIN_LOGIN e FRETE_ADMIN_SENHA. Não há valor padrão.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $login = mb_strtolower(trim((string) env('FRETE_ADMIN_LOGIN')));
        $senha = (string) env('FRETE_ADMIN_SENHA');
        if ($login === '' || $senha === '') {
            throw new RuntimeException('Defina FRETE_ADMIN_LOGIN e FRETE_ADMIN_SENHA no .env antes de rodar o seeder.');
        }

        $admin = Usuario::firstOrCreate(['login' => $login], [
            'nome' => 'Administrador',
            'senha_hash' => Hash::make($senha),
            'situacao' => 'ATIVO',
            'liberado_em' => now(),
            'token_versao' => 0,
        ]);
        foreach (['ADMINISTRADOR', 'GESTAO_ADMIN'] as $codigo) {
            $grupo = Grupo::where('codigo', $codigo)->firstOrFail();
            DB::table('usuario_grupo')->insertOrIgnore(['usuario_id' => $admin->id, 'grupo_id' => $grupo->id]);
        }
    }
}

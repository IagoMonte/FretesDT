<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O painel operacional passa a existir para a transportadora (escopo próprio). O AcessoSeeder já traz a linha,
 * mas rodá-lo em produção refaz a matriz de todos os grupos de sistema; aqui entra só esta permissão, e só se faltar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grupo = DB::table('grupo')->where('codigo', 'TRANSPORTADORA')->first();
        if (! $grupo || ! DB::table('recurso')->where('codigo', 'PAINEL')->exists()) {
            return;
        }
        if (DB::table('grupo_permissao')->where(['grupo_id' => $grupo->id, 'recurso_codigo' => 'PAINEL'])->exists()) {
            return;
        }
        $agora = now()->format('Y-m-d H:i:s.v');
        DB::table('grupo_permissao')->insert([
            'grupo_id' => $grupo->id, 'recurso_codigo' => 'PAINEL',
            'pode_ver' => true, 'pode_inserir' => false, 'pode_alterar' => false, 'pode_cancelar' => false,
            'escopo' => 'TRANSPORTADORA',
            'criado_em' => $agora, 'atualizado_em' => $agora, 'versao' => 0,
        ]);
    }

    public function down(): void
    {
        $grupo = DB::table('grupo')->where('codigo', 'TRANSPORTADORA')->first();
        if ($grupo) {
            DB::table('grupo_permissao')->where(['grupo_id' => $grupo->id, 'recurso_codigo' => 'PAINEL'])->delete();
        }
    }
};

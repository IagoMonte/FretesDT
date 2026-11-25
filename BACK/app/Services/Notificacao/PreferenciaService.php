<?php

namespace App\Services\Notificacao;

use Illuminate\Support\Facades\DB;

/**
 * Matriz evento x canal x grupo. O padrão vem de config/notificacoes.php e a tabela `notificacao_preferencia` sobrepõe.
 * Por padrão todo mundo recebe tudo; o administrador liga/desliga cada célula.
 */
class PreferenciaService
{
    /** Grupos que só existem no WhatsApp (o grupo do cadastro da transportadora não tem e-mail). */
    private const SO_WHATSAPP = ['GRUPO_TRANSPORTADORA'];

    public function canalAdmitido(string $canal, string $grupo): bool
    {
        return $canal === 'WHATSAPP' || ! in_array($grupo, self::SO_WHATSAPP, true);
    }

    /** Grupos que o evento admite em algum canal. @return array<int, string> */
    public function gruposPermitidos(string $evento): array
    {
        $canais = config("notificacoes.eventos.{$evento}.canais", []);

        return array_values(array_unique(array_merge(...array_values($canais) ?: [[]])));
    }

    /**
     * Matriz completa (só combinações que o evento admite) com o valor efetivo.
     *
     * @return array<int, array{evento: string, canal: string, grupo_codigo: string, ativo: bool}>
     */
    public function matriz(): array
    {
        $linhas = [];
        foreach (array_keys(config('notificacoes.eventos')) as $evento) {
            foreach (['WHATSAPP', 'EMAIL'] as $canal) {
                foreach ($this->gruposPermitidos($evento) as $grupo) {
                    if (! $this->canalAdmitido($canal, $grupo)) {
                        continue;
                    }
                    $linhas[] = ['evento' => $evento, 'canal' => $canal, 'grupo_codigo' => $grupo, 'ativo' => $this->ativo($evento, $canal, $grupo)];
                }
            }
        }

        return $linhas;
    }

    public function ativo(string $evento, string $canal, string $grupo): bool
    {
        if (! in_array($grupo, $this->gruposPermitidos($evento), true) || ! $this->canalAdmitido($canal, $grupo)) {
            return false;
        }
        $linha = DB::table('notificacao_preferencia')->where(['evento' => $evento, 'canal' => $canal, 'grupo_codigo' => $grupo])->first();
        if ($linha) {
            return (bool) $linha->ativo;
        }

        return in_array($grupo, config("notificacoes.eventos.{$evento}.canais.{$canal}", []), true);
    }

    /** Upsert em lote. @param  array<int, array{evento: string, canal: string, grupo_codigo: string, ativo: bool}>  $preferencias */
    public function salvar(array $preferencias): void
    {
        $agora = now();
        $uid = auth()->id();
        foreach ($preferencias as $p) {
            $chave = ['evento' => $p['evento'], 'canal' => $p['canal'], 'grupo_codigo' => $p['grupo_codigo']];
            if (DB::table('notificacao_preferencia')->where($chave)->exists()) {
                DB::table('notificacao_preferencia')->where($chave)->update([
                    'ativo' => (bool) $p['ativo'], 'atualizado_em' => $agora, 'atualizado_por' => $uid, 'versao' => DB::raw('versao + 1'),
                ]);
            } else {
                DB::table('notificacao_preferencia')->insert($chave + [
                    'ativo' => (bool) $p['ativo'], 'criado_em' => $agora, 'criado_por' => $uid,
                    'atualizado_em' => $agora, 'atualizado_por' => $uid, 'versao' => 0,
                ]);
            }
        }
    }
}

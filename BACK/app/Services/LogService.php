<?php

namespace App\Services;

use App\Models\LogAcesso;
use App\Models\LogOperacao;
use App\Models\LogSistema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto de escrita dos logs. O log de operação só é gravado
 * depois do commit, para não registrar evento de transação que sofreu rollback.
 */
class LogService
{
    public function operacao(string $entidade, Model $registro, string $evento, ?string $de = null, ?string $para = null, array $detalhe = []): void
    {
        $usuarioId = auth()->id();
        $correlacao = Context::get('correlacao_id');
        $id = (string) $registro->getKey();
        $codigo = $registro->getAttribute('codigo');
        $json = $detalhe ? json_encode($detalhe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        DB::afterCommit(function () use ($usuarioId, $correlacao, $entidade, $id, $codigo, $evento, $de, $para, $json) {
            LogOperacao::create([
                'ocorrido_em' => now(),
                'usuario_id' => $usuarioId,
                'entidade' => $entidade,
                'registro_id' => $id,
                'registro_codigo' => $codigo,
                'evento' => $evento,
                'status_de' => $de,
                'status_para' => $para,
                'detalhe' => $json,
                'correlacao_id' => $correlacao,
            ]);
        });
    }

    public function sistema(string $nivel, string $categoria, string $evento, ?string $mensagem = null, array $extras = []): void
    {
        if ($nivel === 'DEBUG' && ! config('frete.log_debug')) {
            return;
        }
        $req = request();
        LogSistema::create([
            'ocorrido_em' => now(),
            'nivel' => $nivel,
            'categoria' => $categoria,
            'evento' => $evento,
            'usuario_id' => $extras['usuario_id'] ?? auth()->id(),
            'usuario_login' => isset($extras['usuario_login']) ? mb_substr((string) $extras['usuario_login'], 0, 50) : null,
            'mensagem' => $mensagem ? mb_substr($mensagem, 0, 1000) : null,
            'contexto' => isset($extras['contexto']) ? json_encode($extras['contexto'], JSON_UNESCAPED_UNICODE) : null,
            'metodo' => $req->method(),
            'rota' => mb_substr($req->path(), 0, 200),
            'ip' => $req->ip(),
            'user_agent' => mb_substr((string) $req->userAgent(), 0, 255),
            'correlacao_id' => $extras['correlacao_id'] ?? Context::get('correlacao_id'),
        ]);
    }

    public function acesso(string $usuarioId, ?string $ip): void
    {
        LogAcesso::create(['ocorrido_em' => now(), 'usuario_id' => $usuarioId, 'ip' => $ip]);
    }
}
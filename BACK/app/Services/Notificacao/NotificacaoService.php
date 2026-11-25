<?php

namespace App\Services\Notificacao;

use App\Jobs\EnviarEmailJob;
use App\Exceptions\RegraNegocioException;
use App\Jobs\EnviarWhatsappJob;
use App\Mail\TesteNotificacao;
use App\Models\Notificacao;
use App\Services\LogService;
use App\Services\Notificacao\Whatsapp\WhatsappGateway;
use App\Support\Telefone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registra e despacha as notificações de um evento: 1 linha por destinatário e canal ativo na matriz.
 * Idempotente por `evento:registro[:chave]:destino:canal`. O disparo vai em afterCommit e nunca derruba a transação de negócio.
 */
class NotificacaoService
{
    public function __construct(
        private DestinatarioResolver $resolver,
        private PreferenciaService $preferencias,
        private NotificacaoAlertas $alertas,
        private LogService $log,
    ) {}

    /**
     * Chamado de dentro da transação de negócio: só registra e enfileira depois do commit.
     *
     * @param  array<string, mixed>  $ctx  `transportadora_ids`, `chave` (diferencia ocorrências do mesmo evento) e o que o template precisar (vai no payload)
     */
    public function disparar(string $evento, string $entidade, Model $registro, array $ctx = []): void
    {
        DB::afterCommit(function () use ($evento, $entidade, $registro, $ctx) {
            try {
                foreach ($this->registrar($evento, $entidade, $registro, $ctx) as $n) {
                    if ($n->status === 'PENDENTE') {
                        $this->despachar($n);
                    }
                }
            } catch (Throwable $e) {
                $this->falhou($evento, $registro, $e);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return Collection<int, Notificacao> só as linhas criadas agora
     */
    public function registrar(string $evento, string $entidade, Model $registro, array $ctx = []): Collection
    {
        $criadas = new Collection;
        $template = config("notificacoes.eventos.{$evento}.template");
        $payload = $this->payload($ctx);
        $chave = isset($ctx['chave']) ? ":{$ctx['chave']}" : '';

        $destinatarios = $this->resolver->resolver($evento, $registro, $ctx);
        $enviados = [];
        $faltas = [];
        $jaRegistrada = false;
        foreach ($destinatarios as $d) {
            foreach (['WHATSAPP', 'EMAIL'] as $canal) {
                if (! $this->preferencias->ativo($evento, $canal, $d['grupo'])) {
                    continue;
                }
                $grupoWpp = $d['whatsapp_grupo'] ?? null;
                if ($grupoWpp && $canal === 'EMAIL') {
                    continue;
                }
                $endereco = $grupoWpp ?: ($canal === 'EMAIL' ? ($d['email'] ?: null) : Telefone::e164($d['telefone']));
                $erro = null;
                if ($endereco === null) {
                    if ($d['grupo'] !== 'TRANSPORTADORA' || $d['usuario_id'] !== null || $this->transportadoraTemCanal($destinatarios, $d['transportadora_id'], $canal)) {
                        continue;
                    }
                    $erro = $canal === 'EMAIL' ? 'Sem e-mail cadastrado.' : 'Sem número de WhatsApp válido.';
                    $faltas[$d['transportadora_id']]['nome'] = $d['nome'];
                    $faltas[$d['transportadora_id']]['canais'][] = $canal;
                }
                if ($endereco !== null && $d['grupo'] === 'TRANSPORTADORA') {
                    $chaveEnvio = "{$d['transportadora_id']}:{$canal}:{$endereco}";
                    if (isset($enviados[$chaveEnvio])) {
                        continue;
                    }
                    $enviados[$chaveEnvio] = true;
                }
                $destino = $d['usuario_id'] ?? $d['transportadora_id'].($grupoWpp ? ':grupo' : '');
                $idem = mb_substr("{$evento}:{$registro->getKey()}{$chave}:{$destino}:{$canal}", 0, 190);
                if (Notificacao::where('idempotencia_key', $idem)->exists()) {
                    $jaRegistrada = true;
                    continue;
                }
                try {
                    $criadas->push(Notificacao::create([
                        'evento' => $evento, 'entidade' => $entidade, 'registro_id' => (string) $registro->getKey(),
                        'registro_codigo' => $registro->getAttribute('codigo'), 'canal' => $canal, 'grupo_codigo' => $d['grupo'],
                        'destino_nome' => $d['nome'], 'endereco' => $endereco, 'payload' => $payload,
                        'template' => $canal === 'WHATSAPP' ? (config("notificacoes.eventos.{$evento}.template_por_grupo.{$d['grupo']}") ?? $template) : $template,
                        'status' => $erro ? 'FALHA' : 'PENDENTE', 'erro' => $erro, 'tentativas' => 0, 'idempotencia_key' => $idem,
                        'correlacao_id' => Context::get('correlacao_id'),
                    ]));
                } catch (UniqueConstraintViolationException) {
                }
            }
        }
        foreach ($faltas as $f) {
            $this->alertas->add(match (true) {
                count($f['canais']) > 1 => "Transportadora {$f['nome']} sem WhatsApp nem e-mail cadastrado: não foi avisada.",
                $f['canais'][0] === 'EMAIL' => "Transportadora {$f['nome']} sem e-mail cadastrado: aviso por e-mail não enviado.",
                default => "Transportadora {$f['nome']} sem WhatsApp válido cadastrado: avisada somente por e-mail.",
            });
        }
        if ($criadas->isEmpty() && ! $jaRegistrada && in_array($evento, ['COTACAO_CANCELADA', 'PROGRAMACAO_CANCELADA'], true)) {
            $this->alertas->add('Ninguém foi avisado do cancelamento: nenhum destinatário tem e-mail ou WhatsApp cadastrado.');
        }

        return $criadas;
    }

    /** Alguém da transportadora (contato geral ou usuário) tem endereço para o canal. */
    private function transportadoraTemCanal(array $destinatarios, ?string $transportadoraId, string $canal): bool
    {
        foreach ($destinatarios as $d) {
            if ($d['transportadora_id'] === $transportadoraId && ($canal === 'EMAIL' ? ! empty($d['email']) : (! empty($d['whatsapp_grupo']) || Telefone::e164($d['telefone']) !== null))) {
                return true;
            }
        }

        return false;
    }

    /** Recoloca na fila uma notificação que falhou (ação do administrador). */
    public function reenviar(Notificacao $n): Notificacao
    {
        if ($n->status !== 'FALHA') {
            throw new RegraNegocioException('Só é possível reenviar notificações que falharam.');
        }
        if (! config("notificacoes.eventos.{$n->evento}")) {
            throw new RegraNegocioException('Este tipo de notificação não pode ser reenviado.');
        }
        if (! $n->endereco) {
            throw new RegraNegocioException('A notificação não tem endereço de destino: corrija o contato e dispare de novo.');
        }
        $n->forceFill(['status' => 'PENDENTE', 'tentativas' => 0, 'erro' => null])->save();
        $this->despachar($n);

        return $n->refresh();
    }

    /** Envia direto (sem fila) uma mensagem de teste e deixa o resultado no histórico. */
    public function teste(string $canal, string $destino, string $quem): Notificacao
    {
        $endereco = $canal === 'EMAIL' ? $destino : Telefone::e164($destino);
        $n = Notificacao::create([
            'evento' => 'TESTE', 'entidade' => 'TESTE', 'registro_id' => 'teste', 'registro_codigo' => 'TESTE', 'canal' => $canal,
            'grupo_codigo' => 'ADMINISTRADOR', 'destino_nome' => $quem, 'endereco' => $endereco, 'template' => 'carga_status',
            'status' => 'PENDENTE', 'tentativas' => 1, 'idempotencia_key' => 'TESTE:'.Str::uuid(), 'correlacao_id' => Context::get('correlacao_id'),
        ]);
        try {
            $idExterno = null;
            if ($canal === 'EMAIL') {
                Mail::to($endereco)->send(new TesteNotificacao($n));
            } else {
                $idExterno = app(WhatsappGateway::class)->enviar($endereco, 'carga_status', ['TESTE', 'Teste de notificação', now()->format('d/m/Y'), config('notificacoes.front_url')]);
            }
            $n->forceFill(['status' => 'ENVIADO', 'id_externo' => $idExterno, 'enviado_em' => now()])->save();
        } catch (Throwable $e) {
            $n->forceFill(['status' => 'FALHA', 'erro' => mb_substr($e->getMessage(), 0, 500)])->save();
        }

        return $n;
    }

    public function despachar(Notificacao $n): void
    {
        try {
            $n->canal === 'EMAIL' ? EnviarEmailJob::dispatch($n->id) : EnviarWhatsappJob::dispatch($n->id);
        } catch (Throwable) {
        }
    }

    /** Envio esgotado: marca FALHA e deixa o rastro no log do sistema (INTEGRACAO), com a correlação da requisição de origem. */
    public function marcarFalha(string $id, Throwable $e): void
    {
        $n = Notificacao::find($id);
        if (! $n) {
            return;
        }
        $n->forceFill(['status' => 'FALHA', 'erro' => mb_substr($e->getMessage(), 0, 500)])->save();
        try {
            $this->log->sistema('ERRO', 'INTEGRACAO', "NOTIFICACAO_{$n->canal}_FALHA", mb_substr("{$n->evento} {$n->registro_codigo} para {$n->destino_nome}: {$e->getMessage()}", 0, 900), [
                'correlacao_id' => $n->correlacao_id,
            ]);
        } catch (Throwable) {
        }
    }

    /** Números do painel administrativo. @return array{pendentes: int, falhas_24h: int} */
    public function resumo(): array
    {
        return [
            'pendentes' => Notificacao::where('status', 'PENDENTE')->count(),
            'falhas_24h' => Notificacao::where('status', 'FALHA')->where('atualizado_em', '>=', now()->subDay())->count(),
        ];
    }

    private function falhou(string $evento, Model $registro, Throwable $e): void
    {
        try {
            $this->log->sistema('ERRO', 'INTEGRACAO', "NOTIFICACAO_{$evento}", mb_substr($e->getMessage(), 0, 900));
        } catch (Throwable) {
        }
    }

    private function payload(array $ctx): ?string
    {
        unset($ctx['transportadora_ids'], $ctx['chave']);
        $ctx = array_map(fn ($v) => is_array($v) ? array_map(fn ($x) => mb_substr((string) $x, 0, 200), array_slice($v, 0, 3)) : mb_substr((string) $v, 0, 200), $ctx);

        return $ctx ? mb_substr(json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1000) : null;
    }
}

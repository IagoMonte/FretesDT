<?php

namespace App\Services\Notificacao;

use App\Models\Cotacao;
use App\Models\Programacao;
use App\Models\Transportadora;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Quem pode receber cada evento, por grupo (sem override por usuário). Não olha canal nem preferência:
 * isso é do NotificacaoService. Contato: vendedor, administrador e gestor = e-mail e telefone do usuário; transportadora = contato geral
 * do cadastro + usuários dela (todos os ativos quando a cotação é encaminhada; depois, só quem respondeu).
 */
class DestinatarioResolver
{
    /** Grupo de notificação => código do grupo de acesso dos usuários. */
    private const GRUPOS_INTERNOS = ['ADMINISTRADOR' => 'ADMINISTRADOR', 'GESTOR' => 'GESTAO_ADMIN'];

    public function __construct(private PreferenciaService $preferencias) {}

    /**
     * @param  array{transportadora_ids?: array<int, string>}  $ctx  transportadoras-alvo (encaminhadas / vencedora); na programação vale a da carga
     * @return array<int, array{grupo: string, nome: string, email: ?string, telefone: ?string, usuario_id: ?string, transportadora_id: ?string}>
     */
    public function resolver(string $evento, Model $registro, array $ctx = []): array
    {
        $grupos = $this->preferencias->gruposPermitidos($evento);
        $dest = [];

        if (in_array('VENDEDOR', $grupos, true) && ($dono = $this->dono($registro))) {
            $dest[] = $this->doUsuario('VENDEDOR', $dono);
        }
        foreach (self::GRUPOS_INTERNOS as $grupo => $codigoAcesso) {
            if (in_array($grupo, $grupos, true)) {
                foreach ($this->usuariosDoGrupo($codigoAcesso) as $u) {
                    $dest[] = $this->doUsuario($grupo, $u);
                }
            }
        }
        if (in_array('TRANSPORTADORA', $grupos, true)) {
            foreach (Transportadora::whereIn('id', $this->transportadorasDoGrupo($registro, $ctx))->where('ativo', true)->get() as $t) {
                $dest[] = [
                    'grupo' => 'TRANSPORTADORA', 'nome' => $t->nome_fantasia, 'email' => $t->email, 'telefone' => $t->telefone,
                    'usuario_id' => null, 'transportadora_id' => $t->id,
                ];
                foreach ($this->usuariosDaTransportadora($evento, $registro, $t) as $u) {
                    $dest[] = ['transportadora_id' => $t->id] + $this->doUsuario('TRANSPORTADORA', $u);
                }
            }
        }

        if (in_array('GRUPO_TRANSPORTADORA', $grupos, true) && config('whatsapp.driver') === 'evolution') {
            foreach (Transportadora::whereIn('id', $this->transportadorasDoGrupo($registro, $ctx))->where('ativo', true)->whereNotNull('whatsapp_grupo_id')->get() as $t) {
                $dest[] = [
                    'grupo' => 'GRUPO_TRANSPORTADORA', 'nome' => $t->nome_fantasia, 'email' => null, 'telefone' => null,
                    'whatsapp_grupo' => $t->whatsapp_grupo_id, 'usuario_id' => null, 'transportadora_id' => $t->id,
                ];
            }
        }

        return $dest;
    }

    /**
     * Transportadoras cujo grupo é avisado: as do contexto (encaminhadas, vencedora, quem respondeu); no cancelamento da cotação, todas as
     * que a receberam; na carga, a da programação. Nunca as de outra cotação, para o grupo de uma não ver o que é da outra.
     *
     * @return array<int, string>
     */
    private function transportadorasDoGrupo(Model $registro, array $ctx): array
    {
        if (! empty($ctx['transportadora_ids'])) {
            return $ctx['transportadora_ids'];
        }
        if ($registro instanceof Programacao) {
            return array_filter([$registro->transportadora_id]);
        }

        return $registro instanceof Cotacao ? $this->transportadorasEncaminhadas($registro) : [];
    }

    /** @return array<int, string> */
    private function transportadorasEncaminhadas(Cotacao $cotacao): array
    {
        return DB::table('negociacao_resposta')
            ->join('negociacao', 'negociacao.id', '=', 'negociacao_resposta.negociacao_id')
            ->where('negociacao.cotacao_id', $cotacao->getKey())
            ->pluck('negociacao_resposta.transportadora_id')->unique()->values()->all();
    }

    private function dono(Model $registro): ?Usuario
    {
        $id = $registro instanceof Cotacao ? $registro->vendedor_id : ($registro instanceof Programacao ? $registro->representante_id : null);

        return $id ? Usuario::where('id', $id)->where('situacao', 'ATIVO')->first() : null;
    }

    /** @return \Illuminate\Support\Collection<int, Usuario> */
    private function usuariosDoGrupo(string $codigoAcesso)
    {
        $ids = DB::table('usuario_grupo')
            ->join('grupo', 'grupo.id', '=', 'usuario_grupo.grupo_id')
            ->where('grupo.codigo', $codigoAcesso)->where('grupo.ativo', true)
            ->pluck('usuario_grupo.usuario_id');

        return Usuario::whereIn('id', $ids)->where('situacao', 'ATIVO')->get();
    }

    /**
     * Usuários ativos da transportadora que têm algum contato: todos no encaminhamento (ninguém respondeu ainda);
     * nos demais eventos, só quem respondeu a cotação por ela.
     *
     * @return \Illuminate\Support\Collection<int, Usuario>
     */
    private function usuariosDaTransportadora(string $evento, Model $registro, Transportadora $t)
    {
        $q = Usuario::where('transportadora_id', $t->id)->where('situacao', 'ATIVO')
            ->where(fn ($c) => $c->whereNotNull('email')->orWhereNotNull('telefone'));
        if ($evento === 'NEGOCIACAO_ENCAMINHADA') {
            return $q->get();
        }
        $cotacaoId = $registro instanceof Cotacao ? $registro->getKey() : ($registro instanceof Programacao ? $registro->cotacao_id : null);
        if ($cotacaoId === null) {
            return $q->whereRaw('1 = 0')->get();
        }
        $respondentes = DB::table('negociacao_resposta')
            ->join('negociacao', 'negociacao.id', '=', 'negociacao_resposta.negociacao_id')
            ->where('negociacao.cotacao_id', $cotacaoId)->where('negociacao_resposta.transportadora_id', $t->id)
            ->whereNotNull('negociacao_resposta.respondido_por_id')->pluck('negociacao_resposta.respondido_por_id');

        return $q->whereIn('id', $respondentes)->get();
    }

    private function doUsuario(string $grupo, Usuario $u): array
    {
        return ['grupo' => $grupo, 'nome' => $u->nome, 'email' => $u->email, 'telefone' => $u->telefone, 'usuario_id' => $u->id, 'transportadora_id' => null];
    }
}

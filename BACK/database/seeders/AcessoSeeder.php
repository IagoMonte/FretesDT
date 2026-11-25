<?php

namespace Database\Seeders;

use App\Models\Grupo;
use App\Models\Recurso;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Catálogo de recursos/ações e os grupos de sistema com suas matrizes. */
class AcessoSeeder extends Seeder
{
    /** codigo => [nome, módulo, usa_escopo] */
    private const RECURSOS = [
        'COTACAO' => ['Cotação', 'OPERACAO', true],
        'NEGOCIACAO' => ['Negociação', 'OPERACAO', true],
        'PROGRAMACAO' => ['Programação de cargas', 'OPERACAO', true],
        'PRODUTO' => ['Produtos', 'CADASTRO', false],
        'TRANSPORTADORA' => ['Transportadoras', 'CADASTRO', false],
        'CLIENTE' => ['Clientes', 'CADASTRO', false],
        'FORNECEDOR' => ['Fornecedores', 'CADASTRO', false],
        'PARTE' => ['Clientes e fornecedores (busca)', 'CADASTRO', false],
        'EMBALAGEM' => ['Embalagens', 'CADASTRO', false],
        'TIPO_VEICULO' => ['Tipos de veículo', 'CADASTRO', false],
        'ANTT' => ['Piso mínimo de frete ANTT', 'CADASTRO', false],
        'USUARIO' => ['Usuários', 'ACESSO', false],
        'GRUPO' => ['Grupos', 'ACESSO', false],
        'SINCRONIZACAO' => ['Sincronização com o ERP', 'SISTEMA', false],
        'LOG_OPERACAO' => ['Log de operação', 'SISTEMA', false],
        'LOG_SISTEMA' => ['Log do sistema', 'SISTEMA', false],
        'LOG_ACESSO' => ['Log de acesso', 'SISTEMA', false],
        'PAINEL' => ['Painel operacional', 'SISTEMA', false],
        'ADMIN' => ['Painel administrativo (/admin)', 'SISTEMA', false],
        'NOTIFICACAO' => ['Notificações (WhatsApp e e-mail)', 'SISTEMA', false],
    ];

    private const ACOES = [
        'NEGOCIACAO' => ['ENCAMINHAR' => 'Encaminhar às transportadoras', 'RESPONDER' => 'Responder (transportadora)', 'LANCAR_RESPOSTA' => 'Lançar resposta pela transportadora', 'FECHAR' => 'Fechar negociação'],
        'PROGRAMACAO' => ['ALTERAR_STATUS' => 'Alterar status', 'CONFIRMAR_ENTREGA' => 'Confirmar entrega'],
        'SINCRONIZACAO' => ['EXECUTAR' => 'Executar sob demanda'],
        'NOTIFICACAO' => ['REENVIAR' => 'Reenviar notificação que falhou', 'ENVIAR_TESTE' => 'Enviar notificação de teste'],
        'USUARIO' => ['LIBERAR_ACESSO' => 'Liberar/bloquear acesso', 'APROVAR_CADASTRO' => 'Aprovar ou recusar cadastros novos'],
    ];

    public function run(): void
    {
        $agora = now()->format('Y-m-d H:i:s.v');
        $aud = ['criado_em' => $agora, 'atualizado_em' => $agora, 'versao' => 0];

        foreach (self::RECURSOS as $codigo => [$nome, $modulo, $escopo]) {
            Recurso::updateOrCreate(['codigo' => $codigo], ['nome' => $nome, 'modulo' => $modulo, 'usa_escopo' => $escopo, 'ativo' => true]);
        }
        foreach (self::ACOES as $recurso => $acoes) {
            foreach ($acoes as $acao => $nome) {
                $chave = ['recurso_codigo' => $recurso, 'acao_codigo' => $acao];
                DB::table('recurso_acao')->where($chave)->exists()
                    ? DB::table('recurso_acao')->where($chave)->update(['nome' => $nome, 'ativo' => true])
                    : DB::table('recurso_acao')->insert($chave + ['nome' => $nome, 'ativo' => true] + $aud);
            }
        }

        $admin = $this->grupo('ADMINISTRADOR', 'Administrador', 'INTERNO', 'Acesso total (protegido).');
        $vendedor = $this->grupo('VENDEDOR', 'Vendedor', 'INTERNO', 'Vê e cria as próprias cotações.');
        $transportadora = $this->grupo('TRANSPORTADORA', 'Transportadora', 'TRANSPORTADORA', 'Responde cotações e atualiza as próprias cargas.');
        $gestao = $this->grupo('GESTAO_ADMIN', 'Gestão (painel /admin)', 'INTERNO', 'Indicadores, auditoria e análise de transportadoras; cria e edita cotações e programações.');

        foreach ([$admin, $vendedor, $transportadora, $gestao] as $g) {
            DB::table('grupo_permissao')->where('grupo_id', $g->id)->delete();
            DB::table('grupo_acao')->where('grupo_id', $g->id)->delete();
        }

        foreach (array_keys(self::RECURSOS) as $recurso) {
            $this->permissao($admin, $recurso, 'viac', 'TODOS');
        }
        foreach (self::ACOES as $recurso => $acoes) {
            foreach (array_keys($acoes) as $acao) {
                DB::table('grupo_acao')->insert(['grupo_id' => $admin->id, 'recurso_codigo' => $recurso, 'acao_codigo' => $acao]);
            }
        }

        foreach (['ADMIN', 'PAINEL', 'LOG_OPERACAO', 'LOG_SISTEMA', 'LOG_ACESSO', 'NEGOCIACAO', 'ANTT'] as $r) {
            $this->permissao($gestao, $r, 'v');
        }
        foreach (['COTACAO', 'PROGRAMACAO'] as $r) {
            $this->permissao($gestao, $r, 'via');
        }
        $this->acao($gestao, 'NEGOCIACAO', 'ENCAMINHAR');
        $this->acao($gestao, 'NEGOCIACAO', 'FECHAR');

        $this->permissao($vendedor, 'COTACAO', 'viac', 'PROPRIOS');
        $this->permissao($vendedor, 'NEGOCIACAO', 'v', 'PROPRIOS');
        $this->permissao($vendedor, 'PROGRAMACAO', 'v', 'PROPRIOS');
        $this->permissao($vendedor, 'PARTE', 'via');
        foreach (['CLIENTE', 'FORNECEDOR', 'TIPO_VEICULO'] as $r) {
            $this->permissao($vendedor, $r, 'v');
        }

        $this->permissao($transportadora, 'PAINEL', 'v', 'TRANSPORTADORA');
        $this->permissao($transportadora, 'COTACAO', 'v', 'TRANSPORTADORA');
        $this->permissao($transportadora, 'NEGOCIACAO', 'v', 'TRANSPORTADORA');
        $this->permissao($transportadora, 'PROGRAMACAO', 'v', 'TRANSPORTADORA');
        $this->acao($transportadora, 'NEGOCIACAO', 'RESPONDER');
        $this->acao($transportadora, 'PROGRAMACAO', 'ALTERAR_STATUS');
        $this->acao($transportadora, 'PROGRAMACAO', 'CONFIRMAR_ENTREGA');

        foreach (['PRODUTO' => 60, 'CLIENTE' => 60, 'FORNECEDOR' => 60] as $entidade => $intervalo) {
            DB::table('sincronizacao')->where('entidade', $entidade)->exists()
                ?: DB::table('sincronizacao')->insert(['entidade' => $entidade, 'ativo' => true, 'intervalo_minutos' => $intervalo] + $aud);
        }
    }

    private function grupo(string $codigo, string $nome, string $tipo, string $descricao): Grupo
    {
        return Grupo::updateOrCreate(['codigo' => $codigo], ['nome' => $nome, 'tipo_usuario' => $tipo, 'descricao' => $descricao, 'sistema' => true, 'ativo' => true]);
    }

    /** $flags: letras v=ver, i=inserir, a=alterar, c=cancelar (viac = tudo). */
    private function permissao(Grupo $g, string $recurso, string $flags, string $escopo = 'TODOS'): void
    {
        $agora = now()->format('Y-m-d H:i:s.v');
        DB::table('grupo_permissao')->insert([
            'grupo_id' => $g->id,
            'recurso_codigo' => $recurso,
            'pode_ver' => str_contains($flags, 'v'),
            'pode_inserir' => str_contains($flags, 'i'),
            'pode_alterar' => str_contains($flags, 'a'),
            'pode_cancelar' => str_contains($flags, 'c'),
            'escopo' => $escopo,
            'criado_em' => $agora, 'atualizado_em' => $agora, 'versao' => 0,
        ]);
    }

    private function acao(Grupo $g, string $recurso, string $acao): void
    {
        DB::table('grupo_acao')->insert(['grupo_id' => $g->id, 'recurso_codigo' => $recurso, 'acao_codigo' => $acao]);
    }
}

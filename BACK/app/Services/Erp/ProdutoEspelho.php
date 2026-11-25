<?php

namespace App\Services\Erp;

use App\Services\Erp\Fontes\ProdutoFonte;
use App\Services\LogService;
use App\Support\Normaliza;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Espelho de produtos: N códigos do ERP para 1 produto local. Cada código fica em `produto_erp` (com o hash do que a ponte
 * mandou) e aponta para o produto do seu nome-base; o produto nasce no primeiro código e os seguintes só se juntam a ele.
 * Situação do produto: na PRIMEIRA carga todo produto do ERP nasce inativo (o ERP tem centenas de itens que não são frete e a
 * equipe ativa só os que usa); depois disso produto novo segue a situação do ERP e produto que já existe NUNCA tem a situação
 * alterada pelo espelho (nem ativado, nem inativado). Campos só nossos (ONU, densidade, embalagens) também nunca são tocados.
 */
class ProdutoEspelho
{
    private const FORMATO = 'Y-m-d H:i:s.v';

    private const LIMITE_LOG = 100;

    public function __construct(private ErpFretesClient $erp, private LogService $log) {}

    /**
     * @param  array<string, int|string>  $totais  criados = produtos novos; vinculados = códigos que entraram num produto que já existia
     *                                             (ou trocaram de produto); atualizados = código com dado novo
     * @param  array<string, scalar>  $filtros
     */
    public function varrer(ProdutoFonte $fonte, array &$totais, array $filtros, bool $completa): void
    {
        $agora = now()->format(self::FORMATO);

        $mapa = DB::table('produto_erp')->get()->keyBy('codigo_erp')->all();
        $produtos = DB::table('produto')->get(['id', 'nome', 'ativo', 'origem_cadastro'])->keyBy('id')->all();
        $porChave = [];
        foreach ($produtos as $p) {
            $porChave[Normaliza::chave($p->nome)] ??= $p->id;
        }
        $codigosPorProduto = [];
        foreach ($mapa as $m) {
            $codigosPorProduto[$m->produto_id] = ($codigosPorProduto[$m->produto_id] ?? 0) + 1;
        }

        $primeiraCarga = ! DB::table('sincronizacao')->where('entidade', 'PRODUTO')->whereNotNull('marca_dagua')->exists();

        $vistos = [];
        $logados = 0;
        foreach ($this->erp->paginas($fonte->rota(), $filtros) as $pagina) {
            $novosProdutos = [];
            $novosCodigos = [];
            $mudancasCodigo = [];
            $renomes = [];
            $inalterados = [];

            foreach ($pagina as $cru) {
                $totais['lidos']++;
                $codigo = $fonte->codigoErp($cru);
                if ($codigo === '') {
                    $this->pular($fonte, $cru, 'Registro sem código no ERP.', $totais, $logados);

                    continue;
                }
                $vistos[$codigo] = true;

                try {
                    ['campos' => $c, 'avisos' => $avisos] = $fonte->normalizar($cru, fn () => null);
                } catch (RegistroInvalido $e) {
                    $this->pular($fonte, $cru, $e->getMessage(), $totais, $logados);

                    continue;
                }
                foreach ($avisos as $a) {
                    $totais['avisos']++;
                    if ($logados++ < self::LIMITE_LOG) {
                        $this->log->sistema('WARNING', 'SINCRONIZACAO', 'REGISTRO_COM_AVISO', "PRODUTO {$codigo}: {$a}", ['contexto' => ['linha' => $cru]]);
                    }
                }

                $hash = hash('sha256', json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'|v'.config('frete.erp.versao_normalizador'));
                $atual = $mapa[$codigo] ?? null;
                if ($atual && $atual->erp_hash === $hash) {
                    $inalterados[] = $codigo;

                    continue;
                }

                $chave = Normaliza::chave($c['nome']);
                $alvo = $porChave[$chave] ?? null;

                if ($atual) {
                    $origem = $atual->produto_id;
                    if ($alvo === null && ($codigosPorProduto[$origem] ?? 0) === 1 && ($produtos[$origem]->origem_cadastro ?? null) === 'ERP') {
                        $renomes[$origem] = $c['nome'];
                        $porChave[$chave] = $origem;
                        $produtos[$origem]->nome = $c['nome'];
                        $alvo = $origem;
                    }
                    $criou = false;
                    if ($alvo === null) {
                        [$alvo] = $this->novoProduto($c, $codigo, $agora, $novosProdutos, $produtos, $porChave, $primeiraCarga);
                        $totais['criados']++;
                        $criou = true;
                    }
                    $trocou = $alvo !== $origem;
                    if ($trocou) {
                        $codigosPorProduto[$origem] = max(0, ($codigosPorProduto[$origem] ?? 1) - 1);
                        $codigosPorProduto[$alvo] = ($codigosPorProduto[$alvo] ?? 0) + 1;
                        $totais[$criou ? 'atualizados' : 'vinculados']++;
                    } else {
                        $totais['atualizados']++;
                    }
                    $mudancasCodigo[] = [$codigo, $alvo, $c, $hash];
                    $mapa[$codigo] = (object) ['codigo_erp' => $codigo, 'produto_id' => $alvo, 'erp_hash' => $hash, 'ativo' => $c['ativo']];

                    continue;
                }

                if ($alvo === null) {
                    [$alvo] = $this->novoProduto($c, $codigo, $agora, $novosProdutos, $produtos, $porChave, $primeiraCarga);
                    $totais['criados']++;
                } else {
                    $totais['vinculados']++;
                }
                $codigosPorProduto[$alvo] = ($codigosPorProduto[$alvo] ?? 0) + 1;
                $novosCodigos[] = ['codigo_erp' => $codigo, 'produto_id' => $alvo, 'descricao_erp' => $c['descricao_erp'], 'ativo' => $c['ativo'], 'erp_hash' => $hash,
                    'sincronizado_em' => $agora, 'criado_em' => $agora, 'atualizado_em' => $agora];
                $mapa[$codigo] = (object) ['codigo_erp' => $codigo, 'produto_id' => $alvo, 'erp_hash' => $hash, 'ativo' => $c['ativo']];
            }

            DB::transaction(function () use ($novosProdutos, $novosCodigos, $mudancasCodigo, $renomes, $inalterados, $agora) {
                foreach (array_chunk($novosProdutos, 200) as $lote) {
                    DB::table('produto')->insert($lote);
                }
                foreach ($renomes as $id => $nome) {
                    DB::table('produto')->where('id', $id)->update(['nome' => $nome, 'atualizado_em' => $agora, 'versao' => DB::raw('versao + 1')]);
                }
                foreach (array_chunk($novosCodigos, 200) as $lote) {
                    DB::table('produto_erp')->insert($lote);
                }
                foreach ($mudancasCodigo as [$codigo, $alvo, $c, $hash]) {
                    DB::table('produto_erp')->where('codigo_erp', $codigo)->update([
                        'produto_id' => $alvo, 'descricao_erp' => $c['descricao_erp'], 'ativo' => $c['ativo'], 'erp_hash' => $hash,
                        'sincronizado_em' => $agora, 'atualizado_em' => $agora,
                    ]);
                }
                foreach (array_chunk($inalterados, 500) as $lote) {
                    DB::table('produto_erp')->whereIn('codigo_erp', $lote)->update(['sincronizado_em' => $agora]);
                }
            });
            $totais['inalterados'] += count($inalterados);
        }

        if ($completa) {
            $ausentes = array_map('strval', array_keys(array_diff_key(array_filter($mapa, fn ($m) => $m->ativo), $vistos)));
            foreach (array_chunk($ausentes, 500) as $lote) {
                DB::table('produto_erp')->whereIn('codigo_erp', $lote)->update(['ativo' => false, 'atualizado_em' => $agora]);
            }
        }
    }

    /** @return array{0: string} id do produto novo (a linha só é gravada no fim da página) */
    private function novoProduto(array $c, string $codigo, string $agora, array &$novos, array &$produtos, array &$porChave, bool $primeiraCarga): array
    {
        $id = (string) Str::uuid7();
        $ativo = ! $primeiraCarga && $c['ativo'];
        $novos[] = [
            'id' => $id, 'nome' => $c['nome'], 'ativo' => $ativo, 'codigo_erp' => $codigo, 'origem_cadastro' => 'ERP',
            'erp_hash' => null, 'sincronizado_em' => $agora, 'criado_em' => $agora, 'atualizado_em' => $agora, 'versao' => 0,
        ];
        $produtos[$id] = (object) ['id' => $id, 'nome' => $c['nome'], 'ativo' => $ativo, 'origem_cadastro' => 'ERP'];
        $porChave[Normaliza::chave($c['nome'])] = $id;

        return [$id];
    }

    private function pular(ProdutoFonte $fonte, array $cru, string $motivo, array &$totais, int &$logados): void
    {
        $totais['pulados']++;
        if ($logados++ < self::LIMITE_LOG) {
            $this->log->sistema('WARNING', 'SINCRONIZACAO', 'REGISTRO_PULADO', "PRODUTO {$fonte->codigoErp($cru)}: {$motivo}", ['contexto' => ['linha' => $cru]]);
        }
    }
}

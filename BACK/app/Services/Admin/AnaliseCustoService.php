<?php

namespace App\Services\Admin;

use App\Support\Periodo;
use Illuminate\Support\Collection;

/** Quanto custa o frete (valor fechado por tonelada) por produto, rota e veículo. */
class AnaliseCustoService
{
    public function __construct(private Base $base) {}

    public function porProduto(Periodo $p): array
    {
        $acc = [];
        foreach ($this->fechadas($p) as $c) {
            foreach (collect($c['produtos'])->unique('nome') as $pr) {
                $acc[$pr['nome']][] = $c;
            }
        }

        return $this->resumir($acc, 'produto');
    }

    public function porRota(Periodo $p): array
    {
        return $this->resumir($this->fechadas($p)->groupBy('rota')->map->all()->all(), 'rota');
    }

    public function porVeiculo(Periodo $p): array
    {
        return $this->resumir($this->fechadas($p)->groupBy('veiculo')->map->all()->all(), 'veiculo');
    }

    public function porParte(Periodo $p, int $limite = 10): array
    {
        return $this->resumir($this->fechadas($p)->groupBy('parte')->map->all()->all(), 'parte', $limite);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function fechadas(Periodo $p): Collection
    {
        return $this->base->cotacoes($p)->filter(fn ($c) => $c['fechada']);
    }

    /** @param array<string, array<int, array>> $grupos */
    private function resumir(array $grupos, string $campo, ?int $limite = null): array
    {
        $linhas = collect($grupos)->map(function (array $cs, string $chave) use ($campo) {
            $vts = collect($cs)->pluck('fechado_vt');
            $nf = (float) collect($cs)->sum('valor_nf');
            $frete = (float) collect($cs)->sum('fechado_frete');

            return [
                $campo => $chave,
                'fechamentos' => count($cs),
                'min' => (float) $vts->min(), 'max' => (float) $vts->max(), 'media' => Base::media($vts),
                'amplitude' => round((float) ($vts->max() - $vts->min()), 2),
                'toneladas' => round((float) collect($cs)->sum('t'), 3),
                'frete_total' => round($frete, 2),
                'saving' => round((float) collect($cs)->sum('saving'), 2),
                'frete_sobre_nf_pct' => Base::pct($frete, $nf),
            ];
        })->sortByDesc('toneladas')->values();

        return ($limite ? $linhas->take($limite) : $linhas)->all();
    }
}

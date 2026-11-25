<?php

namespace App\Services;

use App\Models\AnttCoeficiente;
use App\Models\Cotacao;
use App\Support\Fmt;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Piso mínimo de frete da ANTT: piso = km × CCD + CC (coeficientes por tipo de carga × eixos, com vigência). */
class AnttService
{
    /** Coeficiente ativo e vigente na data (padrão: hoje); o de vigência mais recente vence. */
    public function coeficiente(string $tipoCarga, int $eixos, ?string $data = null): ?AnttCoeficiente
    {
        $data ??= Fmt::hoje();

        return AnttCoeficiente::where('tipo_carga_codigo', $tipoCarga)
            ->where('eixos', $eixos)->where('ativo', true)
            ->where('vigencia_inicio', '<=', $data)
            ->where(fn ($q) => $q->whereNull('vigencia_fim')->orWhere('vigencia_fim', '>=', $data))
            ->orderByDesc('vigencia_inicio')->first();
    }

    /** km × CCD + CC, 2 casas HALF_UP (brick/math, nunca float). */
    public function calcular(string|int|float $km, AnttCoeficiente $c): string
    {
        return (string) BigDecimal::of((string) $km)
            ->multipliedBy(BigDecimal::of((string) $c->ccd))
            ->plus(BigDecimal::of((string) $c->cc))
            ->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Colunas de snapshot (R8) do piso para a cotação com os eixos informados; todas nulas
     * se faltar tipo de carga, km, eixos ou coeficiente vigente. Fracionada não dedicada não tem piso, eixos nem tipo de carga.
     * O tipo de carga é o escolhido pela transportadora (senão o da cotação).
     *
     * @return array{eixos: ?int, tipo_carga_antt_codigo: ?string, piso_antt_valor: ?string, piso_antt_km: ?string, piso_antt_coef_id: ?string}
     */
    public function snapshot(Cotacao $cotacao, ?int $eixos, ?string $tipoCarga = null, bool $dedicado = false): array
    {
        $nada = ['dedicado' => false, 'eixos' => null, 'tipo_carga_antt_codigo' => null, 'piso_antt_valor' => null, 'piso_antt_km' => null, 'piso_antt_coef_id' => null];
        if (! $cotacao->pisoValido($dedicado)) {
            return $nada;
        }
        $dedicado = $cotacao->fracionada && $dedicado;
        $tipoCarga = $tipoCarga ?: $cotacao->tipo_carga_antt_codigo;
        $vazio = ['dedicado' => $dedicado, 'eixos' => $eixos, 'tipo_carga_antt_codigo' => $tipoCarga] + $nada;
        if (! $eixos || ! $tipoCarga || $cotacao->distancia_km === null) {
            return $vazio;
        }
        $coef = $this->coeficiente($tipoCarga, $eixos);
        if (! $coef) {
            return $vazio;
        }

        return [
            'dedicado' => $dedicado,
            'eixos' => $eixos,
            'tipo_carga_antt_codigo' => $tipoCarga,
            'piso_antt_valor' => $this->calcular($cotacao->distancia_km, $coef),
            'piso_antt_km' => (string) $cotacao->distancia_km,
            'piso_antt_coef_id' => $coef->id,
        ];
    }

    /** Diferença do frete proposto para o piso: positiva = acima do piso. */
    public function diferenca(string|int|float $frete, string|int|float $piso): string
    {
        return (string) BigDecimal::of((string) $frete)->minus(BigDecimal::of((string) $piso))->toScale(2, RoundingMode::HalfUp);
    }

    /** Nome sem acento, MAIÚSCULO e espaços colapsados: chave de busca igual para o IBGE e para o texto da cotação. */
    public static function normalizar(string $nome): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', Str::ascii($nome))));
    }

    /** Código IBGE do município pelo nome + UF (acentos e caixa não importam). */
    public function ibge(string $cidade, string $uf): ?string
    {
        return DB::table('municipio')->where('uf', strtoupper($uf))->where('nome_busca', self::normalizar($cidade))->value('ibge');
    }

    /** Quilometragem da tabela de distâncias (um par por linha, com o menor código IBGE na origem). */
    public function distancia(string $origemCidade, string $origemUf, string $destinoCidade, string $destinoUf): ?string
    {
        $o = $this->ibge($origemCidade, $origemUf);
        $d = $this->ibge($destinoCidade, $destinoUf);
        if (! $o || ! $d || $o === $d) {
            return null;
        }
        $km = DB::table('distancia')->where('origem_ibge', min($o, $d))->where('destino_ibge', max($o, $d))->value('km');

        return $km === null ? null : (string) BigDecimal::of((string) $km)->toScale(1, RoundingMode::HalfUp);
    }

    /** Bloco `antt` da resposta/fechamento (a API só o envia a quem tem ANTT.ver: gestão e admin). */
    public static function bloco(object $linha, string|int|float|null $frete): ?array
    {
        if ($linha->piso_antt_valor === null || $frete === null) {
            return null;
        }
        $dif = (new self)->diferenca($frete, $linha->piso_antt_valor);

        return [
            'piso' => $linha->piso_antt_valor,
            'km' => $linha->piso_antt_km,
            'eixos' => $linha->eixos,
            'diferenca' => $dif,
            'abaixo' => BigDecimal::of($dif)->isNegative(),
        ];
    }
}

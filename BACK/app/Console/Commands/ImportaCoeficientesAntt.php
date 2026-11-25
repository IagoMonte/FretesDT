<?php

namespace App\Console\Commands;

use App\Models\AnttCoeficiente;
use App\Models\TipoCargaAntt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carrega os coeficientes CCD/CC do piso ANTT de um CSV (tipo_carga_site, tipo_carga_codigo, eixos, ccd, cc,
 * vigencia_inicio, fonte). Idempotente por tipo de carga + eixos + vigência: reimportar atualiza os valores.
 * O arquivo é dado (não vai para o git): fica em storage/app/antt/coeficientes.csv.
 */
class ImportaCoeficientesAntt extends Command
{
    protected $signature = 'antt:importa-coeficientes {arquivo? : CSV (padrão: storage/app/antt/coeficientes.csv)}';

    protected $description = 'Importa os coeficientes do piso mínimo ANTT (tipos de carga e CCD/CC por eixos).';

    public function handle(): int
    {
        $arquivo = $this->argument('arquivo') ?? storage_path('app/antt/coeficientes.csv');
        $f = @fopen($arquivo, 'r');
        if (! $f) {
            $this->warn("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }
        $cab = fgetcsv($f);
        $n = 0;
        DB::transaction(function () use ($f, $cab, &$n) {
            while (($l = fgetcsv($f)) !== false) {
                $r = array_combine($cab, array_pad($l, count($cab), null));
                if (! $r || empty($r['tipo_carga_codigo'])) {
                    continue;
                }
                TipoCargaAntt::firstOrCreate(['codigo' => $r['tipo_carga_codigo']], ['descricao' => $r['tipo_carga_site'], 'ativo' => true]);
                AnttCoeficiente::updateOrCreate(
                    ['tipo_carga_codigo' => $r['tipo_carga_codigo'], 'eixos' => (int) $r['eixos'], 'vigencia_inicio' => $r['vigencia_inicio']],
                    ['ccd' => $r['ccd'], 'cc' => $r['cc'], 'ativo' => true],
                );
                $n++;
            }
        });
        fclose($f);
        $this->info("{$n} coeficientes carregados.");

        return self::SUCCESS;
    }
}

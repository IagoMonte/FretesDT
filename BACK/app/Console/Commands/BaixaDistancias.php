<?php

namespace App\Console\Commands;

use App\Services\AnttService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * Carrega os municípios (IBGE) e a matriz de distâncias rodoviárias entre todos eles (piso ANTT).
 *
 * Fonte: Saldanha, "Brazilian road distances" (OSRM, rede de mai/2024), Zenodo 10.5281/zenodo.11400243,
 * CC-BY-4.0. ~15,5 milhões de pares, um por linha. É dado fixo: baixa uma vez para storage/app/antt
 * (fora do git) e importa; se a tabela já está completa, não faz nada.
 */
class BaixaDistancias extends Command
{
    private const URL_ZIP = 'https://zenodo.org/api/records/11400243/files/dist_brasil.zip/content';

    private const URL_MUNICIPIOS = 'https://servicodados.ibge.gov.br/api/v1/localidades/municipios?orderBy=nome';

    /** Pares esperados (5.565 municípios): abaixo disto a carga é refeita (insertOrIgnore retoma de onde parou). */
    private const COMPLETO = 15_000_000;

    protected $signature = 'antt:baixa-distancias
        {--csv= : CSV de distâncias já extraído (pula o download); colunas orig;dest;dist(m);dur}
        {--municipios= : JSON de municípios do IBGE já baixado (pula o download)}
        {--force : Refaz a carga mesmo com a tabela completa}';

    protected $description = 'Baixa e importa os municípios do IBGE e as distâncias rodoviárias entre eles (dado fixo, idempotente).';

    public function handle(): int
    {
        if (! $this->option('force') && DB::table('distancia')->count() >= self::COMPLETO) {
            $this->info('Distâncias já carregadas.');

            return self::SUCCESS;
        }
        if (! $this->municipios()) {
            return self::FAILURE;
        }

        $csv = $this->option('csv') ?: $this->extrair();
        $f = $csv ? @fopen($csv, 'r') : false;
        if (! $f) {
            $this->error('Não consegui abrir o CSV de distâncias.');

            return self::FAILURE;
        }
        $total = $this->importar($f);
        fclose($f);
        $this->info("{$total} pares lidos.");

        return self::SUCCESS;
    }

    private function municipios(): bool
    {
        $arq = $this->option('municipios') ?: (is_file($c = storage_path('app/antt/municipios.json')) ? $c : null);
        $json = $arq ? @file_get_contents($arq) : Http::timeout(120)->retry(3, 2000)->get(self::URL_MUNICIPIOS)->body();
        $lista = json_decode((string) $json, true);
        if (! is_array($lista) || ! $lista) {
            $this->error('Lista de municípios do IBGE indisponível.');

            return false;
        }
        $linhas = [];
        foreach ($lista as $m) {
            $uf = $m['microrregiao']['mesorregiao']['UF']['sigla'] ?? $m['regiao-imediata']['regiao-intermediaria']['UF']['sigla'] ?? null;
            if (! $uf) {
                continue;
            }
            $linhas[] = ['ibge' => (string) $m['id'], 'nome' => $m['nome'], 'nome_busca' => AnttService::normalizar($m['nome']), 'uf' => $uf];
        }
        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table('municipio')->upsert($lote, ['ibge'], ['nome', 'nome_busca', 'uf']);
        }
        $this->info(count($linhas).' municípios.');

        return true;
    }

    /** Baixa o zip (uma vez, fica em storage/app/antt) e devolve o CSV extraído. */
    private function extrair(): ?string
    {
        $dir = storage_path('app/antt');
        @mkdir($dir, 0775, true);
        $zip = "{$dir}/dist_brasil.zip";
        $csv = "{$dir}/dist_brasil.csv";
        if (is_file($csv)) {
            return $csv;
        }
        if (! is_file($zip)) {
            $this->info('Baixando a matriz de distâncias (~154 MB)...');
            $r = Http::timeout(3600)->withOptions(['sink' => $zip])->get(self::URL_ZIP);
            if (! $r->successful()) {
                @unlink($zip);
                $this->error('Falha no download do Zenodo.');

                return null;
            }
        }
        $z = new ZipArchive;
        if ($z->open($zip) !== true) {
            @unlink($zip);
            $this->error('Zip inválido (apagado: rode de novo).');

            return null;
        }
        $this->info('Extraindo...');
        $ok = $z->extractTo($dir, 'dist_brasil.csv');
        $z->close();

        return $ok ? $csv : null;
    }

    /** @param  resource  $f */
    private function importar($f): int
    {
        $lote = [];
        $lidos = 0;
        while (($l = fgets($f)) !== false) {
            $c = explode(';', rtrim($l));
            if (count($c) < 3 || ! ctype_digit($c[0]) || ! ctype_digit($c[1]) || ! is_numeric($c[2]) || $c[0] === $c[1]) {
                continue;
            }
            [$o, $d] = $c[0] < $c[1] ? [$c[0], $c[1]] : [$c[1], $c[0]];
            $lote[] = ['origem_ibge' => $o, 'destino_ibge' => $d, 'km' => round((float) $c[2] / 1000, 1)];
            if (count($lote) >= 2000) {
                $this->gravar($lote);
                $lidos += count($lote);
                $lote = [];
                if ($lidos % 1_000_000 === 0) {
                    $this->line("  {$lidos} pares...");
                }
            }
        }
        $this->gravar($lote);

        return $lidos + count($lote);
    }

    /** insertOrIgnore: reexecutar após uma interrupção retoma sem duplicar. */
    private function gravar(array $lote): void
    {
        if ($lote) {
            DB::table('distancia')->insertOrIgnore($lote);
        }
    }
}

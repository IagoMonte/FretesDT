<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GerarChavesJwt extends Command
{
    protected $signature = 'frete:jwt-keys {--force : Sobrescreve chaves existentes (invalida todos os tokens)}';

    protected $description = 'Gera o par de chaves RSA (RS256) usado para assinar os access tokens, em storage/keys (fora do git).';

    public function handle(): int
    {
        $privada = $this->caminho(config('frete.jwt.chave_privada'));
        $publica = $this->caminho(config('frete.jwt.chave_publica'));

        if ((is_file($privada) || is_file($publica)) && ! $this->option('force')) {
            $this->warn('As chaves já existem. Use --force para recriar (todos os tokens atuais deixam de valer).');

            return self::SUCCESS;
        }

        $chave = $this->criarChave();
        if (! $chave) {
            $this->error('Falha ao gerar a chave RSA: '.openssl_error_string());

            return self::FAILURE;
        }
        openssl_pkey_export($chave, $pem, null, $this->opcoes());
        $detalhes = openssl_pkey_get_details($chave);

        @mkdir(dirname($privada), 0700, true);
        @mkdir(dirname($publica), 0755, true);
        file_put_contents($privada, $pem);
        file_put_contents($publica, $detalhes['key']);
        @chmod($privada, 0600);

        $this->info("Chaves geradas:\n  {$privada}\n  {$publica}");

        return self::SUCCESS;
    }

    private function caminho(string $c): string
    {
        return preg_match('#^([A-Za-z]:[\\\\/]|/)#', $c) ? $c : base_path($c);
    }

    private function criarChave(): \OpenSSLAsymmetricKey|false
    {
        $chave = @openssl_pkey_new($this->opcoes());
        if ($chave) {
            return $chave;
        }
        $cnf = sys_get_temp_dir().DIRECTORY_SEPARATOR.'frete-openssl.cnf';
        file_put_contents($cnf, "[req]\ndistinguished_name=req_dn\n[req_dn]\n");
        putenv("OPENSSL_CONF={$cnf}");

        return openssl_pkey_new($this->opcoes() + ['config' => $cnf]);
    }

    private function opcoes(): array
    {
        $cnf = sys_get_temp_dir().DIRECTORY_SEPARATOR.'frete-openssl.cnf';

        return ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + (is_file($cnf) ? ['config' => $cnf] : []);
    }
}
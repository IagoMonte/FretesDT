<?php

namespace App\Providers;

use App\Auth\JwtGuardResolver;
use App\Mail\Transport\MicrosoftTokenProvider;
use App\Mail\Transport\MicrosoftTransport;
use App\Models\Usuario;
use App\Services\Notificacao\NotificacaoAlertas;
use App\Services\Notificacao\Whatsapp\WhatsappGateway;
use App\Services\Notificacao\Whatsapp\WhatsappGatewayFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(NotificacaoAlertas::class);
        $this->app->bind(WhatsappGateway::class, fn () => WhatsappGatewayFactory::criar());
    }

    public function boot(): void
    {
        $this->macrosDeSchema();

        Mail::extend('microsoft', fn (array $config) => new MicrosoftTransport($config, new MicrosoftTokenProvider($config)));

        Auth::viaRequest('jwt', fn (Request $request) => app(JwtGuardResolver::class)->usuario($request));

        Gate::before(fn ($usuario, string $habilidade) => $usuario instanceof Usuario && $usuario->pode($habilidade) ? true : null);

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(config('frete.login_tentativas_por_minuto'))
            ->by($r->ip().'|'.mb_strtolower(trim(is_string($r->input('login')) ? $r->input('login') : ''))));

        RateLimiter::for('whatsapp', fn () => Limit::perMinute(max(1, (int) config('whatsapp.limite_por_minuto')))->by('whatsapp'));

        RateLimiter::for('cadastro', fn (Request $r) => Limit::perHour(config('frete.cadastro_tentativas_por_hora'))->by($r->ip()));
    }

    /** Macros para as migrations rodarem igual em diferentes bancos. */
    private function macrosDeSchema(): void
    {
        Blueprint::macro('uuidCol', function (string $coluna) {
            /** @var Blueprint $this */
            $c = $this->char($coluna, 36);
            if (DB::getDriverName() === 'mysql') {
                $c->charset('ascii')->collation('ascii_bin');
            }

            return $c;
        });

        Blueprint::macro('asciiChar', function (string $coluna, int $tamanho) {
            /** @var Blueprint $this */
            $c = $this->char($coluna, $tamanho);
            if (DB::getDriverName() === 'mysql') {
                $c->charset('ascii')->collation('ascii_bin');
            }

            return $c;
        });

        Blueprint::macro('auditoria', function () {
            /** @var Blueprint $this */
            $this->dateTime('criado_em', 3);
            $this->uuidCol('criado_por')->nullable();
            $this->dateTime('atualizado_em', 3);
            $this->uuidCol('atualizado_por')->nullable();
            $this->integer('versao')->default(0);
        });
    }
}

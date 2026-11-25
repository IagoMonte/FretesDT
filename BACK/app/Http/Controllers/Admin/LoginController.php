<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\AuthService;
use App\Services\LogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function __construct(private LogService $log) {}

    public function formulario(): \Illuminate\Contracts\View\View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.geral');
        }

        return view('admin.login');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $d = $request->validate(['login' => ['required', 'string', 'max:100'], 'senha' => ['required', 'string', 'max:200']]);
        $login = AuthService::normalizarLogin($d['login']);
        $u = Usuario::where('login', $login)->first();

        if (! $u || ! Hash::check($d['senha'], (string) $u->senha_hash) || ! $u->ativo() || ! $u->pode('ADMIN.ver')) {
            $this->log->sistema('WARNING', 'AUTENTICACAO', 'LOGIN_FALHA', 'Falha de login no painel /admin.', ['usuario_login' => $login]);

            return back()->withErrors(['login' => 'Login ou senha inválidos.'])->onlyInput('login');
        }

        Auth::guard('admin')->login($u);
        $request->session()->regenerate();
        $u->forceFill(['ultimo_acesso_em' => now()])->saveQuietly();
        $this->log->acesso($u->id, $request->ip());

        return redirect()->intended(route('admin.geral'));
    }

    public function sair(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}

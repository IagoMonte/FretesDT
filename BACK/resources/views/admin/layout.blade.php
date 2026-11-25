<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>@yield('titulo', 'Painel') · Gestão de Fretes</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/painel/admin.css?v={{ @filemtime(public_path('painel/admin.css')) }}">
</head>
<body>
@php
    $u = auth('admin')->user();
    $menu = [
        ['admin.geral', 'Visão geral', 'chart', 'admin'],
        ['admin.transportadoras', 'Transportadoras', 'truck', 'admin/transportadoras*'],
        ['admin.vendedores', 'Vendedores', 'users', 'admin/vendedores*'],
        ['admin.custos', 'Custos', 'coin', 'admin/custos*'],
        ['admin.auditoria', 'Auditoria', 'shield', 'admin/auditoria*'],
    ];
    $icones = [
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>',
        'truck' => '<path d="M2 6h11v10H2zM13 9h5l3 3v4h-8M6 19a1.8 1.8 0 100-.01M17 19a1.8 1.8 0 100-.01"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5M16 4.6a3.5 3.5 0 010 6.8M18.5 14.8c1.8.7 2.8 2.3 3 5.2"/>',
        'coin' => '<circle cx="12" cy="12" r="9"/><path d="M14.8 9.2c-.5-.9-1.5-1.4-2.8-1.4-1.6 0-2.8.8-2.8 2s1.1 1.7 2.8 2.1 2.8.9 2.8 2.1-1.2 2-2.8 2c-1.4 0-2.5-.6-3-1.5M12 6v1.8M12 16.2V18"/>',
        'shield' => '<path d="M12 3l8 3v6c0 4.5-3.3 8-8 9-4.7-1-8-4.5-8-9V6z"/><path d="M8.5 12l2.4 2.4 4.6-4.8"/>',
    ];
@endphp
<a class="skip-link" href="#conteudo">Ir para o conteúdo</a>
<div class="app-shell">
  <aside class="side" id="menu">
    <div class="side-brand">
      <div class="row">
        <svg class="mk" viewBox="0 0 72 72" aria-hidden="true"><defs><linearGradient id="gv" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#F0142C"/><stop offset="1" stop-color="#D6001C"/></linearGradient><linearGradient id="gg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#009047"/><stop offset="1" stop-color="#00693C"/></linearGradient></defs><path d="M40 10a22 22 0 1 1 0 44" fill="none" stroke="url(#gv)" stroke-width="13" stroke-linecap="round"/><path d="M40 54a22 22 0 0 1 0-44" fill="none" stroke="url(#gg)" stroke-width="13" stroke-linecap="round"/><rect x="12" y="30" width="13" height="34" rx="3" fill="url(#gv)"/></svg>
        <div><b>FRETES<em>DT</em></b><small>GESTÃO DE FRETES</small></div>
      </div>
    </div>
    <nav class="nav" aria-label="Painel administrativo">
      <div class="nav-cap">Painel /admin</div>
      @foreach ($menu as [$rota, $rotulo, $icone, $padrao])
        <a class="nav-item {{ request()->is($padrao) ? 'on' : '' }}" href="{{ route($rota) }}" @if (request()->is($padrao)) aria-current="page" @endif>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icones[$icone] !!}</svg>{{ $rotulo }}
        </a>
      @endforeach
    </nav>
    <div class="side-foot">
      <div class="uchip"><div class="av">{{ mb_strtoupper(mb_substr($u->nome ?? '?', 0, 1)) }}</div><div><div class="nm">{{ $u->nome }}</div><div class="rl">GESTÃO · SOMENTE LEITURA</div></div></div>
      <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="btn-out" type="submit">Sair</button></form>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="menu-btn" type="button" aria-label="Abrir menu" aria-controls="menu" data-menu>☰</button>
      <div>
        <div class="crumb">@yield('crumb', 'Painel administrativo')</div>
        <h1>@yield('h1')</h1>
      </div>
      <div class="top-right"><span class="clock">{{ now(config('frete.fuso_exibicao'))->format('d/m/Y H:i') }}</span></div>
    </header>
    <div class="road"></div>
    <main class="view" id="conteudo" tabindex="-1">
      @hasSection('periodo')
        @include('admin._periodo')
      @endif
      @yield('conteudo')
    </main>
  </div>
</div>
<script src="/painel/admin.js?v={{ @filemtime(public_path('painel/admin.js')) }}" defer></script>
</body>
</html>

<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Entrar · Gestão de Fretes</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/painel/admin.css?v={{ @filemtime(public_path('painel/admin.css')) }}">
</head>
<body class="login-body">
<div class="login-screen">
  <div class="sw s1"></div><div class="sw s2"></div><div class="sw s3h"></div><div class="sw s3"></div><div class="sw s4h"></div><div class="sw s4"></div>
  <div class="lg-left">
    <div class="lockup">
      <svg class="mk" viewBox="0 0 72 72" aria-hidden="true"><defs><linearGradient id="lv" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#E40016"/><stop offset="1" stop-color="#B00014"/></linearGradient><linearGradient id="lg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#009047"/><stop offset="1" stop-color="#00693C"/></linearGradient></defs><path d="M40 10a22 22 0 1 1 0 44" fill="none" stroke="url(#lv)" stroke-width="13" stroke-linecap="round"/><path d="M40 54a22 22 0 0 1 0-44" fill="none" stroke="url(#lg)" stroke-width="13" stroke-linecap="round"/><rect x="12" y="30" width="13" height="34" rx="3" fill="url(#lv)"/></svg>
      <div class="word">FRETES</div>
      <div class="word2">DT</div>
      <div class="divline"></div>
      <div class="tagline">GESTÃO <span>DE FRETES</span></div>
    </div>
  </div>
  <form class="lg-right" method="post" action="{{ route('admin.entrar') }}" novalidate>
    @csrf
    <h2>Painel <span>admin</span></h2>
    <p class="sub">Indicadores, auditoria e análise de transportadoras.</p>
    @error('login')<div class="lg-err" role="alert">{{ $message }}</div>@enderror
    @if ($errors->has('senha') && ! $errors->has('login'))<div class="lg-err" role="alert">{{ $errors->first('senha') }}</div>@endif
    <div class="field"><label for="login">Login <i>*</i></label><input class="inp" id="login" name="login" value="{{ old('login') }}" autocomplete="username" autofocus required></div>
    <div class="field"><label for="senha">Senha <i>*</i></label><input class="inp" id="senha" name="senha" type="password" autocomplete="current-password" required></div>
    <button class="btn btn-primary btn-block" type="submit">Entrar</button>
  </form>
</div>
</body>
</html>

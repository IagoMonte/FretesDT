@extends('admin.layout')
@section('titulo', 'Auditoria')
@section('crumb', 'Quem fez o quê, e quando')
@section('h1')<b>Auditoria</b>@endsection
@php
    use App\Support\Formato as F;
    use App\Support\Svg;
    $abas = ['operacao' => 'Operação', 'sistema' => 'Sistema', 'acessos' => 'Acessos', 'resumo' => 'Resumo de atividade'];
    $v = fn (string $c) => $f[$c] ?? '';
    $nivelPill = ['ERROR' => 'st-can', 'WARNING' => 'st-cot', 'INFO' => 'st-res', 'DEBUG' => 'st-agu'];
    $entidadeLink = fn ($l) => in_array($l['entidade'] ?? '', ['COTACAO', 'NEGOCIACAO', 'PROGRAMACAO'], true) && ! empty($l['registro_id']);
@endphp
@section('conteudo')
<div class="abas" role="tablist">
  @foreach ($abas as $k => $nome)
    <a class="aba {{ $aba === $k ? 'on' : '' }}" role="tab" href="{{ route('admin.auditoria', ['aba' => $k]) }}" @if ($aba === $k) aria-selected="true" @endif>{{ $nome }}</a>
  @endforeach
</div>

@if ($aba === 'resumo')
  @include('admin._periodo')
  @php
      $dias = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
      $horas = array_map(fn ($h) => sprintf('%02d', $h), range(0, 23));
  @endphp
  <div class="kpis">
    @include('admin._kpi', ['rotulo' => 'Eventos de negócio', 'valor' => F::num($r['total_eventos']), 'sub' => count($r['por_usuario']).' usuários', 'cor' => 'var(--green)'])
    @include('admin._kpi', ['rotulo' => 'Logins', 'valor' => F::num(array_sum(array_column($r['acessos_por_usuario'], 'logins'))), 'sub' => 'acessos com sucesso'])
    @include('admin._kpi', ['rotulo' => 'Falhas de login', 'valor' => (string) $r['falhas_login'], 'sub' => 'tentativas recusadas', 'cor' => 'var(--red)'])
    @include('admin._kpi', ['rotulo' => 'Erros do sistema', 'valor' => (string) $r['erros'], 'sub' => 'nível ERROR', 'cor' => 'var(--red)'])
  </div>
  <div class="dash2 eq">
    <section class="card">
      <div class="card-h"><span class="tag"></span><h2>Atividade por usuário</h2></div>
      <div class="card-b">{!! Svg::barras(array_map(fn ($x) => ['rotulo' => $x['usuario'], 'valor' => $x['eventos'], 'texto' => (string) $x['eventos'], 'cor' => '#00693C'], $r['por_usuario'])) !!}</div>
    </section>
    <section class="card">
      <div class="card-h"><span class="tag"></span><h2>Eventos mais frequentes</h2></div>
      <div class="card-b">{!! Svg::barras(array_map(fn ($x) => ['rotulo' => $x['evento'], 'valor' => $x['total'], 'texto' => (string) $x['total'], 'cor' => '#D6001C'], array_slice($r['por_evento'], 0, 10))) !!}</div>
    </section>
  </div>
  <div class="dash2 eq">
    <section class="card">
      <div class="card-h"><span class="tag"></span><h2>Eventos por dia</h2></div>
      <div class="card-b">{!! Svg::serie(array_map(fn ($x) => substr($x['dia'], 8, 2).'/'.substr($x['dia'], 5, 2), $r['por_dia']), [['nome' => 'Eventos', 'valores' => array_column($r['por_dia'], 'eventos'), 'tipo' => 'coluna', 'cor' => '#00693C']]) !!}</div>
    </section>
    <section class="card">
      <div class="card-h"><span class="tag"></span><h2>Horários de atividade</h2></div>
      <div class="card-b scroll-x">{!! Svg::calor($r['heatmap'], $dias, $horas, fn ($n, $d, $h) => "{$d} {$h}h: {$n} eventos", 20) !!}</div>
    </section>
  </div>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Logins por usuário</h2></div>
    <div class="tbl-wrap"><table class="tbl sortable"><thead><tr><th>Usuário</th><th class="r">Logins</th><th class="r">IPs distintos</th></tr></thead><tbody>
      @forelse ($r['acessos_por_usuario'] as $a)
        <tr><td class="strong">{{ $a['usuario'] }}</td><td class="num r">{{ $a['logins'] }}</td><td class="num r">{{ $a['ips'] }}</td></tr>
      @empty
        <tr><td colspan="3"><div class="empty">Nenhum login no período.</div></td></tr>
      @endforelse
    </tbody></table></div>
  </section>
@else
  <form class="filtros" method="get" action="{{ route('admin.auditoria') }}">
    <input type="hidden" name="aba" value="{{ $aba }}">
    <label>Usuário
      <select class="inp" name="usuario_id"><option value="">Todos</option>
        @foreach ($opcoes['usuarios'] as $u)<option value="{{ $u['id'] }}" @selected($v('usuario_id') === $u['id'])>{{ $u['nome'] }} ({{ $u['login'] }})</option>@endforeach
      </select>
    </label>
    @if ($aba === 'operacao')
      <label>Entidade
        <select class="inp" name="entidade"><option value="">Todas</option>
          @foreach ($opcoes['entidades'] as $e)<option @selected($v('entidade') === $e)>{{ $e }}</option>@endforeach
        </select>
      </label>
      <label>Evento <input class="inp" name="evento" value="{{ $v('evento') }}" placeholder="evento exato"></label>
      <label>Código ou texto <input class="inp" name="q" value="{{ $v('q') }}" placeholder="COT-000123"></label>
    @elseif ($aba === 'sistema')
      <label>Nível
        <select class="inp" name="nivel"><option value="">Todos</option>
          @foreach (['ERROR', 'WARNING', 'INFO', 'DEBUG'] as $n)<option @selected($v('nivel') === $n)>{{ $n }}</option>@endforeach
        </select>
      </label>
      <label>Categoria
        <select class="inp" name="categoria"><option value="">Todas</option>
          @foreach ($opcoes['categorias'] as $c)<option @selected($v('categoria') === $c)>{{ $c }}</option>@endforeach
        </select>
      </label>
      <label>Evento <input class="inp" name="evento" value="{{ $v('evento') }}" placeholder="evento exato"></label>
      <label>Texto <input class="inp" name="q" value="{{ $v('q') }}" placeholder="mensagem, tabela, rota"></label>
    @else
      <label>IP <input class="inp" name="ip" value="{{ $v('ip') }}" placeholder="10.0.0.1"></label>
    @endif
    <label>De <input class="inp" type="date" name="de" value="{{ $v('de') }}"></label>
    <label>Até <input class="inp" type="date" name="ate" value="{{ $v('ate') }}"></label>
    @if ($v('correlacao_id'))<input type="hidden" name="correlacao_id" value="{{ $v('correlacao_id') }}">@endif
    <button class="btn btn-dark btn-sm" type="submit">Filtrar</button>
    <a class="btn btn-line btn-sm" href="{{ route('admin.auditoria', ['aba' => $aba]) }}">Limpar</a>
  </form>

  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>{{ $abas[$aba] }}</h2><span class="cnt">{{ F::num($pag->total()) }} {{ $pag->total() === 1 ? 'registro' : 'registros' }}</span></div>
    <div class="tbl-wrap">
    @if ($aba === 'operacao')
      <table class="tbl">
        <thead><tr><th>Quando</th><th>Quem</th><th>Entidade</th><th>Registro</th><th>Evento</th><th>Status</th><th>Detalhe</th></tr></thead>
        <tbody>
        @forelse ($pag as $l)
          <tr>
            <td class="num">{{ F::dh($l['quando']) }}</td>
            <td class="strong">{{ $l['usuario'] }}</td>
            <td>{{ $l['entidade'] }}</td>
            <td class="num">@if ($entidadeLink($l))<a href="{{ route('admin.registro', [$l['entidade'], $l['registro_id']]) }}">{{ $l['codigo'] ?? 'abrir' }}</a>@else{{ $l['codigo'] ?? '—' }}@endif</td>
            <td><span class="pill st-res">{{ $l['evento'] }}</span></td>
            <td>@if ($l['de'] || $l['para'])<span class="muted">{{ $l['de'] ?? '—' }}</span> → <b>{{ $l['para'] ?? '—' }}</b>@else—@endif</td>
            <td class="detalhe">@foreach ($l['detalhe'] as $k => $val)<span class="kvp"><i>{{ $k }}</i> {{ is_array($val) ? implode(' → ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $val)) : $val }}</span>@endforeach</td>
          </tr>
        @empty
          <tr><td colspan="7"><div class="empty">Nenhum registro com esses filtros.</div></td></tr>
        @endforelse
        </tbody>
      </table>
    @elseif ($aba === 'sistema')
      <table class="tbl">
        <thead><tr><th>Quando</th><th>Nível</th><th>Categoria</th><th>Evento</th><th>Quem</th><th>Origem</th><th>Mensagem</th></tr></thead>
        <tbody>
        @forelse ($pag as $l)
          <tr>
            <td class="num">{{ F::dh($l['quando']) }}</td>
            <td><span class="pill {{ $nivelPill[$l['nivel']] ?? 'st-agu' }}">{{ $l['nivel'] }}</span></td>
            <td>{{ $l['categoria'] }}</td>
            <td class="num">{{ $l['evento'] }}</td>
            <td class="strong">{{ $l['usuario'] }}</td>
            <td class="num">{{ trim(($l['metodo'] ?? '').' '.($l['rota'] ?? '')) ?: ($l['tabela'] ?? '—') }}@if ($l['ip'])<br><small class="muted">{{ $l['ip'] }}</small>@endif</td>
            <td class="msg">
              {{ $l['mensagem'] ?? '—' }}
              @if ($l['dados_antes'] || $l['dados_depois'] || $l['contexto'])
                <details><summary>dados</summary>
                  @if ($l['dados_antes'])<pre>antes: {{ $l['dados_antes'] }}</pre>@endif
                  @if ($l['dados_depois'])<pre>depois: {{ $l['dados_depois'] }}</pre>@endif
                  @if ($l['contexto'])<pre>contexto: {{ $l['contexto'] }}</pre>@endif
                </details>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7"><div class="empty">Nenhum registro com esses filtros.</div></td></tr>
        @endforelse
        </tbody>
      </table>
    @else
      <table class="tbl">
        <thead><tr><th>Quando</th><th>Usuário</th><th>IP</th></tr></thead>
        <tbody>
        @forelse ($pag as $l)
          <tr><td class="num">{{ F::dh($l['quando']) }}</td><td class="strong">{{ $l['usuario'] }}</td><td class="num">{{ $l['ip'] ?? '—' }}</td></tr>
        @empty
          <tr><td colspan="3"><div class="empty">Nenhum acesso com esses filtros.</div></td></tr>
        @endforelse
        </tbody>
      </table>
    @endif
    </div>
    @include('admin._paginacao', ['pag' => $pag])
  </section>
@endif
@endsection

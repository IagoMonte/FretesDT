@extends('admin.layout')
@section('titulo', 'Transportadoras')
@section('crumb', 'Análise de custo')
@section('h1')<b>Transportadoras</b>@endsection
@section('periodo', '1')
@php
    use App\Support\Formato as F;
    use App\Support\Svg;
    $q = $periodo->query();
    $ativas = array_filter($ranking, fn ($t) => $t['consultas'] > 0);
    $consultas = array_sum(array_column($ranking, 'consultas'));
    $respondidas = array_sum(array_column($ranking, 'respondidas'));
    $escalaMin = $mercado ? min(array_column($mercado, 'min')) : 0;
    $escalaMax = $mercado ? max(array_column($mercado, 'max')) : 1;
    $produtosMatriz = array_values(array_unique(array_column($matriz, 'produto')));
    $transpMatriz = array_values(array_unique(array_column($matriz, 'transportadora')));
    $mapa = [];
    foreach ($matriz as $c) { $mapa[$c['transportadora']][$c['produto']] = $c['media']; }
    $grade = array_map(fn ($t) => array_map(fn ($p) => $mapa[$t][$p] ?? 0, $produtosMatriz), $transpMatriz);
    $porRota = collect($rotas)->groupBy('chave')->map(fn ($g, $rota) => ['rota' => $rota, 'vitorias' => $g->sum('vitorias'), 'derrotas' => $g->sum('derrotas')])->values();
@endphp
@section('conteudo')
<div class="kpis">
  @include('admin._kpi', ['rotulo' => 'Transportadoras com consulta', 'valor' => (string) count($ativas), 'sub' => 'de '.count($ranking).' cadastradas', 'cor' => 'var(--green)'])
  @include('admin._kpi', ['rotulo' => 'Taxa de resposta', 'valor' => F::pct($consultas ? $respondidas * 100 / $consultas : 0), 'sub' => $respondidas.' de '.$consultas.' consultas'])
  @include('admin._kpi', ['rotulo' => 'Saving gerado', 'valor' => F::brl(array_sum(array_column($ranking, 'saving'))), 'sub' => 'fechado − menor resposta', 'cor' => 'var(--green-m)'])
  @include('admin._kpi', ['rotulo' => 'Frete contratado', 'valor' => F::brl(array_sum(array_column($ranking, 'frete_contratado'))), 'sub' => array_sum(array_column($ranking, 'cargas')).' cargas'])
</div>

<section class="card">
  <div class="card-h"><span class="tag"></span><h2>Ranking</h2><span class="cnt">{{ count($ranking) }}</span></div>
  <div class="tbl-wrap">
    <table class="tbl sortable">
      <thead><tr>
        <th>Transportadora</th><th class="r">Respondidas</th><th class="r">Vitórias</th><th class="r">Taxa de vitória</th><th class="r">Mín R$/t</th><th class="r">Média R$/t</th><th class="r">Máx R$/t</th>
        <th class="r">Saving</th><th class="r">Gap médio R$/t</th><th class="r">Prazo médio</th><th class="r">Resposta em</th><th class="r">Declínio</th><th class="r">No prazo</th><th class="r">Frete contratado</th>
      </tr></thead>
      <tbody>
      @forelse ($ranking as $t)
        <tr>
          <td class="strong"><a href="{{ route('admin.transportadora', [$t['id']] + $q) }}">{{ $t['nome'] }}</a></td>
          <td class="num r">{{ $t['respondidas'] }}<small class="muted"> / {{ $t['consultas'] }}</small></td>
          <td class="num r" data-v="{{ $t['vitorias'] }}">{{ $t['vitorias'] }}</td>
          <td class="num r" data-v="{{ $t['taxa_vitoria'] }}">{{ F::pct($t['taxa_vitoria']) }}</td>
          <td class="num r" data-v="{{ $t['min'] }}">{{ $t['respondidas'] ? F::num($t['min'], 2) : '—' }}</td>
          <td class="num r" data-v="{{ $t['media'] }}">{{ $t['respondidas'] ? F::num($t['media'], 2) : '—' }}</td>
          <td class="num r" data-v="{{ $t['max'] }}">{{ $t['respondidas'] ? F::num($t['max'], 2) : '—' }}</td>
          <td class="num r good" data-v="{{ $t['saving'] }}">{{ F::brl($t['saving']) }}</td>
          <td class="num r {{ $t['gap_medio'] > 0 ? 'bad' : '' }}" data-v="{{ $t['gap_medio'] }}">{{ $t['gap_medio'] ? '+'.F::num($t['gap_medio'], 2) : '—' }}</td>
          <td class="num r" data-v="{{ $t['prazo_medio'] }}">{{ $t['prazo_medio'] ? F::num($t['prazo_medio'], 1).' d' : '—' }}</td>
          <td class="num r" data-v="{{ $t['tempo_resposta_horas'] }}">{{ F::horas($t['tempo_resposta_horas']) }}</td>
          <td class="num r" data-v="{{ $t['taxa_declinio'] }}">{{ F::pct($t['taxa_declinio']) }}</td>
          <td class="num r" data-v="{{ $t['pct_no_prazo'] }}">{{ $t['cargas'] ? F::pct($t['pct_no_prazo']) : '—' }}</td>
          <td class="num r" data-v="{{ $t['frete_contratado'] }}">{{ F::brl($t['frete_contratado']) }}</td>
        </tr>
      @empty
        <tr><td colspan="14"><div class="empty">Nenhuma transportadora cadastrada.</div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>

<div class="dash2 eq">
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Faixa de preço por produto</h2></div>
    <div class="card-b">
      <p class="muted nota">Menor, média (traço verde) e maior valor por tonelada respondido, todas as transportadoras.</p>
      @forelse ($mercado as $m)
        <div class="faixa-row">
          <span class="bl">{{ $m['produto'] }}</span>
          {!! Svg::faixa($m['min'], $m['media'], $m['max'], $escalaMin, $escalaMax) !!}
          <span class="bn">{{ F::num($m['min'], 2) }} · <b>{{ F::num($m['media'], 2) }}</b> · {{ F::num($m['max'], 2) }}</span>
        </div>
      @empty
        <div class="empty">Sem respostas no período.</div>
      @endforelse
    </div>
  </section>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Média R$/t · transportadora × produto</h2></div>
    <div class="card-b scroll-x">
      {!! Svg::calor($grade, $transpMatriz, $produtosMatriz, fn ($v, $l, $c) => $v ? "{$l} · {$c}: R$ ".F::num($v, 2).'/t' : "{$l} · {$c}: sem resposta", 46) !!}
    </div>
  </section>
</div>

<section class="card">
  <div class="card-h"><span class="tag"></span><h2>Onde ganha e onde perde · por rota</h2></div>
  <div class="tbl-wrap">
    <table class="tbl sortable">
      <thead><tr><th>Transportadora</th><th>Rota</th><th class="r">Vitórias</th><th class="r">Derrotas</th><th>Resultado</th><th class="r">Gap médio R$/t</th><th class="r">Gap %</th><th class="r">Margem sobre o 2º</th></tr></thead>
      <tbody>
      @forelse ($rotas as $r)
        <tr>
          <td class="strong"><a href="{{ route('admin.transportadora', [$r['transportadora_id']] + $q) }}">{{ $r['transportadora'] }}</a></td>
          <td>{{ $r['chave'] }}</td>
          <td class="num r good">{{ $r['vitorias'] }}</td>
          <td class="num r {{ $r['derrotas'] ? 'bad' : '' }}">{{ $r['derrotas'] }}</td>
          <td>{!! Svg::empilhada([['rotulo' => 'Vitórias', 'valor' => $r['vitorias'], 'cor' => '#00693C'], ['rotulo' => 'Derrotas', 'valor' => $r['derrotas'], 'cor' => '#D6001C']], 110) !!}</td>
          <td class="num r" data-v="{{ $r['gap_medio'] }}">{{ $r['gap_medio'] ? '+'.F::num($r['gap_medio'], 2) : '—' }}</td>
          <td class="num r" data-v="{{ $r['gap_pct'] }}">{{ $r['gap_pct'] ? F::pct($r['gap_pct']) : '—' }}</td>
          <td class="num r" data-v="{{ $r['margem_media'] }}">{{ $r['margem_media'] ? F::num($r['margem_media'], 2) : '—' }}</td>
        </tr>
      @empty
        <tr><td colspan="8"><div class="empty">Sem negociações fechadas no período.</div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>

<section class="card" id="oportunidades">
  <div class="card-h"><span class="tag"></span><h2>Oportunidades na base de custo</h2></div>
  <div class="card-b opp">
    <div>
      <h3>Poucos concorrentes <small class="muted">(até 2 preços)</small></h3>
      <p class="muted nota">Rotas com pouca competição: vale ampliar a lista de transportadoras consultadas.</p>
      <ul class="lista">
        @forelse (array_slice($oport['poucos_concorrentes'], 0, 15) as $o)
          <li><b>{{ $o['codigo'] }}</b> {{ $o['rota'] }} · {{ $o['produto'] }} <span class="pill st-agu">{{ $o['respostas'] }} {{ $o['respostas'] === 1 ? 'preço' : 'preços' }}</span></li>
        @empty
          <li class="muted">Nenhuma.</li>
        @endforelse
        @if (count($oport['poucos_concorrentes']) > 15)<li class="muted">+ {{ count($oport['poucos_concorrentes']) - 15 }} outras cotações</li>@endif
      </ul>
    </div>
    <div>
      <h3>Fechou acima do menor preço</h3>
      <p class="muted nota">Preço deixado na mesa: o fechamento não foi o menor valor respondido.</p>
      <ul class="lista">
        @forelse (array_slice($oport['fora_do_menor'], 0, 12) as $o)
          <li><b>{{ $o['codigo'] }}</b> {{ $o['rota'] }} · {{ $o['vencedora'] }} a {{ F::num($o['fechado'], 2) }} (menor {{ F::num($o['menor'], 2) }}) <span class="pill st-cot">{{ F::brl($o['perdido']) }}</span></li>
        @empty
          <li class="muted">Nenhum: todo fechamento foi pelo menor preço.</li>
        @endforelse
      </ul>
    </div>
    <div>
      <h3>Maior dispersão de preço</h3>
      <p class="muted nota">Produtos com maior diferença entre o menor e o maior valor respondido.</p>
      {!! Svg::barras(array_map(fn ($d) => ['rotulo' => $d['produto'], 'valor' => $d['amplitude'], 'texto' => 'R$ '.F::num($d['amplitude'], 2).'/t', 'cor' => '#D6001C'], array_slice($oport['dispersao'], 0, 6))) !!}
    </div>
  </div>
</section>
@endsection

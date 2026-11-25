@extends('admin.layout')
@section('titulo', $d['nome'])
@section('crumb')<a href="{{ route('admin.transportadoras', $periodo->query()) }}">Transportadoras</a> / detalhe @endsection
@section('h1')<b>{{ $d['nome'] }}</b>@endsection
@section('periodo', '1')
@php
    use App\Support\Formato as F;
    use App\Support\Svg;
    $escMin = $d['por_produto'] ? min(array_merge(array_column($d['por_produto'], 'min'), array_column($d['por_produto'], 'mercado_min'))) : 0;
    $escMax = $d['por_produto'] ? max(array_merge(array_column($d['por_produto'], 'max'), array_column($d['por_produto'], 'mercado_max'))) : 1;
    $ganha = array_values(array_filter($d['ganha_perde_rota'], fn ($r) => $r['vitorias'] > 0));
    $perde = array_values(array_filter($d['ganha_perde_rota'], fn ($r) => $r['derrotas'] > 0));
    $meses = array_map(fn ($s) => F::mes($s['mes']), $d['serie_preco']);
@endphp
@section('conteudo')
<div class="kpis">
  @include('admin._kpi', ['rotulo' => 'Taxa de vitória', 'valor' => F::pct($d['taxa_vitoria']), 'sub' => $d['vitorias'].' de '.$d['respondidas'].' respondidas', 'cor' => 'var(--green)'])
  @include('admin._kpi', ['rotulo' => 'Saving gerado', 'valor' => F::brl($d['saving']), 'sub' => 'nas cotações em que venceu', 'cor' => 'var(--green-m)'])
  @include('admin._kpi', ['rotulo' => 'Preço R$/t', 'valor' => $d['respondidas'] ? F::num($d['media'], 2) : '—', 'sub' => $d['respondidas'] ? 'mín '.F::num($d['min'], 2).' · máx '.F::num($d['max'], 2) : 'sem respostas'])
  @include('admin._kpi', ['rotulo' => 'Posição no preço', 'valor' => $d['posicao_media'] ? F::num($d['posicao_media'], 1).'º' : '—', 'sub' => '1º = mais barata', 'cor' => 'var(--green)'])
  @include('admin._kpi', ['rotulo' => 'Gap quando perde', 'valor' => $d['gap_medio'] ? '+'.F::num($d['gap_medio'], 2) : '—', 'sub' => $d['gap_pct'] ? F::pct($d['gap_pct']).' acima do fechado (R$/t)' : 'R$/t acima do fechado'])
  @include('admin._kpi', ['rotulo' => 'Responde em', 'valor' => F::horas($d['tempo_resposta_horas']), 'sub' => 'prazo médio '.($d['prazo_medio'] ? F::num($d['prazo_medio'], 1).' d' : '—'), 'cor' => 'var(--green)'])
  @include('admin._kpi', ['rotulo' => 'Entregas no prazo', 'valor' => $d['cargas'] ? F::pct($d['pct_no_prazo']) : '—', 'sub' => $d['cargas'].' cargas · '.F::brl($d['frete_contratado'])])
</div>

<div class="dash2 eq">
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Onde ganha</h2><span class="cnt">{{ count($ganha) }}</span></div>
    <div class="tbl-wrap"><table class="tbl sortable"><thead><tr><th>Rota</th><th class="r">Vitórias</th><th class="r">Margem sobre o 2º</th></tr></thead><tbody>
      @forelse ($ganha as $r)
        <tr><td class="strong">{{ $r['chave'] }}</td><td class="num r good">{{ $r['vitorias'] }}</td><td class="num r" data-v="{{ $r['margem_media'] }}">{{ $r['margem_media'] ? 'R$ '.F::num($r['margem_media'], 2).'/t' : '—' }}</td></tr>
      @empty
        <tr><td colspan="3"><div class="empty">Ainda não venceu nenhuma rota no período.</div></td></tr>
      @endforelse
    </tbody></table></div>
  </section>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Onde perde</h2><span class="cnt">{{ count($perde) }}</span></div>
    <div class="tbl-wrap"><table class="tbl sortable"><thead><tr><th>Rota</th><th class="r">Derrotas</th><th class="r">Gap médio</th><th class="r">Gap %</th></tr></thead><tbody>
      @forelse ($perde as $r)
        <tr><td class="strong">{{ $r['chave'] }}</td><td class="num r bad">{{ $r['derrotas'] }}</td><td class="num r" data-v="{{ $r['gap_medio'] }}">+{{ F::num($r['gap_medio'], 2) }}</td><td class="num r" data-v="{{ $r['gap_pct'] }}">{{ F::pct($r['gap_pct']) }}</td></tr>
      @empty
        <tr><td colspan="4"><div class="empty">Não perdeu nenhuma disputa no período.</div></td></tr>
      @endforelse
    </tbody></table></div>
  </section>
</div>

<div class="dash2 eq">
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Preço por produto contra o mercado</h2></div>
    <div class="card-b">
      <p class="muted nota">Faixa clara = menor e maior do mercado · traço verde = média do mercado · triângulo vermelho = média desta transportadora.</p>
      @forelse ($d['por_produto'] as $p)
        <div class="faixa-row">
          <span class="bl">{{ $p['produto'] }}</span>
          {!! Svg::faixa($p['mercado_min'], $p['mercado_media'], $p['mercado_max'], $escMin, $escMax, $p['media']) !!}
          <span class="bn">{{ F::num($p['media'], 2) }} <small class="muted">vs {{ F::num($p['mercado_media'], 2) }}</small></span>
        </div>
      @empty
        <div class="empty">Sem respostas no período.</div>
      @endforelse
    </div>
  </section>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Evolução do preço · vs. mercado</h2></div>
    <div class="card-b">
      {!! Svg::serie($meses, [
          ['nome' => 'Esta transportadora (R$/t)', 'valores' => array_column($d['serie_preco'], 'propria'), 'cor' => '#D6001C'],
          ['nome' => 'Média do mercado (R$/t)', 'valores' => array_column($d['serie_preco'], 'mercado'), 'cor' => '#00693C'],
      ]) !!}
    </div>
  </section>
</div>

<div class="dash2 eq">
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Por produto</h2></div>
    <div class="tbl-wrap"><table class="tbl sortable"><thead><tr><th>Produto</th><th class="r">Vitórias</th><th class="r">Derrotas</th><th class="r">Gap médio</th></tr></thead><tbody>
      @forelse ($d['ganha_perde_produto'] as $r)
        <tr><td class="strong">{{ $r['chave'] }}</td><td class="num r good">{{ $r['vitorias'] }}</td><td class="num r {{ $r['derrotas'] ? 'bad' : '' }}">{{ $r['derrotas'] }}</td><td class="num r">{{ $r['gap_medio'] ? '+'.F::num($r['gap_medio'], 2) : '—' }}</td></tr>
      @empty
        <tr><td colspan="4"><div class="empty">Sem dados.</div></td></tr>
      @endforelse
    </tbody></table></div>
  </section>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Por veículo</h2></div>
    <div class="tbl-wrap"><table class="tbl sortable"><thead><tr><th>Veículo</th><th class="r">Vitórias</th><th class="r">Derrotas</th><th class="r">Gap médio</th></tr></thead><tbody>
      @forelse ($d['ganha_perde_veiculo'] as $r)
        <tr><td class="strong">{{ $r['chave'] }}</td><td class="num r good">{{ $r['vitorias'] }}</td><td class="num r {{ $r['derrotas'] ? 'bad' : '' }}">{{ $r['derrotas'] }}</td><td class="num r">{{ $r['gap_medio'] ? '+'.F::num($r['gap_medio'], 2) : '—' }}</td></tr>
      @empty
        <tr><td colspan="4"><div class="empty">Sem dados.</div></td></tr>
      @endforelse
    </tbody></table></div>
  </section>
</div>

<section class="card">
  <div class="card-h"><span class="tag"></span><h2>Últimas respostas</h2><span class="cnt">{{ count($d['ultimas_respostas']) }}</span></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Cotação</th><th>Rota</th><th>Produto</th><th>Resposta</th><th class="r">R$/t</th><th class="r">Prazo</th><th class="r">Fechado a</th><th>Quando</th></tr></thead><tbody>
    @forelse ($d['ultimas_respostas'] as $r)
      <tr>
        <td class="strong"><a href="{{ route('admin.registro', ['COTACAO', $r['cotacao_id']]) }}">{{ $r['codigo'] }}</a></td>
        <td>{{ $r['rota'] }}</td><td>{{ $r['produto'] }}</td>
        <td>@if ($r['venceu'])<span class="pill st-apr">Venceu</span>@elseif ($r['status'] === 'DECLINADA')<span class="pill st-agu">Declinou</span>@else<span class="pill st-cot">Perdeu</span>@endif</td>
        <td class="num r">{{ $r['vt'] ? F::num($r['vt'], 2) : '—' }}</td>
        <td class="num r">{{ $r['prazo'] ? $r['prazo'].' d' : '—' }}</td>
        <td class="num r">{{ $r['fechado_vt'] ? F::num($r['fechado_vt'], 2) : '—' }}</td>
        <td class="num">{{ F::dh($r['quando']?->copy()->setTimezone(config('frete.fuso_exibicao'))) }}</td>
      </tr>
    @empty
      <tr><td colspan="8"><div class="empty">Sem respostas no período.</div></td></tr>
    @endforelse
  </tbody></table></div>
</section>
@endsection

@extends('admin.layout')
@section('titulo', 'Vendedores')
@section('crumb', 'Indicadores comerciais')
@section('h1')<b>Vendedores</b>@endsection
@section('periodo', '1')
@php
    use App\Support\Formato as F;
    use App\Support\Svg;
    $q = $periodo->query();
    $tot = fn (string $c) => array_sum(array_column($ranking, $c));
    $link = fn ($v) => route('admin.vendedor', [$v['id']] + $q);
@endphp
@section('conteudo')
<div class="kpis">
  @include('admin._kpi', ['rotulo' => 'Vendedores ativos', 'valor' => (string) count($ranking), 'sub' => $tot('cotacoes').' cotações no período', 'cor' => 'var(--green)'])
  @include('admin._kpi', ['rotulo' => 'Taxa de fechamento', 'valor' => F::pct($tot('cotacoes') ? $tot('fechadas') * 100 / $tot('cotacoes') : 0), 'sub' => $tot('fechadas').' fechadas ou programadas'])
  @include('admin._kpi', ['rotulo' => 'Saving obtido', 'valor' => F::brl($tot('saving')), 'sub' => 'somando todos os vendedores', 'cor' => 'var(--green-m)'])
  @include('admin._kpi', ['rotulo' => 'Frete contratado', 'valor' => F::brl($tot('frete_total')), 'sub' => $tot('cargas').' cargas · '.F::toneladas($tot('toneladas'))])
</div>

<section class="card">
  <div class="card-h"><span class="tag"></span><h2>Ranking</h2><span class="cnt">{{ count($ranking) }}</span></div>
  <div class="tbl-wrap">
    <table class="tbl sortable">
      <thead><tr>
        <th>Vendedor</th><th class="r">Cotações</th><th class="r">Taxa de fechamento</th><th class="r">Cancelamento</th><th class="r">Toneladas</th><th class="r">Frete total</th><th class="r">Frete R$/t</th>
        <th class="r">Saving</th><th class="r">Tempo até fechar</th><th class="r">Cargas</th><th class="r">Valor NF cotado</th><th class="r">Repetidas</th>
      </tr></thead>
      <tbody>
      @forelse ($ranking as $v)
        <tr>
          <td class="strong"><a href="{{ $link($v) }}">{{ $v['nome'] }}</a></td>
          <td class="num r">{{ $v['cotacoes'] }}</td>
          <td class="num r" data-v="{{ $v['taxa_fechamento'] }}">{{ F::pct($v['taxa_fechamento']) }}</td>
          <td class="num r {{ $v['taxa_cancelamento'] >= 30 ? 'bad' : '' }}" data-v="{{ $v['taxa_cancelamento'] }}">{{ F::pct($v['taxa_cancelamento']) }}</td>
          <td class="num r" data-v="{{ $v['toneladas'] }}">{{ F::toneladas($v['toneladas']) }}</td>
          <td class="num r" data-v="{{ $v['frete_total'] }}">{{ F::brl($v['frete_total']) }}</td>
          <td class="num r" data-v="{{ $v['frete_t_medio'] }}">{{ $v['frete_t_medio'] ? F::num($v['frete_t_medio'], 2) : '—' }}</td>
          <td class="num r good" data-v="{{ $v['saving'] }}">{{ F::brl($v['saving']) }}</td>
          <td class="num r" data-v="{{ $v['tempo_fechamento_horas'] }}">{{ F::horas($v['tempo_fechamento_horas']) }}</td>
          <td class="num r">{{ $v['cargas'] }}</td>
          <td class="num r" data-v="{{ $v['valor_nf'] }}">{{ F::brl($v['valor_nf']) }}</td>
          <td class="num r" data-v="{{ $v['pct_repetidas'] }}">{{ F::pct($v['pct_repetidas']) }}</td>
        </tr>
      @empty
        <tr><td colspan="12"><div class="empty">Nenhuma cotação no período.</div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>

<div class="dash2 eq">
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Frete contratado</h2></div>
    <div class="card-b">{!! Svg::barras(array_map(fn ($v) => ['rotulo' => $v['nome'], 'valor' => $v['frete_total'], 'texto' => F::brl($v['frete_total']), 'cor' => '#D6001C', 'link' => $link($v)], $ranking)) !!}</div>
  </section>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Saving obtido</h2></div>
    <div class="card-b">{!! Svg::barras(array_map(fn ($v) => ['rotulo' => $v['nome'], 'valor' => $v['saving'], 'texto' => F::brl($v['saving']), 'cor' => '#00693C', 'link' => $link($v)], $ranking)) !!}</div>
  </section>
</div>
<div class="dash2 eq">
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Taxa de fechamento</h2></div>
    <div class="card-b">{!! Svg::barras(array_map(fn ($v) => ['rotulo' => $v['nome'], 'valor' => $v['taxa_fechamento'], 'texto' => F::pct($v['taxa_fechamento']), 'cor' => '#00A050', 'link' => $link($v)], $ranking), 100) !!}</div>
  </section>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Tempo até fechar</h2></div>
    <div class="card-b">{!! Svg::barras(array_map(fn ($v) => ['rotulo' => $v['nome'], 'valor' => $v['tempo_fechamento_horas'], 'texto' => F::horas($v['tempo_fechamento_horas']), 'cor' => '#33463C', 'link' => $link($v)], $ranking)) !!}</div>
  </section>
</div>
@endsection

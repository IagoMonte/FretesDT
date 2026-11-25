@extends('admin.layout')
@section('titulo', $d['nome'])
@section('crumb')<a href="{{ route('admin.vendedores', $periodo->query()) }}">Vendedores</a> / detalhe @endsection
@section('h1')<b>{{ $d['nome'] }}</b>@endsection
@section('periodo', '1')
@php
    use App\Support\Formato as F;
    use App\Support\Svg;
    $meses = array_map(fn ($s) => F::mes($s['mes']), $d['por_mes']);
    $cores = ['ABERTA' => 'st-agu', 'EM_NEGOCIACAO' => 'st-tra', 'FECHADA' => 'st-res', 'PROGRAMADA' => 'st-apr', 'CANCELADA' => 'st-can'];
@endphp
@section('conteudo')
<div class="kpis">
  @include('admin._kpi', ['rotulo' => 'Cotações', 'valor' => (string) $d['cotacoes'], 'sub' => $d['abertas'].' em aberto', 'cor' => 'var(--green)'])
  @include('admin._kpi', ['rotulo' => 'Taxa de fechamento', 'valor' => F::pct($d['taxa_fechamento']), 'sub' => $d['fechadas'].' fechadas ou programadas'])
  @include('admin._kpi', ['rotulo' => 'Cancelamento', 'valor' => F::pct($d['taxa_cancelamento']), 'sub' => $d['canceladas'].' canceladas', 'cor' => 'var(--red)'])
  @include('admin._kpi', ['rotulo' => 'Saving', 'valor' => F::brl($d['saving']), 'sub' => 'fechado − menor resposta', 'cor' => 'var(--green-m)'])
  @include('admin._kpi', ['rotulo' => 'Frete contratado', 'valor' => F::brl($d['frete_total']), 'sub' => $d['cargas'].' cargas · '.F::toneladas($d['toneladas'])])
  @include('admin._kpi', ['rotulo' => 'Frete por tonelada', 'valor' => $d['frete_t_medio'] ? F::brl($d['frete_t_medio']) : '—', 'sub' => 'NF cotada '.F::brl($d['valor_nf']), 'cor' => 'var(--green)'])
  @include('admin._kpi', ['rotulo' => 'Tempo até fechar', 'valor' => F::horas($d['tempo_fechamento_horas']), 'sub' => F::pct($d['pct_repetidas']).' cotações repetidas'])
</div>

<div class="dash2 eq">
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Cotações por mês</h2></div>
    <div class="card-b">
      {!! Svg::serie($meses, [
          ['nome' => 'Cotações', 'valores' => array_column($d['por_mes'], 'cotacoes'), 'tipo' => 'coluna', 'cor' => '#BFDCCB'],
          ['nome' => 'Fechadas', 'valores' => array_column($d['por_mes'], 'fechadas'), 'cor' => '#00693C'],
      ]) !!}
    </div>
  </section>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Quando cota</h2></div>
    <div class="card-b">
      <div class="lbl">Dia da semana</div>
      {!! Svg::barras(array_map(fn ($x) => ['rotulo' => $x['dia'], 'valor' => $x['cotacoes'], 'texto' => (string) $x['cotacoes'], 'cor' => '#00693C'], $d['por_dia_semana'])) !!}
      <div class="sep"></div>
      <div class="lbl">Operação</div>
      {!! Svg::donut([['rotulo' => 'Carregamento', 'valor' => $d['mix_operacao']['CARREGAMENTO'], 'cor' => '#00693C'], ['rotulo' => 'Recebimento', 'valor' => $d['mix_operacao']['RECEBIMENTO'], 'cor' => '#D6001C']], (string) array_sum($d['mix_operacao'])) !!}
    </div>
  </section>
</div>

<div class="dash2 eq">
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Principais clientes e fornecedores</h2></div>
    <div class="tbl-wrap"><table class="tbl sortable"><thead><tr><th>Parte</th><th class="r">Cotações</th><th class="r">Toneladas</th><th class="r">Valor NF</th></tr></thead><tbody>
      @forelse ($d['top_clientes'] as $c)
        <tr><td class="strong">{{ $c['cliente'] }}</td><td class="num r">{{ $c['cotacoes'] }}</td><td class="num r" data-v="{{ $c['toneladas'] }}">{{ F::toneladas($c['toneladas']) }}</td><td class="num r" data-v="{{ $c['valor_nf'] }}">{{ F::brl($c['valor_nf']) }}</td></tr>
      @empty
        <tr><td colspan="4"><div class="empty">Sem cotações no período.</div></td></tr>
      @endforelse
    </tbody></table></div>
  </section>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Últimas cotações</h2></div>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Código</th><th>Status</th><th>Parte</th><th>Rota</th><th class="r">Toneladas</th><th class="r">Saving</th></tr></thead><tbody>
      @forelse ($d['ultimas'] as $c)
        <tr>
          <td class="strong"><a href="{{ route('admin.registro', ['COTACAO', $c['id']]) }}">{{ $c['codigo'] }}</a></td>
          <td><span class="pill {{ $cores[$c['status']] ?? 'st-agu' }}">{{ str_replace('_', ' ', $c['status']) }}</span></td>
          <td>{{ $c['parte'] }}</td><td>{{ $c['rota'] }}</td>
          <td class="num r">{{ F::toneladas($c['toneladas']) }}</td><td class="num r">{{ $c['saving'] ? F::brl($c['saving']) : '—' }}</td>
        </tr>
      @empty
        <tr><td colspan="6"><div class="empty">Sem cotações no período.</div></td></tr>
      @endforelse
    </tbody></table></div>
  </section>
</div>
@endsection

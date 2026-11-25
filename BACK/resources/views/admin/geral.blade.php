@extends('admin.layout')
@section('titulo', 'Visão geral')
@section('h1')Visão <b>geral</b>@endsection
@section('periodo', '1')
@php
    use App\Services\Admin\AnaliseGeralService as Geral;
    use App\Support\Formato as F;
    use App\Support\Svg;
    $q = $periodo->query();
    $meses = array_map(fn ($s) => F::mes($s['mes']), $serie);
    $totalOp = array_sum($operacao);
    $coresStatus = ['ABERTA' => '#7F8D85', 'EM_NEGOCIACAO' => '#33463C', 'FECHADA' => '#00A050', 'PROGRAMADA' => '#00693C', 'CANCELADA' => '#D6001C', 'ENCERRADA' => '#B0B8B3'];
    $avisos = [
        ['cotacoes_paradas', 'cotações paradas há mais de '.Geral::DIAS_COTACAO_PARADA.' dias', 'red'],
        ['respostas_vencidas', 'respostas de transportadoras sem retorno há mais de '.Geral::DIAS_RESPOSTA_VENCIDA.' dias', 'red'],
        ['entregas_atrasadas', 'entregas atrasadas', 'red'],
        ['usuarios_pendentes', 'cadastros aguardando aprovação', 'green'],
    ];
@endphp
@section('conteudo')
<div class="kpis">
  @include('admin._kpi', ['rotulo' => 'Cotações', 'valor' => F::num($k['cotacoes_total']), 'sub' => ($k['por_status']['ABERTA'] + $k['por_status']['EM_NEGOCIACAO']).' em aberto', 'delta' => $cmp['cotacoes_total']['delta'], 'cor' => 'var(--green)'])
  @include('admin._kpi', ['rotulo' => 'Conversão em carga', 'valor' => F::pct($k['conversao_pct']), 'sub' => $k['por_status']['PROGRAMADA'].' programadas', 'delta' => $cmp['conversao_pct']['delta']])
  @include('admin._kpi', ['rotulo' => 'Saving', 'valor' => F::brl($k['saving_total']), 'sub' => F::pct($k['saving_pct']).' sobre a menor resposta', 'delta' => $cmp['saving_total']['delta'], 'cor' => 'var(--green-m)'])
  @include('admin._kpi', ['rotulo' => 'Frete contratado', 'valor' => F::brl($k['frete_total']), 'sub' => $k['cargas'].' cargas', 'delta' => $cmp['frete_total']['delta']])
  @include('admin._kpi', ['rotulo' => 'Frete por tonelada', 'valor' => F::brl($k['frete_t_medio']), 'sub' => F::toneladas($k['toneladas']).' movimentadas', 'delta' => $cmp['frete_t_medio']['delta'], 'inverso' => true, 'cor' => 'var(--green)'])
  @include('admin._kpi', ['rotulo' => 'Entregas no prazo', 'valor' => F::pct($k['pct_no_prazo']), 'sub' => $k['entregas'].' entregas · atraso médio '.F::num($k['atraso_medio_dias'], 1).' d', 'delta' => $cmp['pct_no_prazo']['delta'], 'cor' => 'var(--green)'])
  @include('admin._kpi', ['rotulo' => 'Tempo até fechar', 'valor' => F::horas($k['tempo_fechamento_horas']), 'sub' => 'da criação ao fechamento', 'delta' => $cmp['tempo_fechamento_horas']['delta'], 'inverso' => true])
</div>

<div class="alertas" aria-label="Pendências de hoje">
  @foreach ($avisos as [$chave, $texto, $cor])
    <div class="alerta {{ $alertas[$chave] > 0 ? 'on '.$cor : '' }}"><b>{{ $alertas[$chave] }}</b><span>{{ $texto }}</span></div>
  @endforeach
</div>

<div class="dash2">
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Evolução mensal</h2></div>
    <div class="card-b">
      {!! Svg::serie($meses, [
          ['nome' => 'Frete contratado (R$)', 'valores' => array_column($serie, 'frete_total'), 'tipo' => 'coluna', 'cor' => '#BFDCCB', 'formato' => fn ($v) => F::brl($v)],
          ['nome' => 'Saving (R$)', 'valores' => array_column($serie, 'saving'), 'cor' => '#00693C', 'formato' => fn ($v) => F::brl($v)],
      ]) !!}
    </div>
  </section>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Funil de status</h2></div>
    <div class="card-b">
      {!! Svg::barras(collect($k['por_status'])->map(fn ($n, $s) => ['rotulo' => str_replace('_', ' ', $s), 'valor' => $n, 'texto' => (string) $n, 'cor' => $coresStatus[$s]])->values()->all()) !!}
      <div class="sep"></div>
      <div class="lbl">Operação</div>
      {!! Svg::donut([['rotulo' => 'Carregamento', 'valor' => $operacao['CARREGAMENTO'], 'cor' => '#00693C'], ['rotulo' => 'Recebimento', 'valor' => $operacao['RECEBIMENTO'], 'cor' => '#D6001C']], (string) $totalOp) !!}
    </div>
  </section>
</div>

<div class="dash2 eq">
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Rotas com mais volume</h2><span class="cnt">{{ count($rotas) }}</span></div>
    <div class="card-b">
      {!! Svg::barras(array_map(fn ($r) => ['rotulo' => $r['rota'], 'valor' => $r['toneladas'], 'texto' => F::toneladas($r['toneladas']).' · '.$r['cotacoes'].' cot.'], $rotas)) !!}
    </div>
  </section>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Produtos com mais volume</h2><span class="cnt">{{ count($produtos) }}</span></div>
    <div class="card-b">
      {!! Svg::barras(array_map(fn ($r) => ['rotulo' => $r['produto'], 'valor' => $r['toneladas'], 'texto' => F::toneladas($r['toneladas']), 'cor' => '#D6001C'], $produtos)) !!}
    </div>
  </section>
</div>

<div class="dash2 eq">
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Transportadoras · saving</h2><a class="btn btn-line btn-sm acts" href="{{ route('admin.transportadoras', $q) }}">Ver todas</a></div>
    <div class="card-b">
      {!! Svg::barras(array_map(fn ($t) => ['rotulo' => $t['nome'], 'valor' => $t['saving'], 'texto' => F::brl($t['saving']), 'cor' => '#00693C', 'link' => route('admin.transportadora', [$t['id']] + $q)], $transportadoras)) !!}
    </div>
  </section>
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>Vendedores · frete contratado</h2><a class="btn btn-line btn-sm acts" href="{{ route('admin.vendedores', $q) }}">Ver todos</a></div>
    <div class="card-b">
      {!! Svg::barras(array_map(fn ($v) => ['rotulo' => $v['nome'], 'valor' => $v['frete_total'], 'texto' => F::brl($v['frete_total']), 'cor' => '#D6001C', 'link' => route('admin.vendedor', [$v['id']] + $q)], $vendedores)) !!}
    </div>
  </section>
</div>
@endsection

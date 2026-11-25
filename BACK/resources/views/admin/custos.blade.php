@extends('admin.layout')
@section('titulo', 'Custos')
@section('crumb', 'Base de custo')
@section('h1')Custo do <b>frete</b>@endsection
@section('periodo', '1')
@php
    use App\Support\Formato as F;
    use App\Support\Svg;
    $secoes = [
        ['Por produto', 'produto', 'Produto', $produtos],
        ['Por rota', 'rota', 'Rota', $rotas],
        ['Por tipo de veículo', 'veiculo', 'Veículo', $veiculos],
        ['Clientes e fornecedores com maior volume', 'parte', 'Parte', $partes],
    ];
@endphp
@section('conteudo')
<p class="muted nota">Considera só cotações <b>fechadas ou programadas</b>. O valor por tonelada é o do fechamento; "frete sobre NF" é o frete fechado dividido pelo valor da nota cotada.</p>
@foreach ($secoes as [$titulo, $campo, $rotulo, $linhas])
  @php
      $min = $linhas ? min(array_column($linhas, 'min')) : 0;
      $max = $linhas ? max(array_column($linhas, 'max')) : 1;
  @endphp
  <section class="card">
    <div class="card-h"><span class="tag"></span><h2>{{ $titulo }}</h2><span class="cnt">{{ count($linhas) }}</span></div>
    <div class="tbl-wrap">
      <table class="tbl sortable">
        <thead><tr><th>{{ $rotulo }}</th><th class="r">Fechamentos</th><th class="r">Toneladas</th><th>Faixa R$/t (mín · média · máx)</th><th class="r">Mín</th><th class="r">Média</th><th class="r">Máx</th><th class="r">Amplitude</th><th class="r">Frete total</th><th class="r">Frete sobre NF</th><th class="r">Saving</th></tr></thead>
        <tbody>
        @forelse ($linhas as $l)
          <tr>
            <td class="strong">{{ $l[$campo] }}</td>
            <td class="num r">{{ $l['fechamentos'] }}</td>
            <td class="num r" data-v="{{ $l['toneladas'] }}">{{ F::toneladas($l['toneladas']) }}</td>
            <td>{!! Svg::faixa($l['min'], $l['media'], $l['max'], $min, $max) !!}</td>
            <td class="num r" data-v="{{ $l['min'] }}">{{ F::num($l['min'], 2) }}</td>
            <td class="num r" data-v="{{ $l['media'] }}">{{ F::num($l['media'], 2) }}</td>
            <td class="num r" data-v="{{ $l['max'] }}">{{ F::num($l['max'], 2) }}</td>
            <td class="num r" data-v="{{ $l['amplitude'] }}">{{ F::num($l['amplitude'], 2) }}</td>
            <td class="num r" data-v="{{ $l['frete_total'] }}">{{ F::brl($l['frete_total']) }}</td>
            <td class="num r" data-v="{{ $l['frete_sobre_nf_pct'] }}">{{ F::pct($l['frete_sobre_nf_pct']) }}</td>
            <td class="num r good" data-v="{{ $l['saving'] }}">{{ F::brl($l['saving']) }}</td>
          </tr>
        @empty
          <tr><td colspan="11"><div class="empty">Sem fechamentos no período.</div></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </section>
@endforeach
@endsection

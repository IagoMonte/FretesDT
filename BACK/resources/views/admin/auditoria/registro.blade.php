@extends('admin.layout')
@section('titulo', 'Linha do tempo')
@section('crumb')<a href="{{ route('admin.auditoria') }}">Auditoria</a> / registro @endsection
@section('h1')Linha do <b>tempo</b>@endsection
@php
    use App\Support\Formato as F;
    $codigo = collect($linhas)->pluck('codigo')->filter()->first();
    $nivelPill = ['ERROR' => 'st-can', 'WARNING' => 'st-cot', 'INFO' => 'st-res', 'DEBUG' => 'st-agu'];
@endphp
@section('conteudo')
<section class="card">
  <div class="card-h"><span class="tag"></span><h2>{{ $codigo ?? $entidade }}</h2><span class="cnt">{{ count($linhas) }} {{ count($linhas) === 1 ? 'evento' : 'eventos' }}</span></div>
  <div class="card-b">
    @forelse ($linhas as $l)
      @if ($loop->first || $l['quando']->toDateString() !== $linhas[$loop->index - 1]['quando']->toDateString())
        <div class="tl-dia">{{ $l['quando']->format('d/m/Y') }}</div>
      @endif
      <div class="tl {{ $l['origem'] }}">
        <div class="tl-h">{{ $l['quando']->format('H:i:s') }}</div>
        <div class="tl-pt"><i></i></div>
        <div class="tl-b">
          <div>
            <span class="pill {{ $l['origem'] === 'operacao' ? 'st-res' : ($nivelPill[$l['nivel']] ?? 'st-agu') }}">{{ $l['evento'] }}</span>
            <span class="muted">{{ $l['origem'] === 'operacao' ? 'negócio' : 'sistema' }}</span>
            · <b>{{ $l['usuario'] }}</b>
            @if ($l['de'] || $l['para'])· <span class="muted">{{ $l['de'] ?? '—' }}</span> → <b>{{ $l['para'] ?? '—' }}</b>@endif
          </div>
          @if ($l['mensagem'])<div class="muted">{{ $l['mensagem'] }}</div>@endif
          @if ($l['detalhe'])<div class="detalhe">@foreach ($l['detalhe'] as $k => $val)<span class="kvp"><i>{{ $k }}</i> {{ is_array($val) ? implode(' → ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $val)) : $val }}</span>@endforeach</div>@endif
        </div>
      </div>
    @empty
      <div class="empty">Nenhum evento registrado para este item.</div>
    @endforelse
  </div>
</section>
@endsection

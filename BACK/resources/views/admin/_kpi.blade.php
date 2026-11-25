@php
    $cor = $cor ?? 'var(--red)';
    $d = $delta ?? null;
    $bom = $d === null ? null : (($inverso ?? false) ? $d <= 0 : $d >= 0);
@endphp
<div class="kpi" style="--kc:{{ $cor }}">
  <div class="kl">{{ $rotulo }}</div>
  <div class="kv {{ strlen($valor) > 9 ? 'money' : '' }}">{{ $valor }}</div>
  <div class="ks">{{ $sub ?? '' }}
    @if ($d !== null && $d != 0)<span class="delta {{ $bom ? 'up' : 'down' }}" title="Variação contra o período anterior">{{ \App\Support\Formato::delta($d) }}</span>@endif
  </div>
</div>

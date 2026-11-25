@if ($pag->lastPage() > 1)
  @php
      $atual = $pag->currentPage();
      $ultima = $pag->lastPage();
      $de = max(1, $atual - 2);
      $ate = min($ultima, $atual + 2);
  @endphp
  <nav class="pager" aria-label="Paginação">
    <a class="btn btn-line btn-sm {{ $atual <= 1 ? 'off' : '' }}" href="{{ $pag->url(max(1, $atual - 1)) }}" @if ($atual <= 1) aria-disabled="true" tabindex="-1" @endif>‹ Anterior</a>
    @if ($de > 1)<a class="pg" href="{{ $pag->url(1) }}">1</a>@if ($de > 2)<span class="muted">…</span>@endif @endif
    @for ($i = $de; $i <= $ate; $i++)
      <a class="pg {{ $i === $atual ? 'on' : '' }}" href="{{ $pag->url($i) }}" @if ($i === $atual) aria-current="page" @endif>{{ $i }}</a>
    @endfor
    @if ($ate < $ultima)@if ($ate < $ultima - 1)<span class="muted">…</span>@endif<a class="pg" href="{{ $pag->url($ultima) }}">{{ $ultima }}</a>@endif
    <a class="btn btn-line btn-sm {{ $atual >= $ultima ? 'off' : '' }}" href="{{ $pag->url(min($ultima, $atual + 1)) }}" @if ($atual >= $ultima) aria-disabled="true" tabindex="-1" @endif>Próxima ›</a>
    <span class="muted">página {{ $atual }} de {{ $ultima }}</span>
  </nav>
@endif

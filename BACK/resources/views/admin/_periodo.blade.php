@php($atual = $periodo->personalizado ? null : $periodo->dias)
<form class="periodo" method="get" action="{{ url()->current() }}" aria-label="Período">
  <span class="lbl">Período</span>
  <div class="chipz">
    @foreach (\App\Support\Periodo::OPCOES as $n)
      <a class="chip {{ $atual === $n ? 'on' : '' }}" href="{{ url()->current() }}?periodo={{ $n }}">{{ $n === 365 ? '12 meses' : $n.' dias' }}</a>
    @endforeach
  </div>
  <label>De <input class="inp" type="date" name="de" value="{{ $periodo->de->toDateString() }}"></label>
  <label>Até <input class="inp" type="date" name="ate" value="{{ $periodo->ate->toDateString() }}"></label>
  <button class="btn btn-dark btn-sm" type="submit">Aplicar</button>
  <span class="muted per-txt">{{ $periodo->rotulo() }} · comparado com {{ $periodo->anterior()->rotulo() }}</span>
</form>

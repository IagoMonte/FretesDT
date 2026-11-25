(function () {
  'use strict';

  var menu = document.getElementById('menu');
  var btn = document.querySelector('[data-menu]');
  if (menu && btn) {
    btn.addEventListener('click', function () {
      var aberto = menu.classList.toggle('aberto');
      btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.classList.contains('aberto')) {
        menu.classList.remove('aberto');
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
      }
    });
  }

  function valor(td) {
    var v = td.getAttribute('data-v');
    if (v !== null && v !== '' && !isNaN(parseFloat(v))) return parseFloat(v);
    var t = td.textContent.trim();
    var n = parseFloat(t.replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.'));
    return isNaN(n) || /[A-Za-zÀ-ú]{3,}/.test(t) ? t.toLowerCase() : n;
  }

  document.querySelectorAll('table.sortable').forEach(function (tabela) {
    var ths = tabela.querySelectorAll('thead th');
    ths.forEach(function (th, col) {
      th.setAttribute('tabindex', '0');
      var ordenar = function () {
        var asc = th.getAttribute('aria-sort') !== 'ascending';
        ths.forEach(function (o) { o.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        var corpo = tabela.tBodies[0];
        var linhas = Array.prototype.slice.call(corpo.rows).filter(function (r) { return r.cells.length > col; });
        linhas.sort(function (a, b) {
          var x = valor(a.cells[col]);
          var y = valor(b.cells[col]);
          var c = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), 'pt-BR');
          return asc ? c : -c;
        });
        linhas.forEach(function (r) { corpo.appendChild(r); });
      };
      th.addEventListener('click', ordenar);
      th.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); ordenar(); }
      });
    });
  });
})();

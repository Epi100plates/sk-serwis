/* Panel Studio-Komp - interakcje (bez zależności). */
(function () {
  'use strict';

  var body = document.body;
  var toggle = document.querySelector('[data-sidebar-toggle]');
  var backdrop = document.querySelector('[data-sidebar-backdrop]');

  function setSidebar(open) {
    body.classList.toggle('sidebar-open', open);
    if (toggle) toggle.setAttribute('aria-expanded', String(open));
    if (backdrop) backdrop.hidden = !open;
  }

  if (toggle) {
    toggle.addEventListener('click', function () {
      setSidebar(!body.classList.contains('sidebar-open'));
    });
  }
  if (backdrop) {
    backdrop.addEventListener('click', function () { setSidebar(false); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') setSidebar(false);
  });

  // Potwierdzenia akcji destrukcyjnych
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!window.confirm(el.getAttribute('data-confirm') || 'Na pewno?')) {
        e.preventDefault();
      }
    });
  });

  // Licznik znaków dla pól SEO
  document.querySelectorAll('[data-counter]').forEach(function (field) {
    var target = document.querySelector(field.getAttribute('data-counter'));
    if (!target) return;
    var max = parseInt(field.getAttribute('data-max') || '160', 10);
    function update() {
      var len = field.value.length;
      target.textContent = len + ' / ' + max;
      target.style.color = len > max ? '#b42318' : '';
    }
    field.addEventListener('input', update);
    update();
  });

  // Proste kopiowanie
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      if (!text || !navigator.clipboard) return;
      navigator.clipboard.writeText(text).then(function () {
        var old = btn.textContent;
        btn.textContent = 'Skopiowano';
        setTimeout(function () { btn.textContent = old; }, 1400);
      });
    });
  });

  // Zwijane sekcje formularza
  document.querySelectorAll('[data-toggle-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var el = document.querySelector(btn.getAttribute('data-toggle-target'));
      if (el) el.hidden = !el.hidden;
    });
  });

  // Szybkie dodawanie pól list (przyciski "dodaj pozycję" w edytorze)
  document.querySelectorAll('[data-list-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var wrap = document.querySelector(btn.getAttribute('data-list-add'));
      if (!wrap) return;
      var template = wrap.querySelector('template');
      if (template) {
        var clone = template.content.cloneNode(true);
        wrap.appendChild(clone);
      } else {
        var input = document.createElement('input');
        input.type = 'text';
        input.name = btn.getAttribute('data-list-name') || 'items[]';
        wrap.appendChild(input);
      }
    });
  });

  // Edytor list: usuwanie wiersza
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-list-remove]');
    if (!btn) return;
    var row = btn.closest('[data-list-row]');
    if (row) row.remove();
  });

  // Filtrowanie tabeli
  document.querySelectorAll('[data-table-filter]').forEach(function (input) {
    var table = document.querySelector(input.getAttribute('data-table-filter'));
    if (!table) return;
    input.addEventListener('input', function () {
      var q = input.value.toLowerCase();
      table.querySelectorAll('tbody tr').forEach(function (tr) {
        tr.hidden = q !== '' && tr.textContent.toLowerCase().indexOf(q) === -1;
      });
    });
  });

  // Podgląd wybranego pliku z biblioteki mediów (modal)
  var picker = document.querySelector('[data-media-picker]');
  if (picker) {
    var input = document.querySelector(picker.getAttribute('data-target') || '#media-field');
    document.querySelectorAll('[data-pick-media]').forEach(function (item) {
      item.addEventListener('click', function () {
        if (input) {
          input.value = item.getAttribute('data-pick-media');
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        picker.hidden = true;
      });
    });
    document.querySelectorAll('[data-media-picker-open]').forEach(function (btn) {
      btn.addEventListener('click', function () { picker.hidden = false; });
    });
    var close = picker.querySelector('[data-media-picker-close]');
    if (close) close.addEventListener('click', function () { picker.hidden = true; });
  }

  // Wykresy: tooltip na punktach
  document.querySelectorAll('[data-chart-point]').forEach(function (point) {
    var wrap = point.closest('[data-chart]');
    if (!wrap) return;
    var tip = wrap.querySelector('[data-chart-tip]');
    if (!tip) return;
    point.addEventListener('mouseenter', function () {
      tip.textContent = point.getAttribute('data-chart-point');
      tip.hidden = false;
    });
    point.addEventListener('mouseleave', function () { tip.hidden = true; });
  });

  // Autozapis szkicu w edytorze (localStorage, tylko dla wygody)
  var editor = document.querySelector('[data-editor]');
  if (editor) {
    var key = 'sk_draft_' + location.pathname + location.search;
    try {
      var saved = localStorage.getItem(key);
      if (saved) {
        var bar = document.createElement('div');
        bar.className = 'alert alert--info';
        bar.innerHTML = 'Znaleziono lokalny szkic z poprzedniej sesji. <button type="button" class="btn btn--sm" data-restore-draft>Przywróć</button> <button type="button" class="btn btn--sm btn--ghost" data-discard-draft>Odrzuć</button>';
        editor.parentNode.insertBefore(bar, editor);
        bar.querySelector('[data-restore-draft]').addEventListener('click', function () {
          try {
            var data = JSON.parse(saved);
            editor.querySelectorAll('input[name], textarea[name]').forEach(function (el) {
              if (data[el.name] !== undefined) el.value = data[el.name];
            });
          } catch (err) {}
          bar.remove();
        });
        bar.querySelector('[data-discard-draft]').addEventListener('click', function () {
          localStorage.removeItem(key);
          bar.remove();
        });
      }
      var timer;
      editor.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          var data = {};
          editor.querySelectorAll('input[name], textarea[name]').forEach(function (el) {
            if (el.type !== 'password') data[el.name] = el.value;
          });
          try { localStorage.setItem(key, JSON.stringify(data)); } catch (err) {}
        }, 600);
      });
      editor.addEventListener('submit', function () {
        try { localStorage.removeItem(key); } catch (err) {}
      });
    } catch (err) {}
  }
})();

/* ==========================================================================
   ReviverLegisFlow — JavaScript global
   Componentes: tema, sidebar, dropdown, toasts, modais, confirmação,
   drawer, requisições (com CSRF), máscara de moeda e gráficos.
   ========================================================================== */
(function () {
  'use strict';

  const doc = document.documentElement;
  const meta = (n) => (document.querySelector('meta[name="' + n + '"]') || {}).content || '';
  const BASE = meta('base-url');

  const LF = (window.LF = window.LF || {});
  LF.base = BASE;

  /* ---------- Utilidades ---------- */
  LF.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  };
  LF.money = function (v, simbolo) {
    const n = Number(v || 0);
    const txt = n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return simbolo === false ? txt : 'R$ ' + txt;
  };
  LF.moneyCompact = function (v) {
    const n = Number(v || 0), a = Math.abs(n);
    if (a >= 1e9) return 'R$ ' + (n / 1e9).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' bi';
    if (a >= 1e6) return 'R$ ' + (n / 1e6).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mi';
    if (a >= 1e4) return 'R$ ' + (n / 1e3).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mil';
    return LF.money(n);
  };
  LF.parseMoney = function (s) {
    if (s == null || s === '') return 0;
    s = String(s).replace(/[^\d,.-]/g, '');
    const p = s.lastIndexOf('.'), c = s.lastIndexOf(',');
    if (p > -1 && c > -1) s = p < c ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
    else if (c > -1) s = s.replace(',', '.');
    else if ((s.match(/\./g) || []).length > 1 || /^-?\d{1,3}\.\d{3}$/.test(s)) s = s.replace(/\./g, '');
    const n = parseFloat(s);
    return isNaN(n) ? 0 : Math.round(n * 100) / 100;
  };
  LF.dateBR = function (iso, hora) {
    if (!iso) return '—';
    const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (m) {
      // Formata sem passar por Date (evita deslocamento de fuso em datas puras)
      return m[3] + '/' + m[2] + '/' + m[1] + (hora && m[4] ? ' ' + m[4] + ':' + m[5] : '');
    }
    const d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d)) return LF.esc(iso);
    const opt = { day: '2-digit', month: '2-digit', year: 'numeric' };
    if (hora) Object.assign(opt, { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleString('pt-BR', opt).replace(',', '');
  };
  LF.pageData = function () {
    const el = document.getElementById('page-data');
    try { return el ? JSON.parse(el.textContent) : {}; } catch (e) { return {}; }
  };

  /* ---------- Requisições ---------- */
  LF.request = async function (url, opts) {
    opts = opts || {};
    const headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': meta('csrf-token'), 'Accept': 'application/json' }, opts.headers || {});
    let body = opts.body;
    if (body && !(body instanceof FormData) && typeof body === 'object') {
      const fd = new FormData();
      Object.keys(body).forEach((k) => fd.append(k, body[k] == null ? '' : body[k]));
      body = fd;
    }
    let resp;
    try {
      resp = await fetch(url, { method: opts.method || (body ? 'POST' : 'GET'), headers, body, credentials: 'same-origin' });
    } catch (e) {
      throw new Error('Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.');
    }
    let data = null;
    try { data = await resp.json(); } catch (e) { /* resposta não-JSON */ }
    if (resp.status === 401) {
      LF.toast('Sua sessão expirou. Você será levado ao login.', 'warning');
      setTimeout(() => { window.location.href = BASE + '/php/login.php?erro=acesso_restrito&redirect_url=' + encodeURIComponent(location.pathname + location.search); }, 1500);
      throw new Error('Sessão expirada.');
    }
    if (!resp.ok || !data || data.status === 'error') {
      const err = new Error((data && data.message) || 'Ocorreu um erro inesperado (código ' + resp.status + ').');
      err.data = data;
      err.status = resp.status;
      throw err;
    }
    return data;
  };

  /* ---------- Toasts ---------- */
  const ICONS = { success: 'fa-circle-check', error: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
  LF.toast = function (msg, type, ms) {
    type = type === 'danger' ? 'error' : (type || 'success');
    const region = document.getElementById('toasts') || (function () {
      const r = document.createElement('div'); r.className = 'toast-region'; r.id = 'toasts'; document.body.appendChild(r); return r;
    })();
    const t = document.createElement('div');
    t.className = 'toast toast-' + type;
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    t.innerHTML = '<i class="fa-solid ' + (ICONS[type] || ICONS.info) + '"></i><div>' + LF.esc(msg) + '</div><button type="button" class="toast-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>';
    const close = () => { t.classList.add('is-leaving'); setTimeout(() => t.remove(), 200); };
    t.querySelector('.toast-close').addEventListener('click', close);
    region.appendChild(t);
    setTimeout(close, ms || (type === 'error' ? 7000 : 4000));
  };

  /* ---------- Modais ---------- */
  let lastFocus = null;
  LF.openModal = function (id) {
    const bd = typeof id === 'string' ? document.getElementById(id) : id;
    if (!bd) return;
    lastFocus = document.activeElement;
    bd.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => {
      const f = bd.querySelector('[autofocus], input:not([type=hidden]):not([readonly]):not([disabled]), select:not([disabled]), textarea, button.btn-primary');
      if (f) f.focus();
    }, 50);
  };
  LF.closeModal = function (id) {
    const bd = typeof id === 'string' ? document.getElementById(id) : id;
    if (!bd) return;
    bd.classList.remove('is-open');
    if (!document.querySelector('.modal-backdrop.is-open, .drawer.is-open')) document.body.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  };

  /** Confirmação. Retorna Promise<boolean>. */
  LF.confirm = function (opts) {
    opts = Object.assign({ title: 'Tem certeza?', text: '', confirmText: 'Confirmar', cancelText: 'Cancelar', tone: 'danger', icon: 'fa-triangle-exclamation' }, opts || {});
    return new Promise((resolve) => {
      const bd = document.createElement('div');
      bd.className = 'modal-backdrop';
      bd.innerHTML =
        '<div class="modal modal-sm" role="alertdialog" aria-modal="true" aria-labelledby="cf-t">' +
        '<div class="modal-body confirm-body"><div class="confirm-icon ' + opts.tone + '"><i class="fa-solid ' + opts.icon + '"></i></div>' +
        '<h2 id="cf-t">' + LF.esc(opts.title) + '</h2>' + (opts.text ? '<p>' + LF.esc(opts.text) + '</p>' : '') + '</div>' +
        '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-r="0">' + LF.esc(opts.cancelText) + '</button>' +
        '<button type="button" class="btn ' + (opts.tone === 'danger' ? 'btn-danger' : 'btn-primary') + '" data-r="1">' + LF.esc(opts.confirmText) + '</button></div></div>';
      document.body.appendChild(bd);
      const prev = document.activeElement;
      const done = (v) => { bd.remove(); if (!document.querySelector('.modal-backdrop.is-open')) document.body.style.overflow = ''; if (prev && prev.focus) prev.focus(); document.removeEventListener('keydown', onKey); resolve(v); };
      const onKey = (e) => { if (e.key === 'Escape') done(false); };
      bd.addEventListener('click', (e) => { const b = e.target.closest('[data-r]'); if (b) done(b.dataset.r === '1'); else if (e.target === bd) done(false); });
      document.addEventListener('keydown', onKey);
      bd.classList.add('is-open');
      document.body.style.overflow = 'hidden';
      setTimeout(() => bd.querySelector('[data-r="1"]').focus(), 30);
    });
  };

  /* ---------- Drawer ---------- */
  LF.openDrawer = function (id) {
    const d = document.getElementById(id);
    if (!d) return;
    lastFocus = document.activeElement;
    d.classList.add('is-open');
    const bd = document.querySelector('[data-drawer-backdrop="' + id + '"]');
    if (bd) bd.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => { const c = d.querySelector('.drawer-close'); if (c) c.focus(); }, 50);
  };
  LF.closeDrawer = function (id) {
    const d = document.getElementById(id);
    if (!d) return;
    d.classList.remove('is-open');
    const bd = document.querySelector('[data-drawer-backdrop="' + id + '"]');
    if (bd) bd.classList.remove('is-open');
    if (!document.querySelector('.modal-backdrop.is-open')) document.body.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  };

  /* ---------- Botão com carregamento ---------- */
  LF.loading = function (btn, on, texto) {
    if (!btn) return;
    if (on) {
      btn.dataset.html = btn.innerHTML;
      btn.classList.add('is-loading');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span>' + (texto ? '<span>' + LF.esc(texto) + '</span>' : '');
    } else if (btn.dataset.html) {
      btn.innerHTML = btn.dataset.html;
      btn.classList.remove('is-loading');
      btn.disabled = false;
    }
  };

  /* ---------- Máscara de moeda (campo .input-money) ---------- */
  LF.bindMoney = function (root) {
    (root || document).querySelectorAll('input.input-money:not([data-money-bound])').forEach((el) => {
      el.dataset.moneyBound = '1';
      el.setAttribute('inputmode', 'decimal');
      el.addEventListener('blur', () => {
        if (el.value.trim() === '') return;
        el.value = LF.money(LF.parseMoney(el.value), false);
        el.dispatchEvent(new Event('change', { bubbles: true }));
      });
      el.addEventListener('focus', () => { setTimeout(() => el.select(), 0); });
    });
  };

  /* Gráficos: implementados em assets/js/charts.js (carregado só nas páginas com gráficos) */
  LF.rebuildCharts = LF.rebuildCharts || function () {};

  /* ---------- Tema ---------- */
  function toggleTheme() {
    const dark = !doc.classList.contains('dark');
    doc.classList.toggle('dark', dark);
    if (LF.rebuildCharts) LF.rebuildCharts();
    LF.request(BASE + '/php/ajax_salvar_tema.php', { body: { tema: dark ? 'dark' : 'light' } })
      .catch((e) => LF.toast('Não foi possível salvar sua preferência de tema: ' + e.message, 'warning'));
  }

  /* ---------- Sidebar ---------- */
  const isDesktop = () => window.innerWidth > 1024;
  function openSidebar() { document.body.classList.add('sidebar-open'); }
  function closeSidebar() { document.body.classList.remove('sidebar-open'); }
  function toggleCollapse() {
    const c = !doc.classList.contains('sidebar-collapsed');
    doc.classList.toggle('sidebar-collapsed', c);
    try { localStorage.setItem('lf.sidebar', c ? 'collapsed' : 'expanded'); } catch (e) {}
  }

  /* ---------- Delegação de eventos ---------- */
  document.addEventListener('click', function (e) {
    const tp = e.target.closest('[data-toggle-password]');
    if (tp) {
      const inp = document.getElementById(tp.dataset.togglePassword);
      if (inp) {
        const show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        tp.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
        tp.innerHTML = '<i class="fa-regular ' + (show ? 'fa-eye-slash' : 'fa-eye') + '"></i>';
      }
      return;
    }
    const a = e.target.closest('[data-action]');
    if (a) {
      switch (a.dataset.action) {
        case 'toggle-theme': e.preventDefault(); toggleTheme(); return;
        case 'sidebar-open': e.preventDefault(); openSidebar(); return;
        case 'sidebar-close': e.preventDefault(); closeSidebar(); return;
        case 'sidebar-collapse': e.preventDefault(); if (isDesktop()) toggleCollapse(); return;
        case 'dropdown': {
          e.preventDefault();
          const dd = a.closest('.dropdown');
          const open = !dd.classList.contains('is-open');
          document.querySelectorAll('.dropdown.is-open').forEach((x) => x.classList.remove('is-open'));
          dd.classList.toggle('is-open', open);
          a.setAttribute('aria-expanded', open ? 'true' : 'false');
          return;
        }
        case 'modal-open': e.preventDefault(); LF.openModal(a.dataset.target); return;
        case 'modal-close': e.preventDefault(); LF.closeModal(a.closest('.modal-backdrop')); return;
        case 'drawer-close': e.preventDefault(); LF.closeDrawer(a.dataset.target || (a.closest('.drawer') || {}).id); return;
      }
    }
    if (!e.target.closest('.dropdown')) {
      document.querySelectorAll('.dropdown.is-open').forEach((x) => { x.classList.remove('is-open'); const b = x.querySelector('[aria-expanded]'); if (b) b.setAttribute('aria-expanded', 'false'); });
    }
    // Clique fora do modal fecha (somente modais marcados como dispensáveis)
    if (e.target.classList && e.target.classList.contains('modal-backdrop') && e.target.dataset.dismissable !== 'false') {
      LF.closeModal(e.target);
    }
    if (e.target.matches && e.target.matches('.drawer-backdrop')) {
      LF.closeDrawer(e.target.dataset.drawerBackdrop);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      const m = document.querySelector('.modal-backdrop.is-open');
      if (m && m.dataset.dismissable !== 'false') { LF.closeModal(m); return; }
      const d = document.querySelector('.drawer.is-open');
      if (d) { LF.closeDrawer(d.id); return; }
      closeSidebar();
      document.querySelectorAll('.dropdown.is-open').forEach((x) => x.classList.remove('is-open'));
    }
    // Atalho "/" para a busca global
    if (e.key === '/' && !e.ctrlKey && !e.metaKey && !/^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || '')) && !e.target.isContentEditable) {
      const s = document.querySelector('[data-shortcut="/"]');
      if (s && s.offsetParent !== null) { e.preventDefault(); s.focus(); }
    }
  });

  /* Formulários com confirmação: <form data-confirm="Mensagem" data-confirm-title="..."> */
  document.addEventListener('submit', function (e) {
    const f = e.target;
    if (f.dataset.confirm && !f.dataset.confirmed) {
      e.preventDefault();
      LF.confirm({ title: f.dataset.confirmTitle || 'Tem certeza?', text: f.dataset.confirm, confirmText: f.dataset.confirmButton || 'Confirmar', tone: f.dataset.confirmTone || 'danger' })
        .then((ok) => { if (ok) { f.dataset.confirmed = '1'; if (f.requestSubmit) f.requestSubmit(e.submitter || undefined); else f.submit(); } });
      return;
    }
    // Indicador de carregamento em formulários tradicionais (não-AJAX)
    // (verificado depois dos demais handlers: se algum cancelou o envio, nada acontece)
    if (!f.dataset.ajax) {
      const btn = e.submitter || f.querySelector('button[type="submit"]');
      if (btn && !btn.dataset.noLoading) setTimeout(() => { if (!e.defaultPrevented) LF.loading(btn, true, btn.dataset.loadingText || ''); }, 0);
    }
  }, true);

  /* ---------- Inicialização ---------- */
  document.addEventListener('DOMContentLoaded', function () {
    LF.bindMoney();
    // Mensagens "flash" vindas do servidor
    const fl = document.getElementById('flash-data');
    if (fl) { try { JSON.parse(fl.textContent).forEach((m) => LF.toast(m.message, m.type)); } catch (e) {} }
    // Fecha o menu mobile ao navegar
    document.querySelectorAll('.sidebar .nav-link[href]').forEach((l) => l.addEventListener('click', closeSidebar));
    // Linhas de tabela clicáveis: <tr data-href="...">
    document.querySelectorAll('tr[data-href]').forEach((tr) => {
      tr.addEventListener('click', (e) => { if (!e.target.closest('a,button,input,select,label')) window.location.href = tr.dataset.href; });
    });
    // Seletores que navegam ao mudar (filtros rápidos)
    document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => { const f = el.form; if (f) { if (f.requestSubmit) f.requestSubmit(); else f.submit(); } }));
    // Upload: mostrar nome do arquivo e arrastar/soltar
    document.querySelectorAll('.file-drop').forEach((z) => {
      const inp = z.querySelector('input[type=file]');
      const out = z.parentElement.querySelector('.file-name');
      const show = () => { if (out) out.textContent = inp.files.length ? '📎 ' + inp.files[0].name : ''; };
      inp.addEventListener('change', show);
      ['dragenter', 'dragover'].forEach((ev) => z.addEventListener(ev, (e) => { e.preventDefault(); z.classList.add('is-drag'); }));
      ['dragleave', 'drop'].forEach((ev) => z.addEventListener(ev, (e) => { e.preventDefault(); z.classList.remove('is-drag'); }));
      z.addEventListener('drop', (e) => { if (e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; show(); } });
    });
  });

  window.addEventListener('resize', () => { if (isDesktop()) closeSidebar(); });
})();

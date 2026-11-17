(function () {
  'use strict';
  const URL_PAG = 'totais_mensais.php';
  const tabela = document.getElementById('pivot');
  const fmt = (n) => Number(n || 0).toLocaleString('pt-BR');

  function recalcular() {
    if (!tabela) return;
    const porMes = Array(13).fill(0);
    let geral = 0;
    tabela.querySelectorAll('tbody tr').forEach((tr) => {
      let soma = 0, temValor = false;
      tr.querySelectorAll('.cell-edit').forEach((inp) => {
        const v = parseInt(inp.dataset.original, 10);
        if (!isNaN(v)) { soma += v; temValor = true; porMes[+inp.dataset.mes] += v; }
      });
      tr.querySelector('[data-total]').textContent = temValor || !tr.classList.contains('is-suggested') ? fmt(soma) : '–';
      geral += soma;
    });
    for (let m = 1; m <= 12; m++) {
      const c = tabela.querySelector('[data-total-mes="' + m + '"]');
      if (c) c.textContent = fmt(porMes[m]);
    }
    tabela.querySelector('[data-total-geral]').textContent = fmt(geral);
    const tg = document.getElementById('total-geral');
    if (tg) tg.textContent = fmt(geral);
  }

  async function salvar(inp) {
    const bruto = inp.value.trim();
    const original = inp.dataset.original;
    if (bruto === original) return;
    if (bruto === '' && original !== '') {
      // Campo apagado: volta ao valor salvo (registros só são removidos pela vara inteira)
      inp.value = original;
      LF.toast('Para zerar um mês, digite 0.', 'info');
      return;
    }
    if (!/^\d+$/.test(bruto)) {
      inp.classList.add('is-error');
      LF.toast('Digite apenas números inteiros (0 ou maior).', 'error');
      return;
    }
    const tr = inp.closest('tr');
    inp.classList.remove('is-error', 'is-saved');
    inp.classList.add('is-saving');
    try {
      await LF.request(URL_PAG, { body: { action: 'salvar_manual', ano: tabela.dataset.ano, mes: inp.dataset.mes, tipo_vara: tr.dataset.vara, quantidade: bruto } });
      inp.dataset.original = String(parseInt(bruto, 10));
      inp.value = inp.dataset.original;
      inp.classList.toggle('is-zero', inp.value === '0');
      inp.classList.remove('is-saving');
      void inp.offsetWidth;
      inp.classList.add('is-saved');
      if (tr.classList.contains('is-suggested')) {
        tr.classList.remove('is-suggested');
        const sub = tr.querySelector('.cell-sub'); if (sub) sub.remove();
        const acts = tr.querySelector('td.actions');
        if (acts && !acts.querySelector('[data-excluir]')) {
          acts.innerHTML = '<button type="button" class="btn btn-icon danger" data-excluir title="Excluir todos os registros desta vara" aria-label="Excluir vara"><i class="fa-regular fa-trash-can"></i></button>';
        }
      }
      recalcular();
    } catch (err) {
      inp.classList.remove('is-saving');
      inp.classList.add('is-error');
      LF.toast(err.message, 'error');
    }
  }

  if (tabela) {
    tabela.addEventListener('focusin', (e) => { if (e.target.classList.contains('cell-edit')) e.target.select(); });
    tabela.addEventListener('change', (e) => { if (e.target.classList.contains('cell-edit')) salvar(e.target); });
    tabela.addEventListener('keydown', (e) => {
      const inp = e.target;
      if (!inp.classList.contains('cell-edit')) return;
      if (e.key === 'Escape') { inp.value = inp.dataset.original; inp.classList.remove('is-error'); inp.blur(); return; }
      if (e.key === 'Enter' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const mes = inp.dataset.mes;
        const tr = e.key === 'ArrowUp' ? inp.closest('tr').previousElementSibling : inp.closest('tr').nextElementSibling;
        const alvo = tr ? tr.querySelector('.cell-edit[data-mes="' + mes + '"]') : null;
        if (alvo) alvo.focus(); else inp.blur();
      }
    });
    tabela.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-excluir]');
      if (!b) return;
      const vara = b.closest('tr').dataset.vara;
      const ok = await LF.confirm({
        title: 'Excluir vara do ano?',
        text: 'Todos os 12 registros mensais de "' + vara + '" em ' + tabela.dataset.ano + ' serão excluídos. Esta ação não pode ser desfeita.',
        confirmText: 'Excluir registros',
      });
      if (!ok) return;
      try {
        const r = await LF.request(URL_PAG, { body: { action: 'excluir_vara', ano: tabela.dataset.ano, tipo_vara: vara } });
        LF.toast(r.message, 'success');
        setTimeout(() => window.location.reload(), 600);
      } catch (err) { LF.toast(err.message, 'error'); }
    });
  }

  // Nova vara
  const abrir = () => { document.getElementById('form-vara').reset(); LF.openModal('modal-vara'); };
  const btn = document.getElementById('btn-nova-vara');
  if (btn) btn.addEventListener('click', abrir);
  document.querySelectorAll('[data-act-global="nova-vara"]').forEach((b) => b.addEventListener('click', abrir));

  document.getElementById('form-vara').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    if (!f.tipo_vara.value.trim()) { f.tipo_vara.closest('.field').classList.add('has-error'); f.tipo_vara.focus(); return; }
    const sb = f.querySelector('[type=submit]');
    LF.loading(sb, true);
    try {
      await LF.request(URL_PAG, { body: new FormData(f) });
      LF.toast('Vara adicionada.', 'success');
      setTimeout(() => window.location.reload(), 500);
    } catch (err) { LF.loading(sb, false); LF.toast(err.message, 'error'); }
  });
})();

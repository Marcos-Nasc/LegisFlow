(function () {
  'use strict';
  const d = LF.pageData();
  const URL_PAG = 'processos_detalhados.php';
  const form = document.getElementById('form-processo');
  const modalId = 'modal-processo';
  const $ = (s, r) => (r || document).querySelector(s);
  let atual = null; // processo aberto no drawer
  let sujo = false;

  /* ---------- Formulário ---------- */
  function limparErros() {
    form.querySelectorAll('.field.has-error').forEach((f) => f.classList.remove('has-error'));
  }
  function mostrarErros(erros) {
    let primeiro = null;
    Object.keys(erros || {}).forEach((campo) => {
      const inp = form.querySelector('[name="' + campo + '"]');
      if (!inp) return;
      const field = inp.closest('.field');
      field.classList.add('has-error');
      const fe = field.querySelector('.field-error');
      if (fe) fe.textContent = erros[campo];
      if (!primeiro) primeiro = inp;
    });
    if (primeiro) primeiro.focus();
  }
  function calcularEconomia() {
    const v = (n) => LF.parseMoney(form.elements[n].value);
    const calc = v('valor_causa') - v('despesas_processuais_1') - v('despesas_processuais_2') - v('valor_pago');
    $('#economia-calc').textContent = LF.money(calc);
    $('#economia-calc').dataset.valor = calc;
    return calc;
  }

  function abrirForm(proc) {
    form.reset();
    limparErros();
    $('#processo_id').value = proc ? proc.id : '';
    $('#modal-titulo').textContent = proc ? 'Editar processo' : 'Novo processo';
    $('#btn-salvar').textContent = proc ? 'Salvar alterações' : 'Cadastrar processo';
    if (proc) {
      ['numero_processo', 'autor', 'data_recebimento', 'ano', 'mes', 'status', 'materia', 'central_custo', 'sentenca_1_instancia', 'recurso']
        .forEach((c) => { if (form.elements[c]) form.elements[c].value = proc[c] == null ? '' : proc[c]; });
      ['valor_causa', 'despesas_processuais_1', 'despesas_processuais_2', 'valor_pago', 'economia']
        .forEach((c) => { form.elements[c].value = LF.money(proc[c], false); });
      form.dataset.autoRef = '0';
    } else {
      const hoje = new Date();
      form.elements.data_recebimento.value = hoje.getFullYear() + '-' + String(hoje.getMonth() + 1).padStart(2, '0') + '-' + String(hoje.getDate()).padStart(2, '0');
      form.elements.ano.value = hoje.getFullYear();
      form.elements.mes.value = hoje.getMonth() + 1;
      form.elements.status.value = 'em andamento';
      form.dataset.autoRef = '1';
    }
    calcularEconomia();
    sujo = false;
    LF.openModal(modalId);
  }

  // Data de recebimento preenche ano/mês de referência em novos cadastros
  form.elements.data_recebimento.addEventListener('change', () => {
    const v = form.elements.data_recebimento.value;
    if (form.dataset.autoRef === '1' && /^\d{4}-\d{2}-\d{2}$/.test(v)) {
      form.elements.ano.value = v.slice(0, 4);
      form.elements.mes.value = parseInt(v.slice(5, 7), 10);
    }
  });
  ['ano', 'mes'].forEach((c) => form.elements[c].addEventListener('change', () => { form.dataset.autoRef = '0'; }));
  form.addEventListener('input', () => { sujo = true; });
  form.addEventListener('change', (e) => { if (e.target.classList.contains('input-money')) calcularEconomia(); });
  $('#usar-calculo').addEventListener('click', () => {
    form.elements.economia.value = LF.money(calcularEconomia(), false);
    sujo = true;
  });
  form.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit')); }
  });

  // Fechar com alterações não salvas pede confirmação
  document.getElementById(modalId).addEventListener('click', async (e) => {
    const fechar = e.target.closest('[data-action="modal-close"]');
    if (!fechar) return;
    if (sujo) {
      e.stopImmediatePropagation();
      e.preventDefault();
      const ok = await LF.confirm({ title: 'Descartar alterações?', text: 'As informações digitadas neste formulário serão perdidas.', confirmText: 'Descartar', cancelText: 'Continuar editando', tone: 'warning' });
      if (ok) { sujo = false; LF.closeModal(modalId); }
    }
  }, true);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    limparErros();
    // Validação local dos obrigatórios
    const faltando = {};
    if (!form.elements.numero_processo.value.trim()) faltando.numero_processo = 'Informe o número do processo.';
    if (!form.elements.autor.value.trim()) faltando.autor = 'Informe o autor.';
    if (!form.elements.data_recebimento.value) faltando.data_recebimento = 'Informe a data de recebimento.';
    if (!form.elements.ano.value) faltando.ano = 'Informe o ano.';
    if (Object.keys(faltando).length) { mostrarErros(faltando); return; }

    const btn = $('#btn-salvar');
    LF.loading(btn, true, 'Salvando…');
    try {
      const r = await LF.request(URL_PAG, { body: new FormData(form) });
      sujo = false;
      LF.closeModal(modalId);
      LF.toast(r.message, 'success');
      setTimeout(() => {
        const u = new URL(window.location.href);
        u.searchParams.delete('novo');
        u.searchParams.set('ver', r.id);
        window.location.href = u.toString();
      }, 500);
    } catch (err) {
      LF.loading(btn, false);
      LF.toast(err.message, 'error');
      // Reaproveita erros de campo devolvidos pelo servidor
      if (err.data && err.data.errors) mostrarErros(err.data.errors);
    }
  });

  /* ---------- Detalhes ---------- */
  const fmtCampo = {
    valor_causa: LF.money, despesas_processuais_1: LF.money, despesas_processuais_2: LF.money, valor_pago: LF.money, economia: LF.money,
  };
  function bloco(rotulo, valor, classe) {
    return '<div class="' + (classe || '') + '"><dt>' + LF.esc(rotulo) + '</dt><dd' + (classe === 'full longtext' ? ' class="longtext"' : '') + '>' + valor + '</dd></div>';
  }
  function txt(v) { return v ? LF.esc(v) : '<span class="text-muted">—</span>'; }

  function renderHistorico(h) {
    if (!h.length) return '<p class="text-muted">Nenhum registro de alteração encontrado.</p>';
    return '<ul class="timeline">' + h.map((x) => {
      let detalhe = LF.esc(x.detalhes || '');
      const idx = (x.detalhes || '').indexOf('Alterações: ');
      if (idx > -1) {
        const itens = x.detalhes.slice(idx + 12).replace(/\.$/, '').split('; ');
        detalhe = '<div class="diff">' + itens.map((it) => {
          const m = it.match(/^(.*?): "(.*)" → "(.*)"$/);
          return m ? '<div><strong>' + LF.esc(m[1]) + ':</strong> <del>' + LF.esc(m[2]) + '</del> → <ins>' + LF.esc(m[3]) + '</ins></div>' : '<div>' + LF.esc(it) + '</div>';
        }).join('') + '</div>';
      }
      return '<li><div class="tl-title">' + LF.esc(x.acao) + '</div><div class="tl-meta">' + LF.dateBR(x.data_ocorrencia, true) + ' · ' + LF.esc(x.usuario || 'Sistema') + '</div><div class="tl-detail">' + detalhe + '</div></li>';
    }).join('') + '</ul>';
  }

  async function abrirDetalhes(id) {
    $('#drawer-titulo').textContent = 'Carregando…';
    $('#drawer-status').innerHTML = '';
    $('#drawer-body').innerHTML = '<div class="stack"><div class="skeleton" style="width:70%"></div><div class="skeleton" style="width:45%"></div><div class="skeleton" style="width:60%"></div></div>';
    LF.openDrawer('drawer-processo');
    try {
      const r = await LF.request(URL_PAG + '?action=get_processo&id=' + encodeURIComponent(id));
      const p = (atual = r.processo);
      const calc = (+p.valor_causa || 0) - (+p.despesas_processuais_1 || 0) - (+p.despesas_processuais_2 || 0) - (+p.valor_pago || 0);
      const stMap = { 'em andamento': ['info', 'Em andamento'], finalizado: ['success', 'Finalizado'], arquivado: ['neutral', 'Arquivado'] };
      const st = stMap[p.status] || ['neutral', p.status || '—'];
      $('#drawer-titulo').textContent = p.numero_processo;
      $('#drawer-status').innerHTML = '<span class="badge badge-' + st[0] + '">' + LF.esc(st[1]) + '</span>';
      $('#drawer-body').innerHTML =
        '<section class="drawer-section"><dl class="details">' +
          bloco('Autor', txt(p.autor), 'full') +
          bloco('Matéria', txt(p.materia), 'full') +
          bloco('Central de custo', txt(p.central_custo)) +
          bloco('Recebido em', LF.dateBR(p.data_recebimento)) +
          bloco('Referência', LF.esc((d.meses[(+p.mes || 1) - 1] || '?') + '/' + (p.ano || '?'))) +
          bloco('Cadastrado', LF.dateBR(p.data_cadastro) + (p.cadastrado_por ? ' por ' + LF.esc(p.cadastrado_por) : '')) +
        '</dl></section>' +
        '<section class="drawer-section"><h3>Valores</h3><dl class="details">' +
          bloco('Valor da causa', '<span class="num">' + LF.money(p.valor_causa) + '</span>') +
          bloco('Valor pago', '<span class="num">' + LF.money(p.valor_pago) + '</span>') +
          bloco('Despesas processuais 1', '<span class="num">' + LF.money(p.despesas_processuais_1) + '</span>') +
          bloco('Despesas processuais 2', '<span class="num">' + LF.money(p.despesas_processuais_2) + '</span>') +
          bloco('Economia informada', '<span class="num ' + (+p.economia < 0 ? 'text-danger' : 'text-success') + '">' + LF.money(p.economia) + '</span>') +
          bloco('Economia calculada', '<span class="num">' + LF.money(calc) + '</span> <span class="info-tip" title="Valor da causa − despesas − valor pago"><i class="fa-regular fa-circle-question"></i></span>') +
        '</dl></section>' +
        '<section class="drawer-section"><h3>Decisões</h3><dl class="details">' +
          bloco('Sentença 1ª instância', txt(p.sentenca_1_instancia), 'full longtext') +
          bloco('Recurso', txt(p.recurso), 'full longtext') +
        '</dl></section>' +
        '<section class="drawer-section"><h3>Histórico</h3>' + renderHistorico(r.historico || []) + '</section>';
    } catch (err) {
      $('#drawer-titulo').textContent = 'Processo';
      $('#drawer-body').innerHTML = '<div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i><div class="alert-body">' + LF.esc(err.message) + '</div></div>';
      atual = null;
    }
  }

  async function carregarEEditar(id) {
    try {
      const r = await LF.request(URL_PAG + '?action=get_processo&id=' + encodeURIComponent(id));
      abrirForm(r.processo);
    } catch (err) { LF.toast(err.message, 'error'); }
  }

  async function excluir(id, numero) {
    const ok = await LF.confirm({
      title: 'Excluir processo?',
      text: 'O processo ' + numero + ' será removido definitivamente. Esta ação não pode ser desfeita.',
      confirmText: 'Excluir processo',
    });
    if (!ok) return;
    try {
      const r = await LF.request(URL_PAG, { body: { action: 'excluir_processo', id } });
      LF.toast(r.message, 'success');
      LF.closeDrawer('drawer-processo');
      const tr = document.querySelector('tr[data-id="' + id + '"]');
      if (tr) { tr.style.opacity = '.3'; }
      setTimeout(() => window.location.reload(), 600);
    } catch (err) { LF.toast(err.message, 'error'); }
  }

  /* ---------- Eventos ---------- */
  const tabela = document.getElementById('tabela-processos');
  if (tabela) {
    tabela.addEventListener('click', (e) => {
      const tr = e.target.closest('tr[data-id]');
      if (!tr) return;
      const id = tr.dataset.id;
      const btn = e.target.closest('[data-act]');
      const act = btn ? btn.dataset.act : 'ver';
      if (e.target.closest('a')) return;
      if (act === 'ver') abrirDetalhes(id);
      if (act === 'editar') carregarEEditar(id);
      if (act === 'excluir') excluir(id, tr.querySelector('.cell-strong').textContent.trim());
    });
  }
  const novo = document.getElementById('btn-novo');
  if (novo) novo.addEventListener('click', () => abrirForm(null));
  document.querySelectorAll('[data-act-global="novo"]').forEach((b) => b.addEventListener('click', () => abrirForm(null)));
  $('#drawer-editar').addEventListener('click', () => { if (atual) { LF.closeDrawer('drawer-processo'); abrirForm(atual); } });
  $('#drawer-excluir').addEventListener('click', () => { if (atual) excluir(atual.id, atual.numero_processo); });

  // Atalho "n" para novo processo
  document.addEventListener('keydown', (e) => {
    if (e.key === 'n' && !e.ctrlKey && !e.metaKey && !/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) && !document.querySelector('.modal-backdrop.is-open,.drawer.is-open')) {
      e.preventDefault(); abrirForm(null);
    }
  });

  // Abertura direta via URL (?ver=ID ou ?novo=1)
  if (d.abrir) abrirDetalhes(d.abrir);
  else if (d.novo) abrirForm(null);
})();

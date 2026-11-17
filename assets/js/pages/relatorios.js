(function () {
  'use strict';
  const d = LF.pageData();
  const longos = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
  const titulo = (i) => longos[i] + ' de ' + d.ano;

  if (d.relatorio === 'performance') {
    LF.chart('chart-mensal', () => ({
      type: 'bar', format: 'money', labels: d.meses, tooltipTitle: titulo, ariaLabel: 'Economia, despesas e valor pago por mês',
      series: [
        { name: 'Economia', data: d.mensal.eco, color: '--chart-4' },
        { name: 'Despesas', data: d.mensal.desp, color: '--chart-1' },
        { name: 'Valor pago', data: d.mensal.pago, color: '--chart-5' },
      ],
    }));
    LF.chart('chart-composicao', () => ({
      type: 'doughnut', format: 'money', centerLabel: 'Total',
      series: [
        { name: 'Economia', value: d.composicao.economia, color: '--chart-4' },
        { name: 'Despesas', value: d.composicao.despesas, color: '--chart-1' },
        { name: 'Valor pago', value: d.composicao.pago, color: '--chart-5' },
      ],
    }));
  }

  if (d.relatorio === 'provisao') {
    let modo = 'mensal';
    LF.chart('chart-risco', () => ({
      type: modo === 'mensal' ? 'bar' : 'line', format: 'money', labels: d.meses, tooltipTitle: titulo,
      ariaLabel: 'Valor das causas por mês',
      series: [{ name: modo === 'mensal' ? 'Risco do mês' : 'Risco acumulado', data: modo === 'mensal' ? d.mensal : d.acumulado, color: '--chart-5' }],
    }));
    const seg = document.getElementById('modo-risco');
    if (seg) seg.addEventListener('click', (e) => {
      const b = e.target.closest('[data-modo]');
      if (!b) return;
      modo = b.dataset.modo;
      seg.querySelectorAll('button').forEach((x) => { x.classList.toggle('is-active', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      LF.rebuildCharts();
    });
  }

  if (d.relatorio === 'volume') {
    LF.chart('chart-volume', () => ({
      type: 'bar', stacked: true, showTotal: true, format: 'int', labels: d.meses, tooltipTitle: titulo,
      ariaLabel: 'Novas ações por mês e tipo de vara',
      series: [
        { name: 'Cíveis', data: d.civeis, color: '--chart-1' },
        { name: 'Trabalhistas', data: d.trabalhistas, color: '--chart-2' },
        { name: 'Outros', data: d.outros, color: '--chart-3' },
      ],
    }));
  }
})();

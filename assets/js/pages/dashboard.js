(function () {
  'use strict';
  const d = LF.pageData();
  const mesesLongos = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

  LF.chart('chart-volume', () => ({
    type: 'bar',
    stacked: true,
    showTotal: true,
    format: 'int',
    labels: d.meses,
    tooltipTitle: (i) => mesesLongos[i] + ' de ' + d.ano,
    ariaLabel: 'Volume de entrada mensal por tipo de vara',
    series: [
      { name: 'Cíveis', data: d.volume.civeis, color: '--chart-1' },
      { name: 'Trabalhistas', data: d.volume.trabalhistas, color: '--chart-2' },
      { name: 'Outros', data: d.volume.outros, color: '--chart-3' },
    ],
  }));

  LF.chart('chart-status', () => ({
    type: 'doughnut',
    format: 'int',
    centerLabel: 'Processos',
    series: [
      { name: 'Em andamento', value: d.status['em andamento'], color: '--chart-2' },
      { name: 'Finalizados', value: d.status['finalizado'], color: '--chart-4' },
      { name: 'Arquivados', value: d.status['arquivado'], color: '--chart-3' },
    ],
  }));

})();

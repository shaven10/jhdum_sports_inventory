(function() {
    if (!window.analyticsData) return;

    const data = window.analyticsData;
    const colors = data.colors;

    Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
    Chart.defaults.plugins.legend.labels.usePointStyle = true;

    function makeColors(count) {
        const result = [];
        for (let i = 0; i < count; i++) {
            result.push(colors[i % colors.length]);
        }
        return result;
    }

    new Chart(document.getElementById('monthlyChart'), {
        type: 'line',
        data: {
            labels: data.monthly.labels,
            datasets: [
                {
                    label: 'Total Requests',
                    data: data.monthly.totals,
                    borderColor: colors[0],
                    backgroundColor: colors[0] + '20',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                },
                {
                    label: 'Returned',
                    data: data.monthly.returned,
                    borderColor: colors[3],
                    backgroundColor: 'transparent',
                    tension: 0.4,
                    pointRadius: 3,
                },
                {
                    label: 'Active',
                    data: data.monthly.active,
                    borderColor: colors[2],
                    backgroundColor: 'transparent',
                    tension: 0.4,
                    pointRadius: 3,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });

    new Chart(document.getElementById('categoryChart'), {
        type: 'doughnut',
        data: {
            labels: data.categories.labels,
            datasets: [{
                data: data.categories.counts,
                backgroundColor: makeColors(data.categories.labels.length),
                borderWidth: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });

    new Chart(document.getElementById('mostBorrowedChart'), {
        type: 'bar',
        data: {
            labels: data.mostBorrowed.labels,
            datasets: [{
                label: 'Borrow Count',
                data: data.mostBorrowed.counts,
                backgroundColor: colors[0],
                borderRadius: 6,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });

    new Chart(document.getElementById('utilizationChart'), {
        type: 'bar',
        data: {
            labels: data.utilization.labels,
            datasets: [{
                label: 'Utilization %',
                data: data.utilization.rates,
                backgroundColor: makeColors(data.utilization.labels.length),
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } }
        }
    });

    new Chart(document.getElementById('dowChart'), {
        type: 'bar',
        data: {
            labels: data.dayOfWeek.labels,
            datasets: [{
                label: 'Requests',
                data: data.dayOfWeek.counts,
                backgroundColor: colors[1],
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });

    new Chart(document.getElementById('purposeChart'), {
        type: 'polarArea',
        data: {
            labels: data.purposes.labels,
            datasets: [{
                data: data.purposes.counts,
                backgroundColor: makeColors(data.purposes.labels.length).map(c => c + '99'),
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });

    new Chart(document.getElementById('statusChart'), {
        type: 'pie',
        data: {
            labels: data.status.labels,
            datasets: [{
                data: data.status.counts,
                backgroundColor: makeColors(data.status.labels.length),
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });

    function debounce(fn, delay) {
        let timer;
        return function() {
            clearTimeout(timer);
            timer = setTimeout(fn, delay);
        };
    }

    window.addEventListener('resize', debounce(function() {
        Object.values(Chart.instances).forEach(function(chart) {
            chart.resize();
        });
    }, 250));
})();

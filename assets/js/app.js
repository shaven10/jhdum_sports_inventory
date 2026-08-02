document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    const confirmBtns = document.querySelectorAll('[data-confirm]');
    confirmBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    const returnDateInput = document.getElementById('return_date');
    const borrowDateInput = document.getElementById('borrow_date');
    if (returnDateInput && borrowDateInput) {
        borrowDateInput.addEventListener('change', function() {
            returnDateInput.min = this.value;
            if (returnDateInput.value && returnDateInput.value < this.value) {
                returnDateInput.value = this.value;
            }
        });
    }

    const quantityInput = document.getElementById('quantity');
    const maxQty = document.getElementById('max_quantity');
    if (quantityInput && maxQty) {
        quantityInput.max = maxQty.value;
        quantityInput.addEventListener('change', function() {
            if (parseInt(this.value) > parseInt(maxQty.value)) {
                this.value = maxQty.value;
            }
        });
    }

    initMobileNav();
    initResponsiveTables();
    initResponsiveBtnGroups();
});

function initMobileNav() {
    const navCollapse = document.getElementById('navbarNav');
    if (!navCollapse) return;

    navCollapse.querySelectorAll('.nav-link:not(.dropdown-toggle), .dropdown-item').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth < 992 && navCollapse.classList.contains('show')) {
                bootstrap.Collapse.getOrCreateInstance(navCollapse).hide();
            }
        });
    });
}

function initResponsiveTables() {
    const wrappers = document.querySelectorAll('.table-responsive');
    const mobileQuery = window.matchMedia('(max-width: 767.98px)');

    function enhanceTables() {
        wrappers.forEach(function(wrapper) {
            const table = wrapper.querySelector('table');
            if (!table) return;

            if (mobileQuery.matches) {
                wrapper.classList.add('table-responsive-mobile');
                const headers = [];
                table.querySelectorAll('thead th').forEach(function(th) {
                    headers.push(th.textContent.trim());
                });
                table.querySelectorAll('tbody tr').forEach(function(row) {
                    row.querySelectorAll('td').forEach(function(td, index) {
                        if (headers[index]) {
                            td.setAttribute('data-label', headers[index]);
                        }
                    });
                });
            } else {
                wrapper.classList.remove('table-responsive-mobile');
            }
        });
    }

    enhanceTables();
    mobileQuery.addEventListener('change', enhanceTables);
    window.addEventListener('resize', debounce(enhanceTables, 200));
}

function initResponsiveBtnGroups() {
    document.querySelectorAll('.filter-bar .btn-group').forEach(function(group) {
        group.classList.add('btn-group-responsive');
    });
}

function debounce(fn, delay) {
    let timer;
    return function() {
        clearTimeout(timer);
        timer = setTimeout(fn, delay);
    };
}

function printReport() {
    window.print();
}

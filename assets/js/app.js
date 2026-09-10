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
    initPasswordToggle();
    initReportExportButtons();
});

function initPasswordToggle() {
    document.querySelectorAll('[data-toggle-password]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const input = document.querySelector(this.dataset.togglePassword);
            if (!input) return;

            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';

            const icon = this.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye', !show);
                icon.classList.toggle('bi-eye-slash', show);
            }

            this.title = show ? 'Hide password' : 'Show password';
            this.setAttribute('aria-label', this.title);
        });
    });
}

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
    const mobileWrappers = document.querySelectorAll('.table-responsive-mobile');
    mobileWrappers.forEach(function(wrapper) {
        wrapper.classList.remove('table-responsive-mobile');
        wrapper.dataset.wasMobileTable = '1';
    });

    const restore = function() {
        document.querySelectorAll('.table-responsive[data-was-mobile-table="1"]').forEach(function(wrapper) {
            wrapper.classList.add('table-responsive-mobile');
            delete wrapper.dataset.wasMobileTable;
        });
        window.removeEventListener('afterprint', restore);
    };

    window.addEventListener('afterprint', restore);
    window.print();
}

function initReportExportButtons() {
    document.querySelectorAll('button[onclick*="printReport"]').forEach(function(btn) {
        if (btn.closest('.report-export-actions') || btn.dataset.exportEnhanced === '1') {
            return;
        }

        btn.dataset.exportEnhanced = '1';
        const group = document.createElement('div');
        group.className = 'btn-group report-export-actions';
        group.setAttribute('role', 'group');
        group.setAttribute('aria-label', 'Report export');
        btn.parentNode.insertBefore(group, btn);
        group.appendChild(btn);

        const imageBtn = document.createElement('button');
        imageBtn.type = 'button';
        imageBtn.className = btn.className;
        imageBtn.innerHTML = '<i class="bi bi-image"></i> Download Image';
        imageBtn.addEventListener('click', function() {
            downloadReportImage(imageBtn);
        });
        group.appendChild(imageBtn);
    });
}

function shouldSkipReportCaptureElement(el) {
    if (!el || el.nodeType !== 1) {
        return true;
    }
    if (el.classList.contains('no-print') && !el.classList.contains('d-print-block')) {
        return true;
    }
    if (el.classList.contains('page-header') || el.classList.contains('filter-bar') || el.classList.contains('season-bar')) {
        return true;
    }
    return false;
}

function shouldSkipPrintScheduleCaptureElement(el) {
    if (shouldSkipReportCaptureElement(el)) {
        return true;
    }
    if (el.classList.contains('card') && el.classList.contains('no-print')) {
        return true;
    }
    return false;
}

function prepareReportCaptureClone(root) {
    root.querySelectorAll('.d-none').forEach(function(el) {
        if (el.classList.contains('d-print-block')
            || el.classList.contains('report-brand-header')
            || el.classList.contains('report-brand-footer')) {
            el.classList.remove('d-none');
            el.style.display = 'block';
        }
    });

    if (root.classList && root.classList.contains('d-none')) {
        root.classList.remove('d-none');
        root.style.display = 'block';
    }

    root.querySelectorAll('.no-print').forEach(function(el) {
        el.remove();
    });

    root.querySelectorAll('.table-responsive-mobile').forEach(function(wrapper) {
        wrapper.classList.remove('table-responsive-mobile');
    });

    root.querySelectorAll('img').forEach(function(img) {
        img.crossOrigin = 'anonymous';
    });
}

function cloneReportCaptureElement(el) {
    const clone = el.cloneNode(true);
    prepareReportCaptureClone(clone);
    return clone;
}

function wrapReportCaptureNode(inner) {
    const outer = document.createElement('div');
    outer.className = 'report-image-capture-root';
    outer.appendChild(inner);
    return outer;
}

function buildReportCaptureNode() {
    const explicit = document.querySelector('[data-report-capture], .roster-print-doc, .cert-print-root');
    if (explicit) {
        const inner = document.createElement('div');
        inner.className = 'report-image-capture-inner';
        inner.appendChild(cloneReportCaptureElement(explicit));
        return wrapReportCaptureNode(inner);
    }

    const main = document.querySelector('main.app-main, main');
    const inner = document.createElement('div');
    inner.className = 'report-image-capture-inner';

    if (!main) {
        inner.appendChild(cloneReportCaptureElement(document.body));
        return wrapReportCaptureNode(inner);
    }

    const printSchedule = main.querySelector('.match-schedule-print');
    const skipFn = printSchedule ? shouldSkipPrintScheduleCaptureElement : shouldSkipReportCaptureElement;

    main.querySelectorAll(':scope > *').forEach(function(el) {
        if (skipFn(el)) {
            return;
        }
        inner.appendChild(cloneReportCaptureElement(el));
    });

    return wrapReportCaptureNode(inner);
}

function reportImageFilename(trigger) {
    if (trigger && trigger.dataset.reportFilename) {
        return trigger.dataset.reportFilename.replace(/\.png$/i, '') + '.png';
    }

    const title = (document.title || 'report').replace(/\s*-\s*.*$/u, '').trim();
    const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'report';
    const date = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    return slug + '-' + date + '.png';
}

async function downloadReportImage(trigger) {
    if (typeof html2canvas !== 'function') {
        window.alert('Image export is still loading. Please try again in a moment.');
        return;
    }

    const btn = trigger instanceof HTMLElement ? trigger : null;
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
    }

    let captureRoot = null;

    try {
        captureRoot = buildReportCaptureNode();
        document.body.appendChild(captureRoot);

        const captureInner = captureRoot.querySelector('.report-image-capture-inner') || captureRoot;
        const canvas = await html2canvas(captureInner, {
            scale: 2,
            useCORS: true,
            allowTaint: true,
            backgroundColor: '#ffffff',
            logging: false,
            scrollX: 0,
            scrollY: 0,
            width: captureInner.scrollWidth,
            height: captureInner.scrollHeight,
            windowWidth: captureInner.scrollWidth,
            windowHeight: captureInner.scrollHeight,
        });

        const link = document.createElement('a');
        link.download = reportImageFilename(btn);
        link.href = canvas.toDataURL('image/png');
        link.click();
    } catch (err) {
        console.error(err);
        window.alert('Could not generate the image. Try Print / PDF instead.');
    } finally {
        if (captureRoot && captureRoot.parentNode) {
            captureRoot.parentNode.removeChild(captureRoot);
        }
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
}

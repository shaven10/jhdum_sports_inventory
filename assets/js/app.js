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
});

function printReport() {
    window.print();
}

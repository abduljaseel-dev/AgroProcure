
'use strict';

document.addEventListener('DOMContentLoaded', function () {
    const billSelect = document.getElementById('sale_id');
    const amountInput = document.getElementById('amount');
    const hint = document.getElementById('outstanding_hint');

    if (!billSelect || !amountInput || !hint) {
        return;
    }

    function updateOutstanding() {
        const option = billSelect.options[billSelect.selectedIndex];
        const outstanding = option ? option.dataset.outstanding : '';

        if (!outstanding) {
            hint.textContent = 'Select a bill to see its outstanding balance.';
            amountInput.removeAttribute('max');
            return;
        }

        hint.textContent = 'Outstanding balance: ₹' +
            Number(outstanding).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });

        amountInput.max = outstanding;
    }

    billSelect.addEventListener('change', updateOutstanding);
    updateOutstanding();
});

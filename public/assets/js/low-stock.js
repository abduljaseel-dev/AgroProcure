'use strict';

document.addEventListener('DOMContentLoaded', function () {
    const printButton = document.getElementById('printReport');

    if (printButton) {
        printButton.addEventListener('click', function () {
            window.print();
        });
    }
});
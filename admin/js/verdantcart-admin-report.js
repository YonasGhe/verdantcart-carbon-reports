(function () {
    "use strict";

    document.addEventListener("click", function (event) {
        var button = event.target.closest("[data-vcarb-print], [data-amatorcarbon-print]");

        if (!button) {
            return;
        }

        event.preventDefault();
        window.print();
    });
})();
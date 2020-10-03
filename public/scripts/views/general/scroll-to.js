(function (window) {
    "use strict";

    window.ls.view.add({
        selector: 'data-general-scroll-to',
        repeat: false,
        controller: function (element, window) {
            let button = window.document.createElement('button');

            button.className = 'scroll-to icon-up-dir';
            button.alt = 'Back To Top';
            button.title = 'Back To Top';

            button.addEventListener('click', function() {
                element.scrollIntoView({behavior: 'smooth'});
                button.blur();
            }, false);

            element.appendChild(button);
        }
    });

})(window);

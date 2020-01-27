(function (window) {
    "use strict";

    window.ls.view.add({
        selector: 'data-general-scroll-direction',
        repeat: false,
        controller: function (element, window) {
            let position = 0;

            let check = function() {
                let direction = window.document.documentElement.scrollTop;

                if (direction > position) {
                    element.classList.remove('scroll-to-top')
                    element.classList.add('scroll-to-bottom')
                }
                else {
                    element.classList.remove('scroll-to-bottom')
                    element.classList.add('scroll-to-top')
                }

                position = direction;

                //let previous = parseInt(element.getAttribute('data-views-current') || 1);
                let current = Math.ceil(direction / window.innerHeight);

                element.setAttribute('data-views-total', Math.ceil(element.scrollHeight / window.innerHeight));
                element.setAttribute('data-views-current', current);

                if (element.scrollHeight <= (direction + element.offsetHeight + 300) && direction > 0) {
                    element.classList.add('scroll-end')
                }
                else {
                    element.classList.remove('scroll-end')
                }
            };

            window.addEventListener('scroll', check, false);
            window.addEventListener('resize', check, false);

            check();
        }
    });

})(window);

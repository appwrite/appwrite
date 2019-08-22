(function (window) {
    "use strict";

    window.ls.container.get('view').add(
        {
            selector: 'data-forms-parent-down',
            controller: function(element) {
                var target = element.dataset['target'] || null;

                target = (target) ? element.closest(target) : element.parentNode;

                element.addEventListener('click', function () {
                    if(target.nextElementSibling) {
                        target.parentNode.insertBefore(target.nextElementSibling, target);
                        element.scrollIntoView({behavior: 'smooth'});
                    }
                });
            }
        }
    );

})(window);
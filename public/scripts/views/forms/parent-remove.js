(function (window) {
    "use strict";

    window.ls.container.get('view').add(
        {
            selector: 'data-forms-parent-remove',
            repeat: false,
            controller: function(element) {
                var target = element.dataset['target'] || null;

                target = (target) ? element.closest(target) : element.parentNode;

                element.addEventListener('click', function () {
                    target.parentNode.removeChild(target);
                    element.scrollIntoView({behavior: 'smooth'});
                });
            }
        }
    );

})(window);
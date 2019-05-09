(function (window) {
    "use strict";

    window.Litespeed.container.get('view').add(
        {
            selector: 'data-forms-remove',
            repeat: false,
            controller: function(element) {
                Array.prototype.slice.call(element.querySelectorAll('[data-remove]')).map(function(obj) { // Add remove button
                    obj.addEventListener('click', function () {
                        element.parentNode.removeChild(element);
                    });
                });
            }
        }
    );

})(window);
(function (window) {
    "use strict";

    window.ls.container.get('view').add(
        {
            selector: 'data-forms-draft',
            repeat: false,
            controller: function(element, expression) {
                var key = expression.parse(element.dataset['formsDraft'] || '');

                if(element.value === '') {
                    element.value = window.localStorage.getItem(key);
                }

                element.addEventListener('input', function () {
                    window.localStorage.setItem(key, element.value);
                });

                element.form.addEventListener('submit', function () {
                    window.localStorage.removeItem(key);
                });
            }
        }
    );

})(window);
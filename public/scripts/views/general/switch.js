(function (window) {
    window.ls.container.get('view').add({
        selector: 'data-switch',
        controller: function(element, router, document) {
            let check = function (c) {
                if(!element.value) {
                    return;
                }

                if(element.value === router.params.project) {
                    return;
                }

                return router.change('/console/home?project=' + element.value);
            };

            element.addEventListener('change', function() {
                check();
            });
        }
    });
})(window);
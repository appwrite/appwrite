(function (window) {
    window.ls.container.get('view').add({
        selector: 'data-switch',
        controller: function(element, router, document) {
            element.addEventListener('change', function () {
                if(!element.value) {
                    return;
                }

                console.log('change route', element.value);

                if(element.value === router.params.project) {
                    return;
                }

                return router.change('/console/home?project=' + element.value);
            });
        }
    });
})(window);
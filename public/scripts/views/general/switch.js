(function (window) {
    window.ls.container.get('view').add({
        selector: 'data-switch',
        controller: function(element, router, document) {
            element.addEventListener('change', function () {
                return router.change('/console/home?project=' + element.value);
            });
        }
    });
})(window);
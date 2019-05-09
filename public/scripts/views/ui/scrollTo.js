(function (window) {
    window.ls.container.get('view').add({
        selector: 'data-ls-ui-scroll-to',
        repeat: false,
        controller: function(element, document, expression) {
            var id = element.dataset['lsUiScrollTo'] || '';

            element.addEventListener('click', function () {
                var anchorId = expression.parse(id) || null;

                if(anchorId) {
                    document.getElementById(anchorId).scrollIntoView({behavior: 'smooth'});
                }
            });
        }
    });
})(window);
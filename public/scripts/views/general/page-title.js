(function (window) {
    window.ls.container.get('view').add(
        {
            'selector': 'data-page-title',
            'repeat': true,
            'controller': function (element, document, expression) {
                document.title = expression.parse(element.getAttribute('data-page-title')) || document.title;
            }
        }
    );

})(window);
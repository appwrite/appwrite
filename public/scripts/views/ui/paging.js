(function (window) {
    window.ls.container.get('view').add({
        selector: 'data-ls-ui-paging',
        repeat: true,
        controller: function(document, element, expression) {
            var sum     = expression.parse(element.dataset['sum']) || 0;
            var offset  = expression.parse(element.dataset['offset']) || 0;
            var limit   = expression.parse(element.dataset['limit']) || 0;

            if(offset === 0 || limit === 0) {
                element.innerHTML = '1 / 1';
                return true;
            }

            var total   = Math.ceil(sum/limit);
            var current = Math.ceil(offset/limit) + 1;

            element.innerHTML = (total > 0) ? current + ' / ' + total : '';
        }
    });
})(window);
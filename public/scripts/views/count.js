(function (window) {
    window.Litespeed.container.get('view').add({
        selector: 'data-ls-count',
        template: false,
        repeat: true,
        controller: function(element) {
            var count = parseInt(element.dataset['lsCount'] || 0);

            element.dataset['lsCount'] = count + 1;

            element.innerHTML = element.dataset['lsCount'];
        }
    });

})(window);
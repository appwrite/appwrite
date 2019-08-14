(function (window) {
    window.ls.container.get('view').add({
        selector: 'data-paging-next',
        controller: function(element, container, expression) {
            let paths   = [];
            let limit   = 3;
            
            let check = function () {
                let offset  = parseInt(expression.parse(element.dataset['offset']) || '0');
                
                paths = paths.concat(expression.getPaths());
                
                let sum = parseInt(expression.parse(element.dataset['sum']) || '0');
                
                paths = paths.concat(expression.getPaths());

                if((offset + limit) >= sum) {
                    element.disabled = true;
                }
                else {
                    element.disabled = false;
                    element.value = offset + limit;
                }
            };
            
            check();

            for(let i = 0; i < paths.length; i++) {
                let path = paths[i].split('.');
                
                while(path.length) {
                    container.bind(element, path.join('.'), check);
                    path.pop();
                }
            }
        }
    });
})(window);
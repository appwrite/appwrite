(function (window) {
    window.Litespeed.container.get('view').add({
        selector: 'data-ls-wait',
        template: false,
        repeat: false,
        controller: function(element, di, view) {
            let debug   = (element.dataset['debug']);
            let event   = element.dataset['lsWait'] || '';
            let status  = di.check(event); // Has event already triggered

            if (debug) { console.log(di.list); }

            if(status) {
                element.$lsSkip = false;
                if (debug) { console.log('No Wait for ' + event); }
                if (debug) { element.style.background = 'green'; }
            }
            else {
                element.$lsSkip = true;

                if (debug) { console.log('Wait for ' + event); }
                if (debug) { element.style.background = 'yellow'; }

                di.listen(event, function () {
                    element.$lsSkip = false;

                    if (debug) { console.log('Wait Over for ' + event); }
                    if (debug) { element.style.background = 'blue'; }

                    view.render(element);
                });
            }
        }
    });

})(window);
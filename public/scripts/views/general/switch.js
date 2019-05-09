(function (window) {
    window.Litespeed.container.get('view').add({
        selector: 'data-switch',
        controller: function(element, state, window, di) {
            let debug = (element.dataset['debug']);

            let init = function () {
                if (debug) { console.log('Changed: saved project for next redirect', element.value); }

                window.localStorage.setItem('settings.project', element.value); // save for next visit

                di.reset();
            };

            let fallback = function () {
                if (debug) { console.log('fallback init', element.value); }

                if(state.getCurrent().view.scope !== 'console' || !state.getCurrent().view.project) {
                    if (debug) { console.log('Skip: not console state', element.value); }
                    return;
                }

                if(state.params['project']) {
                    return;
                }

                let project = window.localStorage.getItem('settings.project');

                if(project) { // check last used
                    di.reset();

                    if (debug) { console.log('last used project', project); }

                    return state.change('/console/home?project=' + project, true);
                }

                di.reset();

                if (debug) { console.log('first project from list', element.options, element.$lsSkip); }

                return state.change('/console/home?project=' + element.options[0].value, true);
            };

            if (debug) { console.log('switch init', element.options); }

            element.addEventListener('change', function () {
                if (debug) { console.log('change init', element.value); }

                fallback();

                if(element.value && element.value !== state.params['project']) {
                    if (debug) { console.log('Changed: selected project from list');}

                    init ();

                    return state.change('/console/home?project=' + element.value);
                }
            });

            fallback();

            init();
        }
    });
})(window);
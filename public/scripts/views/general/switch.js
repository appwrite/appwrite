(function (window) {
    window.ls.container.get('view').add({
        selector: 'data-switch',
        controller: function(element, router, window) {
            let debug = (element.dataset['debug']);

            let project = router.params.project || null;

            if(project) {
                if(debug) { console.log('project-load-start-init'); }
                document.dispatchEvent(new CustomEvent('project-load'));
            }

            document.addEventListener('state-changed', function () {
               if(router.params.project && project !== router.params.project) {
                   if(debug) { console.log('project-load-init'); }
                   document.dispatchEvent(new CustomEvent('project-load'));
                   project = router.params.project;
               }
            });

            element.addEventListener('change', function () {
                if (debug) { console.log('change init', element.value); }

                if(element.value && element.value !== project) {
                    if (debug) { console.log('Changed: selected project from list');}

                    return router.change('/console/home?project=' + element.value);
                }
            });
        }
    });
})(window);
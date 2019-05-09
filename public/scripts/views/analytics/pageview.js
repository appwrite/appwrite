(function (window) {
    "use strict";

    window.Litespeed.container.get('view').add(
        {
            'selector': 'data-analytics-pageview',
            'controller': function (window, state) {
                if(!ga) {
                    console.error('Google Analytics ga object is not available');
                }

                var company = state.params['company'] || null;

                if(!company) {
                    //return;
                }

                ga('set', 'page', window.location.pathname);

                ga('set', 'dimension1', company);
                //ga('set', 'dimension2', '');

                ga('send', 'pageview');
            }
        }
    );

})(window);
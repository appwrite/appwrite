(function (window) {
    "use strict";

    window.ls.container.get('view').add(
        {
            'selector': 'data-analytics-event',
            'controller': function (element) {
                var action  = element.getAttribute('data-event-action') || 'click';

                element.addEventListener(action, function () {
                    var category    = element.getAttribute('data-event-category') || 'undefined';
                    var label       = element.getAttribute('data-event-label') || 'undefined';

                    if(!ga) {
                        console.error('Google Analytics ga object is not available');
                    }

                    ga('send', {
                        hitType: 'event',
                        eventCategory: category,
                        eventAction: action,
                        eventLabel: label
                    });
                });
            }
        }
    );

})(window);
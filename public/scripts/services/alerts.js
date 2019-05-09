(function (window) {
    "use strict";

    window.ls.container.set('alerts', function (window) {
        let service = {};

        let counter = 0;

        let event = new CustomEvent('alerted', {
            bubbles: false,
            cancelable: true
        });

        service.list = [];

        service.remove = function (id) {
            let message = this.get(id);

            if (message && message.remove && typeof message.remove === 'function') {
                message.remove();
            }

            this.list = this.list.filter(function( obj ) {
                return obj.id !== parseInt(id);
            });

            window.document.dispatchEvent(event);
        };

        service.get = function(id){
            id = parseInt(id);

            let result = this.list.filter(function(obj) {
                return obj.id === id;
            });

            if(result[0]) {
                return result[0];
            }

            return null;
        };

        service.send = function (message, time) {
            let scope = this;

            message.id = counter++;

            scope.list.unshift(message);

            window.document.dispatchEvent(event);

            if(time > 0) { // When 0 alert is unlimited in time
                window.setTimeout(function(message) { return function () {
                    scope.remove(message.id)
                }}(message), time);
            }

            return message.id;
        };

        return service;
    }, true);

})(window);
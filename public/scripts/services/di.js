(function (window) {
    "use strict";

    window.ls.container.set('di', function () {
        var list = {
            'load': true
        };

        return {
            listen: function (event, callback) {
                if(list[event]) {
                    callback();
                }

                document.addEventListener(event, callback);
            },
            report: function (event) {
                list[event] = true;
            },
            check: function(event) {
                return (list[event]);
            },
            reset: function () {
                list = {'load': true};
            },
            list: list
        };
    }, true);

})(window);
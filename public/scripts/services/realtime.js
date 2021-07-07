(function (window) {
    "use strict";

    window.ls.container.set('realtime', () => {
        return {
            current: null,
            set: function (currentConnections) {
                var scope = this;
                scope.current = currentConnections;
                return scope.current;
            }
        };
    }, true, true);
})(window);
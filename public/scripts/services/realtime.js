(function (window) {
    "use strict";

    window.ls.container.set('realtime', () => {
        return {
            current: null,
            history: null,
            setCurrent: function(currentConnections) {
                var scope = this;
                scope.current = currentConnections;
                return scope.current;
            },
            setHistory: function(history) {
                var scope = this;
                scope.history = history;
                return scope.history;
            }
        };
    }, true, true);
})(window);
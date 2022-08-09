(function (window) {
    "use strict";

    window.ls.container.set('date', function () {
        function format(format, datetime) {
            if (!datetime) {
                return null;
            }

            return new Intl.DateTimeFormat('en-US', {
                timeZone: 'UTC',
                hourCycle: 'h24',
                ...format
            }).format(new Date(datetime));
        }

        return {
            format: format,
        }
    }(), true);

})(window);
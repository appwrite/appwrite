(function (window) {
    "use strict";

    window.ls.container.set('timezone', function () {
        return {
            convert: function (unixTime) {
                var timezoneMinutes = new Date().getTimezoneOffset();
                timezoneMinutes = (timezoneMinutes === 0) ? 0 : -timezoneMinutes;

                // Timezone difference in minutes such as 330 or -360 or 0
                return parseInt(unixTime) + (timezoneMinutes * 60);
            }
        };
    }, true);

})(window);
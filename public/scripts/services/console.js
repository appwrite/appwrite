(function (window) {
    "use strict";

    window.ls.container.set('console', function (window) {
        var sdk = new window.Appwrite.Appwrite();

        sdk
            .setEndpoint(window.location.protocol + '//' + window.location.host + APP_ENV.API)
            .setProject('console')
            .setLocale(APP_ENV.LOCALE)
        ;

        return sdk;
    }, true);

})(window);
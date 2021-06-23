(function (window) {
    "use strict";

    window.ls.container.set('console', function (window) {
        var sdk = new window.Appwrite();

        sdk
            .setEndpoint(APP_ENV.ENDPOINT + APP_ENV.API)
            .setProject('console')
            .setLocale(APP_ENV.LOCALE)
        ;

        return sdk;
    }, true);

})(window);
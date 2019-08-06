(function (window) {
    "use strict";

    window.ls.container.set('console', function (window) {
        var sdk = new window.Appwrite();

        sdk
            .setEndpoint(APP_ENV.API)
            .setEndpoint(APP_ENV.API)
            .setProject(0)
            .setLocale(APP_ENV.LOCALE)
            .setMode('admin')
            ;

        return sdk;
    }, true);

})(window);
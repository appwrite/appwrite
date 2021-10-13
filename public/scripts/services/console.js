(function (window) {
    "use strict";

    window.ls.container.set('console', function (window) {
        var sdk = new window.Appwrite();
        var endpoint = window.location.origin + '/v1';

        sdk
            .setEndpoint(endpoint)
            .setProject('console')
            .setLocale(APP_ENV.LOCALE)
        ;

        return sdk;
    }, true);

})(window);
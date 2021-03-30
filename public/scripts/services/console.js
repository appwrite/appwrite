(function (window) {
    "use strict";

    window.ls.container.set('console', function (window) {
        const { Appwrite } = window.Appwrite;
        const sdk = new Appwrite();

        sdk
            .setEndpoint(window.location.origin + APP_ENV.API)
            .setProject('console')
            .setLocale(APP_ENV.LOCALE)
        ;

        return sdk;
    }, true);

})(window);
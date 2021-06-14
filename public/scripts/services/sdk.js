(function (window) {
    "use strict";

    window.ls.container.set('sdk', function (window, router) {
        var sdk = new window.Appwrite.Appwrite();

        sdk
            .setEndpoint(window.location.protocol + '//' + window.location.host + APP_ENV.API)
            .setProject(router.params.project || '')
            .setLocale(APP_ENV.LOCALE)
            .setMode('admin')
        ;

        return sdk;
    }, false);

})(window);
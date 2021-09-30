(function (window) {
    "use strict";

    window.ls.container.set('sdk', function (window, router) {
        var sdk = new window.Appwrite();
        var endpoint = window.location.origin + '/v1';

        sdk
            .setEndpoint(endpoint)
            .setProject(router.params.project || '')
            .setLocale(APP_ENV.LOCALE)
            .setMode('admin')
        ;

        return sdk;
    }, false);

})(window);
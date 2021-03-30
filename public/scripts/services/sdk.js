(function (window) {
    "use strict";

    window.ls.container.set('sdk', function (window, router) {
        const { Appwrite } = window.Appwrite;
        const sdk = new Appwrite();

        sdk
            .setEndpoint(window.location.origin + APP_ENV.API)
            .setProject(router.params.project || '')
            .setLocale(APP_ENV.LOCALE)
            .setMode('admin')
        ;

        return sdk;
    }, false);

})(window);
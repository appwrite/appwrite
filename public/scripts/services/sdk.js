(function (window) {
    "use strict";

    window.ls.container.set('sdk', function (window, router) {
        var sdk = new window.AppwriteSDK();

        sdk.config.domain = APP_ENV.API;
        sdk.config.project = router.params.project || null;
        sdk.config.locale = APP_ENV.LOCALE;
        sdk.config.mode = 'admin';

        return sdk;
    }, false);

})(window);
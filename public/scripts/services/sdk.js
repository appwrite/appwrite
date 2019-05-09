(function (window) {
    "use strict";

    window.Litespeed.container.set('sdk', function (window, state) {
        var sdk = new window.AppwriteSDK();

        sdk.config.domain = APP_ENV.API;
        sdk.config.project = state.params.project || null;
        sdk.config.locale = APP_ENV.LOCALE;
        sdk.config.mode = 'admin';

        return sdk;
    }, false);

})(window);
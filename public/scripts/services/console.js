(function (window) {
    "use strict";

    window.ls.container.set('console', function (window) {
        var sdk = new window.AppwriteSDK();

        sdk.config.domain = 'https://appwrite.io';
        sdk.config.domain = APP_ENV.API;
        sdk.config.project = 0;
        sdk.config.locale = APP_ENV.LOCALE;

        return sdk;
    }, true);

})(window);
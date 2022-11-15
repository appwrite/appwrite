(function (window) {
    "use strict";

    window.ls.container.set('sdk', function (window, router) {
        var client = new Appwrite.Client();
        var endpoint = window.location.origin + '/v1';

        client
            .setEndpoint(endpoint)
            .setProject(router.params.project || '')
            .setLocale(APP_ENV.LOCALE)
            .setMode('admin')
        ;

        return {
            client: client,
            project: new Appwrite.Project(client),
            account: new Appwrite.Account(client),
            avatars: new Appwrite.Avatars(client),
            databases: new Appwrite.Databases(client),
            functions: new Appwrite.Functions(client),
            health: new Appwrite.Health(client),
            locale: new Appwrite.Locale(client),
            storage: new Appwrite.Storage(client),
            teams: new Appwrite.Teams(client),
            users: new Appwrite.Users(client)
        }
    }, false);

})(window);
(function (window) {
    "use strict";

    window.ls.container.set('console', function (window) {
        var client = new Appwrite.Client();
        var endpoint = window.location.origin + '/v1';

        client
            .setEndpoint(endpoint)
            .setProject('console')
            .setLocale(APP_ENV.LOCALE)
        ;

        return {
            client: client,
            account: new Appwrite.Account(client),
            avatars: new Appwrite.Avatars(client),
            databases: new Appwrite.Databases(client),
            functions: new Appwrite.Functions(client),
            health: new Appwrite.Health(client),
            locale: new Appwrite.Locale(client),
            projects: new Appwrite.Projects(client),
            storage: new Appwrite.Storage(client),
            teams: new Appwrite.Teams(client),
            users: new Appwrite.Users(client)
        }
    }, true);

})(window);
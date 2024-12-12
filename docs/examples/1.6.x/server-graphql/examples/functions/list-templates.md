query {
    functionsListTemplates(
        runtimes: [],
        useCases: [],
        limit: 1,
        offset: 0
    ) {
        total
        templates {
            icon
            id
            name
            tagline
            permissions
            events
            cron
            timeout
            useCases
            runtimes {
                name
                commands
                entrypoint
                providerRootDirectory
            }
            instructions
            vcsProvider
            providerRepositoryId
            providerOwner
            providerVersion
            variables {
                name
                description
                value
                placeholder
                required
                type
            }
            scopes
        }
    }
}

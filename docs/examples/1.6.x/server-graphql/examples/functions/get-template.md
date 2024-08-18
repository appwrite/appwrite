query {
    functionsGetTemplate(
        templateId: "<TEMPLATE_ID>"
    ) {
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

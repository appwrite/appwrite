query {
    functionsGet(
        functionId: "<FUNCTION_ID>"
    ) {
        _id
        _createdAt
        _updatedAt
        execute
        name
        enabled
        live
        logging
        runtime
        deployment
        vars {
            _id
            _createdAt
            _updatedAt
            key
            value
            resourceType
            resourceId
        }
        events
        schedule
        timeout
        entrypoint
        commands
        version
        installationId
        providerRepositoryId
        providerBranch
        providerRootDirectory
        providerSilentMode
    }
}

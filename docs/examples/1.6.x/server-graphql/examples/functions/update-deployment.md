mutation {
    functionsUpdateDeployment(
        functionId: "<FUNCTION_ID>",
        deploymentId: "<DEPLOYMENT_ID>"
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
        scopes
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
        specification
    }
}

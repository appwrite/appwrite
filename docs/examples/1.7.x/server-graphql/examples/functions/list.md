query {
    functionsList(
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        functions {
            _id
            _createdAt
            _updatedAt
            execute
            name
            enabled
            live
            logging
            runtime
            deploymentId
            deploymentCreatedAt
            latestDeploymentId
            latestDeploymentCreatedAt
            latestDeploymentStatus
            scopes
            vars {
                _id
                _createdAt
                _updatedAt
                key
                value
                secret
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
}

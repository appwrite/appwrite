query {
    functionsListDeployments(
        functionId: "[FUNCTION_ID]"
    ) {
        total
        deployments {
            _id
            _createdAt
            _updatedAt
            resourceId
            resourceType
            entrypoint
            size
            buildId
            activate
            status
            buildStdout
            buildStderr
            buildTime
        }
    }
}

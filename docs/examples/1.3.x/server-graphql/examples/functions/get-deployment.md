query {
    functionsGetDeployment(
        functionId: "[FUNCTION_ID]",
        deploymentId: "[DEPLOYMENT_ID]"
    ) {
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

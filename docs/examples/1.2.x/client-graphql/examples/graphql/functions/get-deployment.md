query {
    functionsGetDeployment(
        functionId: "[FUNCTION_ID]",
        deploymentId: "[DEPLOYMENT_ID]"
    ) {
        id
        createdAt
        updatedAt
        resourceId
        resourceType
        entrypoint
        size
        buildId
        activate
        status
        buildStdout
        buildStderr
    }
}
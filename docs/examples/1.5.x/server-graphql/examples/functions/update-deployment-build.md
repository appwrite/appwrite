mutation {
    functionsUpdateDeploymentBuild(
        functionId: "<FUNCTION_ID>",
        deploymentId: "<DEPLOYMENT_ID>"
    ) {
        _id
        deploymentId
        status
        stdout
        stderr
        startTime
        endTime
        duration
        size
    }
}

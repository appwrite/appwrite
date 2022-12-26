mutation {
    functionsUpdateDeployment(
        functionId: "[FUNCTION_ID]",
        deploymentId: "[DEPLOYMENT_ID]"
    ) {
        id
        createdAt
        updatedAt
        execute
        name
        enabled
        runtime
        deployment
        vars
        events
        schedule
        scheduleNext
        schedulePrevious
        timeout
    }
}

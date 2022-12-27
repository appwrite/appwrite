mutation {
    functionsUpdateDeployment(
        functionId: "[FUNCTION_ID]",
        deploymentId: "[DEPLOYMENT_ID]"
    ) {
        _id
        _createdAt
        _updatedAt
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

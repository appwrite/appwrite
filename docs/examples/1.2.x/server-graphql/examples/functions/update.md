mutation {
    functionsUpdate(
        functionId: "[FUNCTION_ID]",
        name: "[NAME]",
        execute: ["any"]
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

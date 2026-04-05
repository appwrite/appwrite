mutation {
    functionsUpdate(
        functionId: "[FUNCTION_ID]",
        name: "[NAME]"
    ) {
        _id
        _createdAt
        _updatedAt
        execute
        name
        enabled
        runtime
        deployment
        vars {
            _id
            _createdAt
            _updatedAt
            key
            value
            functionId
        }
        events
        schedule
        scheduleNext
        schedulePrevious
        timeout
    }
}

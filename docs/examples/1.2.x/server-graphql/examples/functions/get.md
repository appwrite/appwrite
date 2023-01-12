query {
    functionsGet(
        functionId: "[FUNCTION_ID]"
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

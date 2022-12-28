mutation {
    functionsCreate(
        functionId: "[FUNCTION_ID]",
        name: "[NAME]",
        execute: ["any"],
        runtime: "node-14.5"
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

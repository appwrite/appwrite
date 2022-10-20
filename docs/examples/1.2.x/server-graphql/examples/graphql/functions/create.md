mutation {
    functionsCreate(
        functionId: "[FUNCTION_ID]",
        name: "[NAME]",
        execute: ["any"],
        runtime: "node-14.5"
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
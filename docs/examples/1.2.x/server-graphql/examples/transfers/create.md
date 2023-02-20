mutation {
    transfersCreate(
        source: "[SOURCE]",
        destination: "[DESTINATION]",
        resources: []
    ) {
        _id
        _createdAt
        _updatedAt
        status
        stage
        source
        destination
        resources
        progress
        latestUpdate
        errorData
    }
}

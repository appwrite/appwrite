mutation {
    transfersCreateAppwriteSource(
        projectId: "[PROJECT_ID]",
        endpoint: "https://example.com",
        key: "[KEY]"
    ) {
        _id
        _createdAt
        _updatedAt
        type
        name
    }
}

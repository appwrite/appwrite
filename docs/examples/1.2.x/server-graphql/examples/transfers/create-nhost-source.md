mutation {
    transfersCreateNhostSource(
        host: "[HOST]",
        password: "[PASSWORD]"
    ) {
        _id
        _createdAt
        _updatedAt
        type
        name
    }
}

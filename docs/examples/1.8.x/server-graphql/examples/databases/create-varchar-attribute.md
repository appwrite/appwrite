```graphql
mutation {
    databasesCreateVarcharAttribute(
        databaseId: "<DATABASE_ID>",
        collectionId: "<COLLECTION_ID>",
        key: "",
        size: 1,
        required: false,
        default: "<DEFAULT>",
        array: false
    ) {
        key
        type
        status
        error
        required
        array
        _createdAt
        _updatedAt
        size
        default
    }
}
```

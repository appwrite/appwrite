query {
    transfersList {
        total
        transfers {
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
}

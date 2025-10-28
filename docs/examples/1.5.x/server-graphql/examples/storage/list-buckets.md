query {
    storageListBuckets(
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        buckets {
            _id
            _createdAt
            _updatedAt
            _permissions
            fileSecurity
            name
            enabled
            maximumFileSize
            allowedFileExtensions
            compression
            encryption
            antivirus
        }
    }
}

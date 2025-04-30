query {
    sitesListDeployments(
        siteId: "<SITE_ID>",
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        deployments {
            _id
            _createdAt
            _updatedAt
            type
            resourceId
            resourceType
            entrypoint
            sourceSize
            buildSize
            totalSize
            buildId
            activate
            screenshotLight
            screenshotDark
            status
            buildLogs
            buildDuration
            providerRepositoryName
            providerRepositoryOwner
            providerRepositoryUrl
            providerBranch
            providerCommitHash
            providerCommitAuthorUrl
            providerCommitAuthor
            providerCommitMessage
            providerCommitUrl
            providerBranchUrl
        }
    }
}

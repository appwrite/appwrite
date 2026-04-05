query {
    functionsListDeployments(
        functionId: "[FUNCTION_ID]"
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
            size
            buildId
            activate
            status
            buildLogs
            buildTime
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

mutation {
    functionsCreateDuplicateDeployment(
        functionId: "<FUNCTION_ID>",
        deploymentId: "<DEPLOYMENT_ID>",
        buildId: "<BUILD_ID>"
    ) {
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
        providerCommitHash
        providerCommitAuthorUrl
        providerCommitAuthor
        providerCommitMessage
        providerCommitUrl
        providerBranch
        providerBranchUrl
    }
}

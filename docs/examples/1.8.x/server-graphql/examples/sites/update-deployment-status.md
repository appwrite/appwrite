mutation {
    sitesUpdateDeploymentStatus(
        siteId: "<SITE_ID>",
        deploymentId: "<DEPLOYMENT_ID>"
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
        providerBranch
        providerCommitHash
        providerCommitAuthorUrl
        providerCommitAuthor
        providerCommitMessage
        providerCommitUrl
        providerBranchUrl
    }
}

mutation {
    sitesCreateTemplateDeployment(
        siteId: "<SITE_ID>",
        repository: "<REPOSITORY>",
        owner: "<OWNER>",
        rootDirectory: "<ROOT_DIRECTORY>",
        type: "branch",
        reference: "<REFERENCE>",
        activate: false
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

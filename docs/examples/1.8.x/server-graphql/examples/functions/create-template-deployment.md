mutation {
    functionsCreateTemplateDeployment(
        functionId: "<FUNCTION_ID>",
        repository: "<REPOSITORY>",
        owner: "<OWNER>",
        rootDirectory: "<ROOT_DIRECTORY>",
        version: "<VERSION>",
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
        providerBranch
        providerCommitHash
        providerCommitAuthorUrl
        providerCommitAuthor
        providerCommitMessage
        providerCommitUrl
        providerBranchUrl
    }
}

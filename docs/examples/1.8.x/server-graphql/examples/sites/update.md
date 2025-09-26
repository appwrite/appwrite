mutation {
    sitesUpdate(
        siteId: "<SITE_ID>",
        name: "<NAME>",
        framework: "analog",
        enabled: false,
        logging: false,
        timeout: 1,
        installCommand: "<INSTALL_COMMAND>",
        buildCommand: "<BUILD_COMMAND>",
        outputDirectory: "<OUTPUT_DIRECTORY>",
        buildRuntime: "node-14.5",
        adapter: "static",
        fallbackFile: "<FALLBACK_FILE>",
        installationId: "<INSTALLATION_ID>",
        providerRepositoryId: "<PROVIDER_REPOSITORY_ID>",
        providerBranch: "<PROVIDER_BRANCH>",
        providerSilentMode: false,
        providerRootDirectory: "<PROVIDER_ROOT_DIRECTORY>",
        specification: ""
    ) {
        _id
        _createdAt
        _updatedAt
        name
        enabled
        live
        logging
        framework
        deploymentId
        deploymentCreatedAt
        deploymentScreenshotLight
        deploymentScreenshotDark
        latestDeploymentId
        latestDeploymentCreatedAt
        latestDeploymentStatus
        vars {
            _id
            _createdAt
            _updatedAt
            key
            value
            secret
            resourceType
            resourceId
        }
        timeout
        installCommand
        buildCommand
        outputDirectory
        installationId
        providerRepositoryId
        providerBranch
        providerRootDirectory
        providerSilentMode
        specification
        buildRuntime
        adapter
        fallbackFile
    }
}

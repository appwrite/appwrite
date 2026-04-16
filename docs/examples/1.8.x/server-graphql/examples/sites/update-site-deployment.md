mutation {
    sitesUpdateSiteDeployment(
        siteId: "<SITE_ID>",
        deploymentId: "<DEPLOYMENT_ID>"
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

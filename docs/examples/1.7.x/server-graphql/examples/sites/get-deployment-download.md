query {
    sitesGetDeploymentDownload(
        siteId: "<SITE_ID>",
        deploymentId: "<DEPLOYMENT_ID>",
        type: "source"
    ) {
        status
    }
}

mutation {
    functionsUpdate(
        functionId: "<FUNCTION_ID>",
        name: "<NAME>",
        runtime: "node-14.5",
        execute: ["any"],
        events: [],
        schedule: "",
        timeout: 1,
        enabled: false,
        logging: false,
        entrypoint: "<ENTRYPOINT>",
        commands: "<COMMANDS>",
        installationId: "<INSTALLATION_ID>",
        providerRepositoryId: "<PROVIDER_REPOSITORY_ID>",
        providerBranch: "<PROVIDER_BRANCH>",
        providerSilentMode: false,
        providerRootDirectory: "<PROVIDER_ROOT_DIRECTORY>"
    ) {
        _id
        _createdAt
        _updatedAt
        execute
        name
        enabled
        live
        logging
        runtime
        deployment
        vars {
            _id
            _createdAt
            _updatedAt
            key
            value
            resourceType
            resourceId
        }
        events
        schedule
        timeout
        entrypoint
        commands
        version
        installationId
        providerRepositoryId
        providerBranch
        providerRootDirectory
        providerSilentMode
    }
}

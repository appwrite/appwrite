mutation {
    functionsCreate(
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
        scopes: [],
        installationId: "<INSTALLATION_ID>",
        providerRepositoryId: "<PROVIDER_REPOSITORY_ID>",
        providerBranch: "<PROVIDER_BRANCH>",
        providerSilentMode: false,
        providerRootDirectory: "<PROVIDER_ROOT_DIRECTORY>",
        templateRepository: "<TEMPLATE_REPOSITORY>",
        templateOwner: "<TEMPLATE_OWNER>",
        templateRootDirectory: "<TEMPLATE_ROOT_DIRECTORY>",
        templateVersion: "<TEMPLATE_VERSION>",
        specification: ""
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
        scopes
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
        specification
    }
}

query {
    sitesListFrameworks {
        total
        frameworks {
            key
            name
            buildRuntime
            runtimes
            adapters {
                key
                installCommand
                buildCommand
                outputDirectory
                fallbackFile
            }
        }
    }
}

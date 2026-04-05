query {
    healthGetFailedJobs(
        name: "v1-database",
        threshold: 0
    ) {
        size
    }
}

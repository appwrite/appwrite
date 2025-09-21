package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/functions"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithKey("<YOUR_API_KEY>")
)

service := functions.New(client)

response, error := service.CreateTemplateDeployment(
    "<FUNCTION_ID>",
    "<REPOSITORY>",
    "<OWNER>",
    "<ROOT_DIRECTORY>",
    "<VERSION>",
    functions.WithCreateTemplateDeploymentActivate(false),
)

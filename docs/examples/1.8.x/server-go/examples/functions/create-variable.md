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

response, error := service.CreateVariable(
    "<FUNCTION_ID>",
    "<KEY>",
    "<VALUE>",
    functions.WithCreateVariableSecret(false),
)

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

response, error := service.UpdateVariable(
    "<FUNCTION_ID>",
    "<VARIABLE_ID>",
    "<KEY>",
    functions.WithUpdateVariableValue("<VALUE>"),
    functions.WithUpdateVariableSecret(false),
)

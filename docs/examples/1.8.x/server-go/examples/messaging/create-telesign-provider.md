package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/messaging"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithKey("<YOUR_API_KEY>")
)

service := messaging.New(client)

response, error := service.CreateTelesignProvider(
    "<PROVIDER_ID>",
    "<NAME>",
    messaging.WithCreateTelesignProviderFrom("+12065550100"),
    messaging.WithCreateTelesignProviderCustomerId("<CUSTOMER_ID>"),
    messaging.WithCreateTelesignProviderApiKey("<API_KEY>"),
    messaging.WithCreateTelesignProviderEnabled(false),
)

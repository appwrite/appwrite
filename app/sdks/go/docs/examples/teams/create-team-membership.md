package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    var client := appwrite.Client{}

    client.SetProject("")
    client.SetKey("")

    var service := appwrite.Teams{
        client: &client
    }

    var response, error := service.CreateTeamMembership("[TEAM_ID]", "email@example.com", [], "https://example.com", "[NAME]")

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
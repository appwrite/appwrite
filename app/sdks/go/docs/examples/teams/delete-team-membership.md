package main

import (
    "fmt"
    "os"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    // Create a Client
    var client := appwrite.Client{}

    // Set Client required headers
    client.SetProject("")
    client.SetKey("")

    // Create a new Teams service passing Client
    var srv := appwrite.Teams{
        client: &client
    }

    // Call DeleteTeamMembership method and handle results
    var res, err := srv.DeleteTeamMembership("[TEAM_ID]", "[INVITE_ID]")
    if err != nil {
        panic(err)
    }

    fmt.Println(res)
}
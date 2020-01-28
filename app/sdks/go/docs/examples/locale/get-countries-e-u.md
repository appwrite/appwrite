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

    // Create a new Locale service passing Client
    var srv := appwrite.Locale{
        client: &client
    }

    // Call GetCountriesEU method and handle results
    var res, err := srv.GetCountriesEU()
    if err != nil {
        panic(err)
    }

    fmt.Println(res)
}
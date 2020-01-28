# Teams Examples

## UpdateTeam

```go
    package appwrite-updateteam

    import (
        "fmt"
        "os"
        "github.com/appwrite/sdk-for-go"
    )

    func main() {
        // Create a Client
        var clt := appwrite.Client{}

        // Set Client required headers
        clt.SetProject("")
        clt.SetKey("")

        // Create a new Teams service passing Client
        var srv := appwrite.Teams{
            client: &clt
        }

        // Call UpdateTeam method and handle results
        var res, err := srv.UpdateTeam("[TEAM_ID]", "[NAME]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
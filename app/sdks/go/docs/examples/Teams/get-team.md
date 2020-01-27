# Teams Examples

## GetTeam

```go
    package appwrite-getteam

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

        // Create a new Teams service passing Client
        var srv := appwrite.Teams{
            client: &clt
        }

        // Call GetTeam method and handle results
        var res, err := srv.GetTeam("[TEAM_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
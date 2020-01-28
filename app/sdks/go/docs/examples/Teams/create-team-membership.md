# Teams Examples

## CreateTeamMembership

```go
    package appwrite-createteammembership

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

        // Call CreateTeamMembership method and handle results
        var res, err := srv.CreateTeamMembership("[TEAM_ID]", "email@example.com", [], "https://example.com")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
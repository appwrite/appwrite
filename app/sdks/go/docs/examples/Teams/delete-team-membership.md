# Teams Examples

## DeleteTeamMembership

```go
    package appwrite-deleteteammembership

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

        // Call DeleteTeamMembership method and handle results
        var res, err := srv.DeleteTeamMembership("[TEAM_ID]", "[INVITE_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
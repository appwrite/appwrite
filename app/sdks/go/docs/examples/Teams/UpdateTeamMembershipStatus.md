# Teams Examples

## UpdateTeamMembershipStatus

```go
    package appwrite-updateteammembershipstatus

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

        // Call UpdateTeamMembershipStatus method and handle results
        var res, err := srv.UpdateTeamMembershipStatus("[TEAM_ID]", "[INVITE_ID]", "[USER_ID]", "[SECRET]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
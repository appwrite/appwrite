# Projects Examples

## UpdateProjectOAuth

```go
    package appwrite-updateprojectoauth

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

        // Create a new Projects service passing Client
        var srv := appwrite.Projects{
            client: &clt
        }

        // Call UpdateProjectOAuth method and handle results
        var res, err := srv.UpdateProjectOAuth("[PROJECT_ID]", "bitbucket")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
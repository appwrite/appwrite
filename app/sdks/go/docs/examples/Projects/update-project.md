# Projects Examples

## UpdateProject

```go
    package appwrite-updateproject

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

        // Call UpdateProject method and handle results
        var res, err := srv.UpdateProject("[PROJECT_ID]", "[NAME]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
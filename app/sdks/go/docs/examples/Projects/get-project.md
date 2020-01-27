# Projects Examples

## GetProject

```go
    package appwrite-getproject

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

        // Create a new Projects service passing Client
        var srv := appwrite.Projects{
            client: &clt
        }

        // Call GetProject method and handle results
        var res, err := srv.GetProject("[PROJECT_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
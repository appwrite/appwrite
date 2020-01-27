# Projects Examples

## ListTasks

```go
    package appwrite-listtasks

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

        // Call ListTasks method and handle results
        var res, err := srv.ListTasks("[PROJECT_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
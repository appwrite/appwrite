# Projects Examples

## CreateTask

```go
    package appwrite-createtask

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

        // Call CreateTask method and handle results
        var res, err := srv.CreateTask("[PROJECT_ID]", "[NAME]", "play", "", 0, "GET", "https://example.com")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
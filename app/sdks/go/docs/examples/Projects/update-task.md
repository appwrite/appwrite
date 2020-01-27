# Projects Examples

## UpdateTask

```go
    package appwrite-updatetask

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

        // Call UpdateTask method and handle results
        var res, err := srv.UpdateTask("[PROJECT_ID]", "[TASK_ID]", "[NAME]", "play", "", 0, "GET", "https://example.com")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
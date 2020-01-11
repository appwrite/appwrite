# Projects Examples

## GetTask

```go
    package appwrite-gettask

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

        // Call GetTask method and handle results
        var res, err := srv.GetTask("[PROJECT_ID]", "[TASK_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
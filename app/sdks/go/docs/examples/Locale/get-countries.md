# Locale Examples

## GetCountries

```go
    package appwrite-getcountries

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

        // Create a new Locale service passing Client
        var srv := appwrite.Locale{
            client: &clt
        }

        // Call GetCountries method and handle results
        var res, err := srv.GetCountries()
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```
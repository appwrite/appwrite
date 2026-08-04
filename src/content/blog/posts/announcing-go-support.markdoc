---
layout: post
title: "Introducing Go, high-performance Functions runtime and SDK"
description: We’re excited to announce that Go runtime is now supported in Appwrite Functions and we have added a Go SDK.
date: 2024-08-22
lastUpdated: 2026-06-29
cover: /images/blog/go-cover.avif
timeToRead: 7
author: matej-baco
category: init, announcement
featured: false
faqs:
  - question: "Which Go version does Appwrite Functions support?"
    answer: "[Appwrite Functions](/docs/products/functions) ships with the `go-1.23` runtime. You can self-host with this runtime by adding it to the `_APP_FUNCTIONS_RUNTIMES` environment variable, or use it directly on Appwrite Cloud. Your `go.mod` file pins the version, which you should not change."
  - question: "Why is Go a good fit for serverless functions?"
    answer: "Go compiles to machine code, so cold starts are fast (Appwrite benchmarks showed up to 3x faster cold starts versus interpreted runtimes) and base execution time is under 1 millisecond. Memory usage is also low, around 5x less than typical interpreted runtimes, which keeps function costs predictable under load."
  - question: "How do I get started writing a Go function on Appwrite?"
    answer: "Install the [Appwrite CLI](/docs/tooling/command-line) and run `appwrite init function`, then pick the Go runtime. The CLI scaffolds a `go.mod` and `main.go` with a `Main(Context openruntimes.Context)` handler. Deploy with `appwrite deploy function` and your function is live."
  - question: "Does Appwrite have a Go SDK?"
    answer: "Yes, Appwrite ships an official Go SDK at `github.com/appwrite/sdk-for-go`. You can use it from inside an [Appwrite Function](/docs/products/functions) or from any external Go service. The SDK exposes the same APIs as other Appwrite SDKs."
  - question: "How do I read JSON from a request in a Go function?"
    answer: "Use `Context.Req.BodyJson(&request)` to decode the request body into a Go struct, where `request` is your typed struct. To send JSON back, use `Context.Res.Json(value)` and optionally `Context.Res.WithStatusCode(code)` for the HTTP status. Types come from the `github.com/open-runtimes/types-for-go` package."
  - question: "Is Go better than Node.js or Python for backend functions?"
    answer: "Go's strengths are raw performance, low memory, and predictable concurrency through goroutines, which makes it a strong choice for CPU-bound or high-throughput workloads. Node.js and Python often win on developer velocity and ecosystem breadth. Pick by use case: Go for performance-sensitive paths, Node or Python for fast iteration and richer libraries."
---

Compiled programming languages are well known for outperforming interpreted ones thanks to a compilation step. Today, Appwrite welcomes a new runtime for writing simple and performant functions, [Go](https://go.dev/).

The new runtime allowed achieving up to 3x faster cold starts and a base execution time of less than 1 millisecond. That means your functions will start running much quicker, and once they're up, they'll fly.

Let’s look at the benefits of Go runtime and SDK, and how they can help you build more performant functions.

# What makes Go special?

Go (or Golang) is a statically typed compiled programming language that balances the ease of use like Python and JavaScript and the performance of compiled languages like Rust. 

It was developed by Google in 2012, and has since grown a large developer community online, with companies like Netflix, Paypal, Riot Games and more moving to Go due to its speed and developer accessibility. There are a few reasons why Go is popular with devs and enterprise companies alike:

- **Easy to learn:** Go uses a clean syntax and avoids complex features found in low-level languages. If you’re familiar with JavaScript and Python, you’ll feel right at home.
- **Strict types:** Go is a statically typed language, meaning you define your data structure up front. This helps prevent runtime errors and makes your code more reliable.
- **Compiled language:** Go compiles into machine code making it fast, a crucial advantage for backend development where speed is essential for optimal user experiences and handling heavy workloads. This also has a substantial effect on minimising cold starts.
- **High performance:** Go’s multi-threaded concurrency model, which uses lightweight Goroutines, allows you to run thousands of multiple simultaneous operations without crashing your system.
- **Dependencies:** Go uses Git to manage packages, making it easy to share and reuse code.
- **Comprehensive tooling:** Go comes with batteries included, featuring a built-in testing framework, a code formatter, a package manager, and other tools to help you write clean and efficient code.

# Introducing Go runtime in Appwrite Functions

As mentioned earlier, with Appwrite 1.6, you can build Appwrite Functions with Go. Let’s take a look at how powerful the runtime is and how you can develop these functions.

## How powerful is the Go runtime?

To understand whether using Go really has any quantifiable benefits for developers building Appwrite Functions, we did a small internal benchmark comparing the Go runtime with various other runtimes on Appwrite Cloud, including Node.js, Dart, PHP, and Python. What we observed was that the Go runtime demonstrated **over** **5 times less memory consumption** than any other runtime. While the larger codebase and compiled nature of Go leads to slower builds, it showed up to **3 times faster cold-start times** compared to interpreted languages.

Steadily, the Go runtime demonstrated the best performance compared to other runtimes.

## Developing the Go functions

To use Go in Appwrite, you need to use the latest version of Appwrite. You can either [sign up on Appwrite Cloud](https://cloud.appwrite.io/) or [self-host](https://appwrite.io/docs/advanced/self-hosting) Appwrite 1.6 with the `go-1.23` runtime added to your [environment](https://appwrite.io/docs/advanced/self-hosting/environment-variables#functions:~:text=preserve%20harddrive%20health.-,_APP_FUNCTIONS_RUNTIMES,-version%20%3E%3D%200.8.0%20This). The runtime will be available on Appwrite Cloud after Init. Next, go ahead and create a Go function using the [Appwrite CLI](https://appwrite.io/docs/tooling/command-line/installation#Installation) by running `appwrite init function`.

Every Go function starts with a `go.mod` file, which contains the name of the module Appwrite’s runtime requires, the Go version (do not change either of these two), and any dependencies your function requires.

```go
module openruntimes/handler

go 1.23.0

require github.com/open-runtimes/types-for-go/v4 v4.0.6

require github.com/appwrite/sdk-for-go v0.1.0
```

Let’s create a simple hello world function in the `main.go` file. Just like the other runtimes, you can use the [function context](https://appwrite.io/docs/products/functions/develop#context-object) to handle the request and response as well as logging. To make the developer experience even better, we have implemented types for all of these via the `github.com/open-runtimes/types-for-go` package.

```go
package handler

import (
	"github.com/open-runtimes/types-for-go/v4/openruntimes"
)

type Response struct {
	Motto       string `json:"motto"`
	Learn       string `json:"learn"`
	Connect     string `json:"connect"`
	GetInspired string `json:"getInspired"`
}

func Main(Context openruntimes.Context) openruntimes.Response {
	if Context.Req.Path == "/hello" && Context.Req.Method == "GET" {
		Context.Log("Hello, world!")
		return Context.Res.Text("Hello, world!")
	}

	return Context.Res.Json(Response{
		Motto:       "Build like a team of hundreds_",
		Learn:       "https://appwrite.io/docs",
		Connect:     "https://appwrite.io/discord",
		GetInspired: "https://builtwith.appwrite.io",
	})
}
```

If you need to get the JSON request body to use in your function, the request property in the function context offers a method `Context.Req.BodyJson()` that decodes the request body to your preferred type. Similarly, the response property offers a method `Context.Res.Json()` that encodes any object to JSON, and allows you to add an HTTP status code and headers of your choice, as seen in the following example.

```go
package handler

import (
	"github.com/open-runtimes/types-for-go/v4/openruntimes"
)

type Request struct {
	FirstNumber  int `json:"firstNumber"`
	SecondNumber int `json:"secondNumber"`
}

type SuccessResponse struct {
	Sum int `json:"sum"`
}

type ErrorResponse struct {
	Error string `json:"error"`
}

func Main(Context openruntimes.Context) openruntimes.Response {
	if Context.Req.Path == "/sum" && Context.Req.Method == "POST" {
		var request Request
		err := Context.Req.BodyJson(&request)
		if err != nil {
			return Context.Res.Json(ErrorResponse{
				Error: err.Error(),
			}, Context.Res.WithStatusCode(400))
		}

		sum := request.FirstNumber + request.SecondNumber

		return Context.Res.Json(SuccessResponse{
			Sum: sum,
		})
	}

	return Context.Res.Json(ErrorResponse{
		Error: "Invalid path or method",
	}, Context.Res.WithStatusCode(404))
}
```

You can also return HTML pages and static files using the `embed` directive in Go. To try this out, you can create a simple HTML webpage `index.html` in the `static` directory of your function.

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hello World</title>
</head>
<body>
    <h1>Hello World</h1>
    <h2>This is a test HTML file</h2>
</body>
</html>
```

You can then update the function logic using the `embed` module, as shown in the following example.

```go
package handler

import (
	"embed"

	"github.com/open-runtimes/types-for-go/v4/openruntimes"
)

//go:embed static/*
var embedReader embed.FS

func Main(Context openruntimes.Context) openruntimes.Response {
	if Context.Req.Method == "GET" {
		testHtml, err := embedReader.ReadFile("static/index.html")
		if err != nil {
			return Context.Res.Text("File not found", Context.Res.WithStatusCode(404))
		}

		return Context.Res.Text(string(testHtml),
			Context.Res.WithHeaders(map[string]string{
				"Content-Type": "text/html",
			}))
	}
	return Context.Res.Text("Bad request", Context.Res.WithStatusCode(400))
}
```

# **Go SDK for easier integration with Appwrite APIs**

We also introduced a new Go SDK that allows developers to consume all of Appwrite's APIs and services in their Go applications.
Most of our APIs have strongly typed responses, as you can see with the Appwrite Storage `ListBuckets` function in the following example. These make the responses easier to iterate through and consume in your apps.

```go
package handler

import (
	"os"
	
	"github.com/open-runtimes/types-for-go/v4/openruntimes"
	"github.com/appwrite/sdk-for-go/appwrite"
)

func Main(Context openruntimes.Context) openruntimes.Response {
	client := appwrite.NewClient(
		appwrite.WithEndpoint(os.Getenv("APPWRITE_FUNCTION_API_ENDPOINT")),
		appwrite.WithProject(os.Getenv("APPWRITE_FUNCTION_PROJECT_ID")),
		appwrite.WithKey(Context.Req.Headers["x-appwrite-key"]),
	)

	storage := appwrite.NewStorage(client)

	response, err := storage.ListBuckets()
	if err != nil {
		Context.Error(err)
		return Context.Res.Text(err.Error(), Context.Res.WithStatusCode(500))
	}

	for _, bucket := range response.Buckets {
		Context.Log("Bucket Name:", bucket.Name)
	}
	
	return Context.Res.Json(response.Buckets)
}
```

Some of our API endpoints such as the ones that return preferences from the Accounts and Teams APIs as well as the ones that return rows from Appwrite Databases are loosely typed because they can be customised by user. For these, the structs offer a `Decode()` method, as seen in the following example.

```go
package handler

import (
	"log"
	"os"

	"github.com/appwrite/sdk-for-go/appwrite"
	"github.com/appwrite/sdk-for-go/models"
	"github.com/open-runtimes/types-for-go/v4/openruntimes"
)

type Profile struct {
	*models.Row
	Name      string `json:"name"`
	Verified  bool   `json:"verified"`
}

type ProfileList struct {
	*models.RowList
	Rows []Profile `json:"rows"`
}

func Main(Context openruntimes.Context) openruntimes.Response {
	client := appwrite.NewClient(
		appwrite.WithEndpoint(os.Getenv("APPWRITE_FUNCTION_API_ENDPOINT")),
		appwrite.WithProject(os.Getenv("APPWRITE_FUNCTION_PROJECT_ID")),
		appwrite.WithKey(Context.Req.Headers["x-appwrite-key"]),
	)

	tablesDB := appwrite.NewTablesDB(client)

	response, err := tablesDB.ListRows("main", "profiles")
	if err != nil {
		Context.Error(err)
		return Context.Res.Text("Internal error" Context.Res.WithStatusCode(500))
	}

	var profiles ProfileList
	err = response.Decode(&profiles)
	if err != nil {
		Context.Error(err)
		return Context.Res.Text("Internal error", Context.Res.WithStatusCode(500))
	}

	for _, profile := range profiles.Rows {
		Context.Log(profile.Id, profile.Name, profile.Verified)
	}
	
	return Context.Res.Json(profiles.Rows)
}
```

# What can you build with Go?

Hopefully, by this point, you’re excited to start building with Go. Here are a few use cases to get you started:

- **Create APIs:** Build RESTful APIs quickly and efficiently.
- **Process data:** Handle complex data transformations and analysis.
- **Image processing:** Resize, crop, or filter images on the fly.
- **File conversion:** Convert files between different formats.
- **Machine learning:** Deploy machine learning models for real-time predictions.

# More resources

Ready to get your hands on the new Go runtime and Go SDK? We’ve created a few resources to help you get your first Go project off the ground and leverage Golang’s speed and performance in your apps. Check them out:

- [Join the Appwrite Community on Discord](https://appwrite.io/discord)
- [More about Init](https://appwrite.io/init)

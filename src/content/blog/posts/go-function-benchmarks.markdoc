---
layout: post
title: Measuring Appwrite's Go runtime performance
description: How we benchmarked the performance of our new Go runtime in comparison with other Appwrite Functions runtimes
date: 2024-10-08
cover: /images/blog/go-function-benchmarks/cover.avif
timeToRead: 8
author: matej-baco
category: product
faqs:
  - question: "Does Appwrite Functions support Go?"
    answer: "Yes. [Appwrite Functions](/docs/products/functions) added a Go runtime, so you can write serverless functions in Go alongside Node.js, Python, PHP, Ruby, Dart, and other supported languages. The runtime is built on Open Runtimes, the same open source project that powers all Appwrite Functions runtimes."
  - question: "How does the Go runtime perform compared to other Appwrite Function runtimes?"
    answer: "In the benchmarks here, Go is among the fastest runtimes for both cold start and concurrent CPU heavy workloads, thanks to compiled binaries and goroutines. Interpreted runtimes (Python, Node.js, PHP) trade some raw performance for ecosystem and rapid iteration. Pick Go for compute heavy or latency sensitive functions."
  - question: "What is Open Runtimes?"
    answer: "[Open Runtimes](https://github.com/open-runtimes/open-runtimes) is an open source project from Appwrite that defines a standard way to run serverless functions across many languages. Appwrite Functions uses Open Runtimes under the hood, but you can use Open Runtimes independently in your own infrastructure."
  - question: "When should I use Go for a function instead of Node.js or Python?"
    answer: "Use Go when cold start time, CPU performance, or memory efficiency matter, such as image processing, parsing large payloads, or running concurrent jobs. Use Node.js or Python when you need quick iteration, rich ecosystems, or are calling APIs where the runtime speed barely matters. Most apps mix both."
  - question: "How were these benchmarks structured?"
    answer: "Three function types were tested: a Hello World endpoint for cold start and minimal overhead, a recursive Fibonacci function for CPU and concurrency, and a Scraper function with multiple dependencies for build time and warm performance. Each function was implemented in several Open Runtimes languages and run under identical conditions to isolate runtime behavior."
  - question: "Can I deploy the benchmark functions to my own Appwrite project?"
    answer: "Yes. The function code shown in this post runs on Appwrite Functions without modification. You can copy the Go handler into a function, deploy it via the [Appwrite CLI](/docs/tooling/command-line) or Git, and run your own benchmarks against your project."
---

It is undeniable that Go has grown to become one of the most popular programming languages among developers worldwide. Recently, during Init, we announced the [new Golang (or Go) runtime](https://appwrite.io/blog/post/announcing-go-support) for Appwrite Functions. However, it is one thing for us to claim that our Go functions runtime is performant, it is a whole other thing for us to justify the same. To do so, we planned a benchmark to test the performance of our Go runtime in comparison with other Appwrite Functions runtimes.

# Setting up the benchmark

To test the raw performance of our Go functions, we created several functions with different programming languages using [Open Runtimes](https://github.com/open-runtimes/open-runtimes), a set of open-source serverless function runtimes developed by Appwrite. We also prepared a benchmarking system for these functions. Here’s how we achieved all of these. 

## Creating our benchmark test functions

We created three different types of functions to benchmark different aspects of the function runtimes’ performance.

### Hello World

The Hello World function is the simplest benchmark for understanding build speed, cold-start speed, and memory consumption in a minimal function. It features a single path and responds with text to any HTTP request sent to it.

```go
package handler

import (
	"github.com/open-runtimes/types-for-go/v4/openruntimes"
)

func Main(Context openruntimes.Context) openruntimes.Response {
    if Context.Req.Path == "/ping" {
        return Context.Res.Text("Pong");
    }

		return Context.Res.Text("Hello");
}
```

### Fibonacci

The Fibonacci benchmark allows us to understand the concurrency model of each runtime for CPU-heavy operations.

```go
package handler

import (
	"sync"

	"github.com/open-runtimes/types-for-go/v4/openruntimes"
)

func fibonacci(n int) int {
	if n <= 1 {
		return n
	}
	
	return fibonacci(n-1) + fibonacci(n-2)
}

func Main(Context openruntimes.Context) openruntimes.Response {
    if Context.Req.Path == "/ping" {
        return Context.Res.Text("Pong");
    }

	var wg sync.WaitGroup
	
	for i := 0; i < 10; i++ {
		wg.Add(1)

		go func() {
			defer wg.Done()
			fibonacci(30)
		}()
	}

	wg.Wait()

	return Context.Res.Text("OK")
}
```

An important note is that certain runtimes may feature worse performance than others, implying that they likely didn't use all CPU cores. This can be achieved with additional code, but the benchmark is designed to focus on the runtime's native approach.

### Scraper

The Scraper benchmark tests the build speed and cold-start speed of a bigger function with multiple libraries.

```go
package handler

import (
    "bytes"

    "github.com/open-runtimes/types-for-go/v4/openruntimes"
    "github.com/go-resty/resty/v2"
    "github.com/go-faker/faker/v4"
    "github.com/PuerkitoBio/goquery"
)

type FakerTags struct {
    Name    string   `faker:"name"`
}

func Main(Context openruntimes.Context) openruntimes.Response {
    if Context.Req.Path == "/ping" {
        return Context.Res.Text("Pong");
    }

    if Context.Req.Path == "/faker" {
        fakeData := FakerTags{}
        err := faker.FakeData(&fakeData)
        if err != nil {
            return Context.Res.Text(err.Error(), Context.Res.WithStatusCode(500))
        }

        return Context.Res.Text("<body><h1 id=\"title\">" + fakeData.Name + "</h1></body>");
    }

    client := resty.New().SetCloseConnection(true)
    resp, err := client.R().Get("http://127.0.0.1:3000/faker")

    if err != nil {
        return Context.Res.Text(err.Error(), Context.Res.WithStatusCode(500))
    }

    reader := bytes.NewReader(resp.Body())
    doc, err := goquery.NewDocumentFromReader(reader)
    if err != nil {
        return Context.Res.Text(err.Error(), Context.Res.WithStatusCode(500))
    }

    data := doc.Find("#title").Text()
		return Context.Res.Text(data);
}
```

Some interpreted languages may underperform in this benchmark, and this could be solved by introducing a library with a build step to minify the code into a single file. However, this benchmark focuses on the runtime’s native approach, so these improvements were not made.

You can find the benchmark functions for all the tested languages in this [GitHub repo](https://github.com/Meldiron/open-runtimes-benchmark/).

## Preparing the benchmarking system

For our benchmarking system, we created a VM on DigitalOcean with the following specs:

- 8 Intel vCPUs
- 16 GB RAM
- 480 GB Disk memory
- Ubuntu 24.04 (LTS) x64 OS.

We then installed Docker to run the functions, `hyperfine` to benchmark the function builds and cold starts, and `k6` to benchmark the function executions (view the [preparation instructions](https://github.com/Meldiron/open-runtimes-benchmark/blob/main/PREPARE.md)).

Our scripts to set up and run the benchmarks are available in our [GitHub repo](https://github.com/Meldiron/open-runtimes-benchmark).

# Running the benchmarks

Through the benchmarks, we explored several aspects of the function runtimes.

## Builds

First, we observed the builds of our function. We reviewed both build sizes and build times, and here is what we discovered: 

### Build size

In the simpler functions with fewer dependencies (Hello World and Fibonacci),  Go had a larger build size than Node, Bun, Deno, .NET, and Dart. For the Scraper function, Go remained competitive and had a smaller build size than Node, Bun, Python, and Ruby.

![Build size](/images/blog/go-function-benchmarks/build-size.avif)

Interestingly, .NET had the smallest build size across all the benchmark functions compared to Go and other runtimes.

### Build time

Overall, Go was a stable choice with moderate performance. It was not the fastest, but it offered consistent build times as compared to runtimes like Python, Dart, and Ruby.

![Build time](/images/blog/go-function-benchmarks/build-time.avif)

We must give special shoutouts to Bun and PHP, which showed exceptionally fast built times as compared to any other runtime. These distinctions were even more visible in the Scraper function, showcasing the efficiency of their package managers. 

## Memory usage

We observed the memory usage of each function before running the benchmarks (i.e., as soon as the functions were set up). Across all three functions, Go consistently used less memory, making it our most suitable function runtime for applications where memory resources are limited, followed by Dart as our next best option.

![Memory usage](/images/blog/go-function-benchmarks/memory-usage.avif)

Our JavaScript runtimes, Python, Ruby, and .NET, have higher base memory usage, which can be a downside for some. However, we noticed that the memory usage of our .NET function did not increase much with the addition of libraries (in the Scraper function), so that might be the best candidate from the aforementioned list.

## Cold-starts

In our benchmark to verify the lowest cold starts, Go emerged as one of the fastest runtimes, particularly when compared to Python, Ruby, and .NET, which showed significantly higher cold-start times. Go's performance was notably better for more complex functions like Scraper, suggesting its suitability for more complex projects with multiple dependencies.

![Cold-starts](/images/blog/go-function-benchmarks/cold-starts.avif)

As observed, PHP was the best choice for very simple, quick-execution tasks, with the best cold-start times in both the Hello World and Fibonacci functions. Among the compiled runtimes, Dart showed similar performance to PHP in the Hello World and Fibonacci functions with better performance in the Scraper function, making it a more suitable alternative to Go.

## Function executions

Next, we tested warmed functions to determine their throughput and the runtimes' ability to handle multitasking. Some runtimes only allow single-threaded operations out of the box, and could show improved performance by installing relevant libraries; however, the benchmarks were intended to demonstrate the runtimes’ native performance.

### Total requests per second

Go consistently showed the highest performance in requests per second across all benchmarks and with higher CPU usage, indicating that it effectively utilizes multiple cores for both I/O-bound and CPU-bound tasks.

![Total requests per second](/images/blog/go-function-benchmarks/total-requests.avif)

Additionally, .NET was the only runtime other than Go that was able to utilize all CPU cores natively, i.e., without the need to use special libraries or frameworks. Python happened to be an outlier with substantially low throughput; however, this could have been caused by the runtime environment and can be optimized in the future.

### Requests per second for a single CPU core

We also compared each runtime's single-core performance. Go showed balanced performance across different scenarios, excelling particularly in tasks involving high computational needs (e.g., the Fibonacci calculation). 

![Requests for the Fibonacci](/images/blog/go-function-benchmarks/requests-per-core-fibonacci.avif)

While it was not always the top performer in every scenario (Bun, PHP, and .NET demonstrated better performance in the Hello World function), Go's consistency across different types of workloads showed its versatility and reliability.

![Requests per second for a single CPU core](/images/blog/go-function-benchmarks/requests-per-core.avif)

The data for all the graphs shared above is available in our [GitHub repo](https://github.com/Meldiron/open-runtimes-benchmark/blob/main/RESULTS_SIMPLIFIED.md).

# Conclusion

These benchmarks consistently showed us why the Go runtime is one of the best runtimes for Appwrite Functions. It is highly performant, multitasks well, and is highly memory efficient. If you liked these benchmarks, you can find our scripts and test functions for the same in our [GitHub repo](https://github.com/Meldiron/open-runtimes-benchmark).

Learn more about Appwrite Functions and Go by reading:

- [Appwrite Functions docs](https://appwrite.io/docs/products/functions)
- [Appwrite Go quick start](https://appwrite.io/docs/quick-starts/go)
- [3 things you can build with the Go runtime](https://appwrite.io/blog/post/3-things-you-can-build-with-go-runtime)
- [Appwrite Discord server](https://appwrite.io/discord)
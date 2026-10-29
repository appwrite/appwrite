---
layout: post
title: Asynchronous vs. Synchronous programming
description: Learn the difference between synchronous and asynchronous programming, how blocking and non-blocking code works, and when developers should use each.
date: 2026-07-16
cover: /images/blog/asynchronous-vs-synchronous-programming/cover.avif
timeToRead: 5
author: aditya-oberai
category: best-practices
featured: false
unlisted: true
faqs:
  - question: Does asynchronous programming use multiple threads?
    answer: Not necessarily. Some runtimes use threads, but environments such as JavaScript commonly use an event loop to coordinate asynchronous operations without running application code on multiple threads simultaneously.
  - question: What is the difference between synchronous and asynchronous APIs?
    answer: A synchronous API call makes the caller wait until the operation finishes and returns a result. An asynchronous API call usually returns immediately while the operation continues in the background.
  - question: Is asynchronous programming faster than synchronous programming?
    answer: Async does not make an individual operation faster. It improves responsiveness and throughput by preventing the program from sitting idle while waiting for network requests, database queries, or file operations.
  - question: When should I use asynchronous programming?
    answer: Use asynchronous programming for I/O-bound work such as API calls, database queries, file access, background jobs, and other operations that spend time waiting on external systems.
  - question: When should I use synchronous programming?
    answer: Use synchronous programming for quick calculations, startup tasks, simple scripts, and operations that must run in a strict order. It is often easier to read, test, and debug.
---

Most performance problems in application code come down to one decision made early and rarely revisited: whether a piece of work runs synchronously or asynchronously. Get it wrong and a single slow network call freezes your entire request, your UI stops responding, and your server sits idle waiting on I/O it could have handled in parallel.

The confusing part is that synchronous and asynchronous code often look almost identical. The difference is not in the syntax, it is in what happens while the work is in progress. This post breaks down synchronous vs asynchronous programming, explains how blocking and non-blocking execution actually work, and gives you a clear rule for when to reach for each.

# What is synchronous programming?

**Synchronous programming runs one task at a time, in order, and each task must finish before the next one starts.** The program blocks on each line until it returns a result, then moves on.

Think of it like a single checkout line at a store. Each customer is served completely before the next one steps forward. If one customer needs a price check that takes two minutes, everyone behind them waits, even if they only have one item.

Here is synchronous code in JavaScript:

```javascript
const data = readFileSync('config.json'); // blocks until the file is read
const parsed = JSON.parse(data);          // only runs after the read finishes
console.log(parsed);                       // only runs after parsing
```

Each line waits for the one before it. This is predictable and easy to reason about, which is exactly why it is the default mental model most developers start with. The cost shows up when one of those lines involves waiting on something slow.

# What is asynchronous programming?

**Asynchronous programming lets a task start and then hand control back to the program while it waits, so other work can run in the meantime.** When the task completes, the program is notified and picks up where it left off.

Back to the store analogy: instead of blocking the line, the customer needing a price check steps aside, the cashier serves everyone else, and the first customer returns once the price is confirmed. Nobody sits idle.

Here is the asynchronous version of the same file read:

```javascript
const data = await readFile('config.json'); // starts the read, frees the thread
const parsed = JSON.parse(data);            // runs when the read resolves
console.log(parsed);
```

The `await` keyword makes this read like sequential code, but under the hood the runtime is free to do other work while the file is being read. That distinction is the whole point.

# Blocking vs non-blocking: the real distinction

People often use "synchronous" and "blocking" interchangeably, but they describe slightly different things.

* **Blocking** means the current thread cannot do anything else until the operation completes. It is stuck.
* **Non-blocking** means the operation returns control immediately, and the result arrives later through a callback, promise, or event.

Synchronous code is almost always blocking. Asynchronous code is designed to be non-blocking. The reason this matters is CPU utilization. A blocked thread waiting on a database query is doing nothing useful, it is just occupying memory and a slot in your thread pool. A non-blocking model lets that same thread serve other requests while the query runs.

This is why a single-threaded runtime like Node.js can handle thousands of concurrent connections. It is not doing many things at once on the CPU, it is refusing to sit still while waiting on I/O.

# Synchronous vs asynchronous programming: key differences

Here is the comparison at a glance.

| Aspect          | Synchronous                     | Asynchronous                                    |
| --------------- | ------------------------------- | ----------------------------------------------- |
| Execution order | Strictly sequential             | Tasks can overlap and complete out of order     |
| Thread behavior | Blocks while waiting            | Frees the thread while waiting                  |
| Complexity      | Simple to read and debug        | Harder to trace, needs care with error handling |
| Best for        | CPU-bound and short tasks       | I/O-bound and long-running tasks                |
| Failure impact  | One slow call stalls everything | A slow call runs in the background              |
| Typical tools   | Plain function calls            | Promises, async/await, callbacks, events        |

The short version: synchronous code trades throughput for simplicity, and asynchronous code trades simplicity for throughput.

# How asynchronous code works under the hood

Asynchronous programming is not magic, and it usually does not mean multithreading. In JavaScript and many other runtimes, it is built on an **event loop**.

When you start an async operation, the runtime offloads it (to the operating system, a thread pool, or a network layer) and registers a callback. Your code keeps running. The event loop continuously checks whether any offloaded work has finished, and when it has, it queues the callback to run as soon as the call stack is clear.

This model is well documented if you want to go deeper. The [MDN guide to the event loop](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Event_loop) explains the concurrency model in the browser, and the [Node.js event loop documentation](https://nodejs.org/en/learn/asynchronous-work/event-loop-timers-and-nexttick) covers how it works on the server.

The practical takeaway: async does not make individual operations faster. A network request takes the same amount of time either way. What async does is stop that request from wasting the time of everything else.

# When to use synchronous programming

Reach for synchronous code when the work is fast, CPU-bound, or when order and simplicity matter more than throughput.

* **CPU-bound computation.** Sorting an array, transforming data in memory, or running a calculation does not wait on external systems, so making it async adds overhead with no benefit.
* **Startup and configuration.** Reading a config file once at boot is fine to do synchronously, since nothing else needs to run yet.
* **Scripts and simple tools.** A one-off migration script or CLI command is easier to write and debug as straight-line synchronous code.
* **Strict ordering.** When step two genuinely cannot begin until step one is fully done, synchronous flow expresses that intent clearly.

The rule of thumb: if the operation does not wait on I/O and finishes quickly, synchronous is the right default.

# When to use asynchronous programming

Reach for asynchronous code whenever a task waits on something outside your process.

* **Network requests.** API calls, database queries, and third-party service calls all involve waiting. Blocking on them wastes your server's capacity.
* **File and disk operations.** Reading, writing, and streaming files are I/O-bound and benefit from non-blocking execution.
* **User interfaces.** In a browser or mobile app, blocking the main thread freezes the UI. Async keeps the interface responsive while data loads.
* **Long-running background jobs.** Sending emails, processing uploads, generating reports, or running batch operations should not make the caller wait.
* **High-concurrency servers.** If you need to serve many simultaneous requests, non-blocking I/O is what lets a small number of threads handle a large number of connections.

If you are building an application backend, most of your meaningful work is I/O-bound, which means asynchronous execution is usually the correct default there.

# Common asynchronous programming mistakes

Async gives you throughput, but it introduces failure modes that synchronous code does not have. Watch for these.

* **Forgetting to await.** An un-awaited promise runs, but your code moves on without its result, leading to race conditions and silent failures.
* **Unhandled rejections.** Errors in async code do not always propagate the way you expect. Wrap awaited calls in try/catch or attach a `.catch()`.
* **Accidental serialization.** Awaiting calls one after another when they could run together wastes the whole benefit. Use `Promise.all` to run independent operations concurrently.
* **Blocking the event loop.** Running heavy CPU work inside an async runtime still blocks everything, because there is only one thread handling the loop. Offload it to a worker or a background function.

# Using async functions with Appwrite SDKs

Appwrite SDK methods are asynchronous because most operations involve making a network request and waiting for a response. In JavaScript and TypeScript, methods for authentication, databases, storage, and other Appwrite services return promises that you can handle using `async` and `await`.

```javascript
const user = await account.get();

const documents = await databases.listDocuments({
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
});
```

This gives developers readable, sequential-looking code without blocking the application while the request is in progress.

When multiple Appwrite requests do not depend on one another, you can run them concurrently with `Promise.all()` instead of waiting for each one separately.

```javascript
const [user, rows] = await Promise.all([
    account.get(),
    tablesDB.listRows({
        databaseId: '<DATABASE_ID>',
        tableId: '<TABLE_ID>',
    }),
]);
```

The same async programming principles apply when using Appwrite: await an operation when the next step depends on its result, and run independent requests concurrently when it is safe to do so.

# Synchronous and asynchronous Appwrite Function executions

[Appwrite Functions](/docs/products/functions) introduce another execution choice: whether Appwrite should wait for the function to finish before returning a response.

A synchronous execution keeps the request open until the function completes and returns its result. This works well for short operations where the caller needs the output immediately.

An asynchronous execution returns an execution ID immediately while the function continues running in the background. This is better suited to work such as batch processing, email delivery, report generation, or upload post-processing.

```javascript
const execution = await functions.createExecution({
    functionId: '<FUNCTION_ID>',
    async: true,
});
```

The SDK method is asynchronous in both cases. The `async` option controls whether Appwrite waits for the Function execution to complete before responding.

# Choosing the right approach with Appwrite

Use `await` when your application needs the result of an Appwrite SDK request before continuing. Run independent SDK requests concurrently when they can safely happen together.

For Appwrite Functions, use synchronous execution when the caller needs the function result immediately. Use asynchronous execution when the work can continue in the background without blocking the request.

To explore these patterns further:

* [Appwrite Functions overview](/docs/products/functions)
* [Execute a function synchronously or asynchronously](/docs/products/functions/execute)
* [Function runtimes](/docs/products/functions/runtimes)
* [A complete guide to Appwrite Functions](/blog/post/appwrite-functions-guide)
* [Serverless functions best practices](/blog/post/serverless-functions-best-practices)
* [Join the Appwrite Discord community](https://appwrite.io/discord)

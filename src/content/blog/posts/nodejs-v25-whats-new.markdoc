---
layout: post
title: "What's new in Node.js v25.2: Web Storage, V8 14.1, permissions and more"
description: Node.js v25.2.1 brings V8 14.1, experimental Web Storage, network permissions with --allow-net, and performance optimizations. Learn about the key features and what they mean for your applications.
date: 2025-11-25
cover: /images/blog/nodejs-v25-whats-new/cover.avif
timeToRead: 9
author: ebenezer-don
category: news
featured: false
faqs:
  - question: "What is new in Node.js v25?"
    answer: "Node.js v25 ships with the V8 14.1 engine, experimental Web Storage API support, a --allow-net flag for network permissions, and ongoing performance improvements. JSON.stringify is more than twice as fast in JetStream2 benchmarks. The release is non LTS, so use it for testing rather than production critical systems."
  - question: "Is Node.js v25 an LTS release?"
    answer: "No, v25 is an odd numbered release and does not receive long term support. It acts as a testing ground for features that may stabilize in future LTS versions like v26. Stick with the latest LTS for production unless you specifically need a v25 feature."
  - question: "What is the --allow-net flag in Node.js?"
    answer: "The --allow-net flag is part of the Node.js permission model and lets you restrict which hosts your application can reach over the network. Combined with --permission, you can require explicit allowlists for network access. It is similar in spirit to Deno's permission system."
  - question: "Does Node.js v25 really have localStorage?"
    answer: "Yes, Node.js v25 exposes the experimental Web Storage API, including localStorage and sessionStorage. This makes it easier to share utility code between browser and server in frameworks like Next.js. The API is still experimental and stricter behavior is planned for v26.0.0."
  - question: "What broke in Node.js v25.2.0?"
    answer: "v25.2.0 started throwing SecurityError when accessing localStorage without a configured storage path, which broke Jest, Vite, and other ecosystem tools. v25.2.1 reverted the change and pushed the stricter behavior to v26.0.0. Upgrade past v25.2.0 to avoid the regression."
  - question: "Does Appwrite Functions support Node.js v25?"
    answer: "Appwrite Functions tracks Node.js runtimes for [Functions](/docs/products/functions). Node.js LTS versions are the typical recommendation for production workloads. Check the [Functions runtime docs](/docs/products/functions) for the current list of supported Node.js versions."
---

Node.js v25.2.1, the current release as of November 17, 2025, represents the latest evolution in the v25 series. This release brings performance improvements, security features, and web standards alignment, though it also demonstrates how the Node.js team handles breaking changes.

If you've been following the Node.js ecosystem, you know that odd-numbered releases like v25 aren't designated for long-term support (LTS). But that doesn't make them any less important. These releases serve as a testing ground for features that will eventually stabilize in future LTS versions.

In this article, we'll break down what's new in the Node.js v25 series, explore the major features introduced, discuss the localStorage controversy that led to v25.2.1, and examine what these changes mean for your applications.

# V8 14.1 engine upgrade {% #v8-14-1-engine-upgrade %}

Node.js v25 ships with **V8 14.1**, Google's JavaScript and WebAssembly engine that powers Chrome. This upgrade brings several performance improvements that directly impact how your code runs.

## Major JSON.stringify improvements

One of the most significant improvements in V8 14.1 is the optimization of `JSON.stringify`. If you've ever worked with large objects or arrays that need to be serialized to JSON, you'll appreciate this.

According to V8's benchmarks, `JSON.stringify` is now **more than 2x faster** on the JetStream2 benchmark. This matters when you're building APIs that return JSON responses, handling data transformations in serverless functions, or working with any system that relies heavily on JSON serialization.

```javascript
const largeObject = {
  users: Array.from({ length: 10000 }, (_, i) => ({
    id: i,
    name: `User ${i}`,
    email: `user${i}@example.com`,
    metadata: { createdAt: new Date(), status: 'active' }
  }))
};

// This operation is now significantly faster in Node.js v25
const jsonString = JSON.stringify(largeObject);
```

## WebAssembly and JIT pipeline optimizations

V8 14.1 also brings ongoing improvements to WebAssembly support and the Just-In-Time (JIT) compilation pipeline. These changes might not be immediately visible in your day-to-day code, but they contribute to better overall runtime performance.

# Native Web Storage API support {% #native-web-storage-api-support %}

One of the challenges when building full-stack JavaScript applications has been the gap between browser and server-side APIs. Node.js v25 narrows this gap by enabling **Web Storage API** by default.

This means you can now use `localStorage` and `sessionStorage` directly in Node.js, just like you would in the browser.

```javascript
// Store data in localStorage
localStorage.setItem('user', JSON.stringify({ name: 'Jane Doe' }));

// Retrieve data
const user = JSON.parse(localStorage.getItem('user'));
console.log(user.name); // Output: Jane Doe

// Clear storage
localStorage.removeItem('user');
```

## Why this matters

For developers working with frameworks like Next.js, Remix, or Astro, this standardization makes it easier to share code between client and server. You can write utilities that work in both environments without needing to check which context you're in or use different APIs.

## The localStorage controversy

Here's where things get interesting. The Web Storage API in Node.js is still marked as **experimental**, and v25.2.0 introduced a change that caused significant problems in the ecosystem.

In v25.2.0, Node.js started throwing errors when accessing `localStorage` without a configured storage path. The intent was to align with web specifications, but this broke popular tools like Jest, Vite, and other frameworks that relied on the previous behavior.

Developers upgrading to v25.2.0 encountered errors like:

```
SecurityError: Cannot initialize local storage without a `--localstorage-file` path
```

This broke test suites and build processes across the ecosystem. The Node.js team received enough feedback that they decided this change was too breaking for a semver-minor release.

**Node.js v25.2.1** (released November 17, 2025) reverted this behavior. The throwing behavior has been postponed to Node.js v26.0.0, giving developers more time to adapt to the change.

This episode highlights an important aspect of non-LTS releases: they're testing grounds for features, and sometimes those features need to be adjusted based on real-world feedback. If you're using Web Storage in Node.js v25, it works, but be prepared for stricter behavior in future versions.

# Network permissions with --allow-net {% #network-permissions-with-allow-net %}

Node.js has traditionally been permissive when it comes to what your code can access. If you run a script, it can read files, make network requests, and interact with the system without restrictions. This flexibility is convenient, but it also poses security risks, especially when running untrusted code.

Node.js v25 expands the **permission model** with a new `--allow-net` flag, giving you finer control over network access.

## How it works

The `--allow-net` flag allows you to explicitly grant network permissions to your application. This is similar to Deno's security model, which requires explicit permissions for different operations.

```bash
# Allow network access to specific hosts (requires --permission flag)
node --permission --allow-net=api.example.com,cdn.example.com server.js

# Allow all network access (requires --permission flag)
node --permission --allow-net server.js
```

This approach encourages **secure-by-default** applications. By requiring explicit permission for network operations, you reduce the risk of unintended behavior or vulnerabilities in dependencies that might make unauthorized network requests.

For production systems, multi-tenant environments, or any scenario where you need to limit what your code can do, this is a valuable addition.

# Built-in Uint8Array base64/hex conversion {% #built-in-uint8array-base64-hex-conversion %}

Working with binary data has always been a bit cumbersome in JavaScript. Converting between `Uint8Array` and formats like base64 or hex usually required either using the `Buffer` API or pulling in a third-party library.

Node.js v25 changes this by adding **built-in methods** to convert `Uint8Array` directly to base64 or hex strings.

```javascript
// Create a Uint8Array
const data = new Uint8Array([72, 101, 108, 108, 111]);

// Convert to base64
const base64 = data.toBase64();
console.log(base64); // Output: SGVsbG8=

// Convert to hex
const hex = data.toHex();
console.log(hex); // Output: 48656c6c6f
```

## Practical use cases

This is useful when working with:

- **File uploads:** Encoding binary data for transmission
- **Cryptographic operations:** Converting hash outputs to readable formats
- **API integrations:** Many APIs expect base64-encoded binary data
- **WebSocket communication:** Encoding binary frames

By making these conversions native, Node.js removes the dependency on external libraries and improves performance since these operations are now handled at the engine level.

# WebAssembly JSPI support {% #webassembly-jspi-support %}

WebAssembly continues to evolve, and Node.js v25 enables **JavaScript Promise Integration (JSPI)** for WebAssembly modules.

JSPI allows synchronous WebAssembly code to interact with asynchronous JavaScript APIs. This is important because WebAssembly is inherently synchronous, but JavaScript's ecosystem is heavily async-based.

## Why this is useful

Before JSPI, if you wanted to call an async JavaScript function from WebAssembly, you had to use complex workarounds involving callbacks or state machines. JSPI simplifies this by letting WebAssembly suspend and resume execution while waiting for async operations.

This is valuable for:

- **High-performance computing:** Running CPU-intensive operations (like scientific simulations or video encoding) in WebAssembly while interacting with async I/O
- **Game engines:** Integrating WebAssembly-based game logic with async network calls for multiplayer features or asset loading
- **Image and video processing:** Using WebAssembly for fast image manipulation (filters, compression, format conversion) while reading/writing files asynchronously
- **Data processing:** Combining WebAssembly's speed for parsing large datasets with JavaScript's async file system or database operations

# Portable compile cache {% #portable-compile-cache %}

Node.js compiles JavaScript code to bytecode before executing it. This compilation step takes time, especially for large applications. To speed up subsequent runs, Node.js caches the compiled bytecode.

Node.js v25 introduces **portable compile cache**, which allows caches to be reused even when you move your project directory. Previously, compile caches used absolute file paths, meaning if you moved your project or deployed it to a different environment, the cache couldn't be reused.

You can enable portable caching in two ways:

```bash
# Enable portable compile cache via environment variable
NODE_COMPILE_CACHE_PORTABLE=1 node app.js
```

Or programmatically:

```javascript
// Enable portable compile cache via API
module.enableCompileCache({
  directory: '/path/to/cache/dir',
  portable: true
});
```

## Impact on serverless and containers

This feature is especially useful for:

- **Serverless functions:** Reuse caches across different function invocations
- **Docker containers:** Share caches between container builds
- **CI/CD pipelines:** Speed up test runs by reusing compilation artifacts

# Type stripping marked as stable {% #type-stripping-marked-as-stable %}

Node.js v25.2 marks **type stripping** as stable. This feature allows you to run TypeScript-like syntax directly in Node.js without needing a separate build step.

Type stripping removes type annotations from your code at runtime, but it doesn't perform type checking. You still need the TypeScript compiler (`tsc`) for that.

```typescript
// This works natively in Node.js v25.2
function greet(name: string): void {
  console.log(`Hello, ${name}!`);
}

greet('World');
```

## When to use it

Type stripping is ideal for:

- **Rapid prototyping:** Write TypeScript without setting up a build pipeline
- **Small scripts:** Run one-off TypeScript files without compilation
- **Learning TypeScript:** Experiment with TypeScript syntax without tooling overhead

For production applications, you'll likely still want a proper TypeScript setup with full type checking, but type stripping removes friction for simpler use cases.

# Performance improvements {% #performance-improvements %}

Beyond the headline features, Node.js v25.2 includes several performance optimizations:

## Buffer concatenation speedup

The implementation of buffer concatenation has been improved using `TypedArray#set`, which reduces the time needed to combine multiple buffers. This is useful when working with streams or assembling data from multiple sources.

## Console logging optimization

Single-string logging in the `console` module has been optimized. If you're doing a lot of logging (especially in development), you'll notice faster output.

## Crypto argument validation

The crypto module now performs argument validation more efficiently in fast paths, reducing overhead for common cryptographic operations.

# What's specific to v25.2.1 {% #whats-specific-to-v25-2-1 %}

While most of the features we've discussed came with v25.0.0 or v25.2.0, v25.2.1 includes a few specific changes worth noting.

## Crypto fix: RSA-PSS saltLength default

v25.2.1 fixed an issue where the documented RSA-PSS `saltLength` default wasn't being used correctly. If you're working with RSA-PSS signatures in the crypto module, this ensures the implementation matches the documented behavior.

```javascript
const crypto = require('crypto');

// The default saltLength now matches documentation
const sign = crypto.createSign('RSA-SHA256');
// ...
```

## V8 engine backport

The release includes a critical V8 engine patch that was backported to improve stability and performance. While the specific details are technical, these backports typically address edge cases or bugs discovered in production environments.

## Documentation clarity on Web Storage

Beyond reverting the throwing behavior, v25.2.1 also clarified the experimental status of Web Storage across documentation, source code, and library code. This makes it clearer to developers that while the feature is available, it's still evolving and may change in future releases.

# Deprecated API removals {% #deprecated-api-removals %}

Node.js v25 continues the tradition of cleaning up deprecated APIs. Several long-deprecated features have been removed:

## SlowBuffer removal

The `SlowBuffer` object has been completely removed due to security vulnerabilities. If you were still using it, you should migrate to `Buffer.allocUnsafeSlow()`.

```javascript
// Old (removed in v25)
const buf = new SlowBuffer(10);

// New
const buf = Buffer.allocUnsafeSlow(10);
```

## Deprecated crypto options

Certain crypto options, like default lengths for `shake128` and `shake256`, are now deprecated at runtime and will be removed in future versions.

## Filesystem constants

Filesystem permission constants (`fs.F_OK`, `fs.R_OK`, `fs.W_OK`, `fs.X_OK`) have been removed. These were already marked as deprecated, and you should use the corresponding constants from the `fs.constants` object instead.

```javascript
// Old (removed in v25)
fs.access(path, fs.R_OK, callback);

// New
fs.access(path, fs.constants.R_OK, callback);
```

# When should you use Node.js v25? {% #when-should-you-use-nodejs-v25 %}

Node.js v25 is a **Current** release, not an LTS release. This means it's designed for developers who want to experiment with the latest features and provide feedback, but it's not recommended for production applications that require long-term stability.

## Use Node.js v25 if:

- You're building side projects or prototypes
- You want to test new features before they land in LTS
- You're contributing to the Node.js ecosystem and need to validate changes
- You're working on applications that can tolerate breaking changes

## Stick with LTS if:

- You're running production applications
- You need long-term support and security updates
- Your team relies on ecosystem stability
- You're working in enterprise environments with strict upgrade policies

The next LTS release is expected in October 2026 with Node.js v26, which will incorporate many of the features tested in v25.


# Conclusion

Node.js v25.2.1 represents a meaningful step forward for the runtime, though it also demonstrates the value of iterative development. The combination of performance improvements, security features, and web standards alignment shows that Node.js continues to evolve in response to developer needs.

While v25 isn't an LTS release, it gives us a preview of what's coming and an opportunity to provide feedback before these features stabilize. Whether you're working with high-performance JSON operations, building cross-platform applications that benefit from Web Storage, or experimenting with WebAssembly, there's something here worth exploring.

If you're interested in learning more about modern JavaScript runtimes, you might enjoy reading about [Deno vs Bun](https://appwrite.io/blog/post/deno-vs-bun-javascript-runtime) or exploring [how Deno 2.0 works with Appwrite Functions](https://appwrite.io/blog/post/deno-2-appwrite-functions).

You can reach out to us on [Discord](https://appwrite.io/discord) if you have any questions or feedback.

# More resources

- [Node.js v25.2.1 release notes](https://nodejs.org/en/blog/release/v25.2.1)
- [Node.js v25.2.0 release notes](https://nodejs.org/en/blog/release/v25.2.0)
- [Why you need to try the new Bun function runtime](/blog/post/why-you-need-to-try-the-new-bun-runtime)
- [Announcing Deno support on Appwrite Cloud](/blog/post/deno-runtime-announcment)

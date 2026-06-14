---
layout: post
title: TypeScript 7.0 will be 10x faster with Go
description: Microsoft is rewriting TypeScript's compiler in Go. Learn what this means and how it will impact your workflow.
date: 2025-03-18
cover: /images/blog/typescript-7-faster-with-go/cover.avif
timeToRead: 5
author: ebenezer-don
category: tutorial
faqs:
  - question: "Is TypeScript itself being rewritten in Go?"
    answer: "No. The TypeScript language, syntax, and type system are unchanged. Only the compiler (tsc) is being ported to Go under the internal name Project Corsa. TypeScript code you write today will still compile the same way."
  - question: "How much faster is the Go based TypeScript compiler?"
    answer: "Early Microsoft benchmarks show roughly a 10x improvement. Compiling the VS Code codebase (about 1.5 million lines) reportedly dropped from around 78 seconds to about 7.5 seconds. Similar speedups were measured for Playwright and TypeORM, but these are early stage numbers and real world results will vary."
  - question: "Why did Microsoft choose Go over Rust or C++?"
    answer: "Microsoft cited Go's structural similarity to the existing TypeScript codebase, which makes porting easier, plus Go's garbage collection (acceptable for a non latency sensitive compiler) and its ergonomics for traversing ASTs and graphs. Rust would have required more rewriting and stricter memory management."
  - question: "When will TypeScript 7 ship?"
    answer: "Microsoft has not committed to a firm date. The roadmap targets a preview release with basic type checking in mid 2025 and a feature complete version later in 2025. TypeScript 7.0 will ship once the Go compiler reaches feature parity."
  - question: "Will my existing build tools and IDE plugins keep working?"
    answer: "Microsoft has committed to running the JavaScript and Go based compilers in parallel during the transition. Most tools that use the public TypeScript APIs should continue to work, but anything that relies on internal compiler internals may need updates."
  - question: "Should I wait for TypeScript 7 to start a new project?"
    answer: "No. TypeScript 6.x will keep receiving updates and remain supported. Start now on the stable compiler, and switch to the Go based one when it stabilizes. The migration is meant to be drop in for typical projects."
---

Over the past decade, TypeScript has earned the trust of developers building large-scale JavaScript applications. It offers the safety net of a type system, reliable tooling, and codebases that are *arguably* easier to maintain. Yet, if you ask developers working with large projects, they'll likely express one common frustration: the TypeScript compiler can feel painfully slow.

To address this challenge, Microsoft recently confirmed a significant shift under the hood. They are rewriting TypeScript's compiler (tsc) in Go. Officially codenamed "Project Corsa," this transition aims to dramatically improve compilation performance. Early benchmarks and public demonstrations from Microsoft suggest compilation speeds could improve roughly tenfold.

Let's break down what this transition means, what's officially confirmed, and how it'll affect you as a developer.

# What does this mean for TypeScript?

Here's what we know so far:

- **Compiler rewrite:** The TypeScript compiler, currently written in TypeScript and running on Node.js, is being rewritten from scratch in Go. Microsoft announced this under the internal name "Project Corsa."
- **Motivation:** The primary motivation is performance. TypeScript compilation times and type-checking responsiveness are known to degrade as projects scale.
- **Language stability:** The rewrite will not affect TypeScript's syntax or language design. It will continue emitting standard JavaScript as before. Developers will still write TypeScript exactly as they currently do.
- **Standalone binary:** The new Go-based compiler will run as a standalone binary, removing the Node.js runtime dependency currently required for compilation.

Microsoft has not announced any changes to TypeScript's language features, syntax, or output because of this rewrite. The transition to Go is an engineering effort to improve performance and maintainability.

# Why Go specifically? Not Rust or C++?

One question naturally arises: why Go specifically? While Microsoft hasn't provided a comprehensive breakdown comparing Go to alternatives like Rust or C++, they've [publicly shared](https://github.com/microsoft/typescript-go/discussions/411) some key factors:

**Structural similarity:** Go's programming style closely resembles the existing TypeScript codebase, making it much easier to port TypeScript's logic while keeping behavior and optimizations intact. This is critical since Microsoft plans to maintain both the JavaScript and Go-based compilers for some time.

**Memory management tradeoffs:** Unlike Rust, which requires manual memory handling, Go provides fine-grained control over memory allocation without forcing constant memory management decisions throughout the codebase. TypeScript's compiler doesn't suffer from latency-sensitive GC pauses, so Go's garbage collection works well for this use case.

**Graph processing & AST traversal:** Go provides an ergonomic way to traverse complex trees and graphs, a key part of TypeScript's type-checking and compilation process.

**Long-term API design considerations:** The TypeScript team wants more control over internal compiler APIs. While Go's interop with JavaScript isn't as strong as some alternatives, moving to a more intentional API design will help modernize the ecosystem.

# Benchmark numbers: early results, still preliminary

Perhaps the most attention-grabbing details involve benchmark numbers shared by Microsoft and industry publications. For instance, compiling the Visual Studio Code codebase (about 1.5 million lines of TypeScript) reportedly went from around 78 seconds with the current compiler down to roughly 7.5 seconds with the new Go-based compiler; a 10x improvement. Other large projects, such as Playwright and TypeORM, reportedly saw similar performance improvements (approximately 10x-13x faster).

These numbers come directly from early demonstrations and internal testing shared by Microsoft. But benchmarks at this early stage often represent idealized or carefully selected scenarios. Real-world improvements for diverse TypeScript projects may vary, and final figures might differ as the implementation matures.

# How will this affect tooling and ecosystem compatibility?

A main concern among developers centers around compatibility with existing tools and integrations. Currently, TypeScript integrates smoothly with bundlers like Webpack, Vite, and esbuild, plus countless IDE plugins and language-server implementations.

Microsoft has publicly committed to maintaining compatibility and minimizing disruption throughout the compiler transition. Still, it's realistic to expect that tooling relying heavily on internal compiler APIs or deeply integrated TypeScript behaviors may require some adjustments. It's been [confirmed](https://github.com/microsoft/typescript-go/discussions/411) that the TypeScript team will initially support both the existing JavaScript-based compiler and the new Go-based compiler concurrently. This dual-compiler support would allow tool authors and ecosystem maintainers ample time to adapt without immediate disruption.

While Microsoft is prioritizing a smooth transition, developers and tool authors should prepare for minor integration adjustments, especially if their workflows rely directly on internal TypeScript compiler APIs.

# Timeline and versioning: what we know

Microsoft has confirmed that **TypeScript 7.0 will ship with the Go-based compiler** once it reaches feature parity with the current JavaScript-based version. Meanwhile, the existing compiler will continue under the **TypeScript 6.x** series, which will receive updates and breaking changes to align with the upcoming transition.

Since some projects may depend on specific APIs or legacy configurations, **Microsoft will maintain TypeScript 6.x alongside TypeScript 7+** until the Go-based compiler reaches full stability and adoption.

The rough timeline is:

- **Mid-2025** – Preview release with basic type-checking.
- **Late 2025** – Feature-complete version for project builds and language services.

For now, both compilers will coexist, allowing developers to upgrade at their own pace.

# What does this mean for you?

This transition changes how TypeScript is compiled but not how developers use it. The language itself remains the same, and the compiler will continue outputting JavaScript as before. The main difference is that builds should be significantly faster, with better performance in large projects.

That said, the new compiler is still in development, and benchmarks are early-stage. While Microsoft is working to maintain compatibility, some tools may need updates, and TypeScript 6.x will remain available for projects that aren't ready to switch immediately.

The long-term goal is for the Go-based compiler to fully replace the current one, but until it reaches full stability, both will be maintained in parallel.

We'll continue monitoring official announcements and detailed benchmarks as Microsoft progresses through this transition. Until then, you can remain cautiously optimistic: the future of TypeScript looks promising, and notably faster.

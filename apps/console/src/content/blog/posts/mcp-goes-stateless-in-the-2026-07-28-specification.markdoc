---
layout: post
title: "What's new in the MCP 2026-07-28 specification"
description: The MCP 2026-07-28 spec drops sessions, adds header based routing, cacheable lists, and MRTR. Here is what changed and what you need to migrate your servers.
date: 2026-07-30
cover: /images/blog/mcp-goes-stateless-in-the-2026-07-28-specification/cover.avif
timeToRead: 5
author: aditya-oberai
category: news
featured: false
faqs:
  - question: What changed in the MCP 2026-07-28 specification?
    answer: The MCP 2026-07-28 specification introduces a stateless protocol core, removes session IDs and the initialize handshake, adds Multi Round-Trip Requests (MRTR), header-based routing, cacheable list responses, stronger OAuth security, an extensions framework, and a formal deprecation policy.
  - question: Why did MCP remove sessions?
    answer: Sessions made remote MCP servers difficult to scale because requests had to reach the same server instance. The new stateless model lets any request be handled by any instance behind a load balancer without sticky sessions or shared session storage.
  - question: Does MCP still support stateful applications?
    answer: Yes. While the protocol is stateless, applications can still maintain state by returning explicit handles from tools and passing them back in later requests instead of relying on transport-level sessions.
  - question: What is Multi Round-Trip Requests (MRTR) in MCP?
    answer: MRTR is a new mechanism that lets servers request additional user input during a tool call without keeping a connection open. Instead of streaming requests, the server returns input_required, and the client retries the request with the required responses.
---
For most of its life, the Model Context Protocol was a stateful, bidirectional protocol. Every connection opened with an `initialize`/`initialized` handshake, and servers carried session state behind an `Mcp-Session-Id` header. That worked for a single process on your laptop. It fell apart the moment you tried to run a remote MCP server across more than one instance.

The [MCP 2026-07-28 specification](https://blog.modelcontextprotocol.io/posts/2026-07-28) fixes that. The headline change is a **stateless protocol core**: MCP moves from a bidirectional stateful protocol to a request/response stateless one. It was one of the most requested changes from developers who wanted MCP servers that scale and behave like any other HTTP workload.

This post breaks down what changed in the MCP 2026-07-28 specification, why each change matters in practice, and what you need to do to migrate your servers.

# What is new in the MCP 2026-07-28 specification?

The MCP 2026-07-28 specification makes the protocol stateless at its core and adds routing, caching, and authorization changes built around that shift. Here is the short version before we go deeper.

| Change | What it does | Why it matters |
| --- | --- | --- |
| Stateless core | Retires the `initialize` handshake and `Mcp-Session-Id` | Any request lands on any instance behind a load balancer |
| Multi Round-Trip Requests (MRTR) | Handles mid-call input without held-open streams | Elicitation and sampling work over a stateless transport |
| Header-based routing | Adds `Mcp-Method` and `Mcp-Name` headers | Gateways route and meter without parsing JSON bodies |
| Cacheable list results | Adds `ttlMs` and `cacheScope` to list responses | Clients stop refetching tool catalogs on every reconnect |
| Authorization hardening | RFC 9207 issuer validation, move to CIMD | Closes an auth-server mix-up hole, aligns with OAuth |
| Extensions framework | Formalizes Tasks, MCP Apps, and EMA | New capabilities ship without bloating the core spec |
| Deprecation policy | Twelve-month minimum removal window | You can plan upgrades instead of reacting to them |

For a refresher on the protocol itself before diving into the changes, see our [complete guide to MCP for developers](/blog/post/what-is-mcp-a-complete-guide-for-developers).

# Why did MCP go stateless?

MCP went stateless because sessions made remote servers hard to scale. Under the old model, a client opened a session with one server instance, and every follow-up request had to reach that same instance because the state lived there. Putting a plain round-robin load balancer in front meant either sticky sessions or shared storage for session state. Both add operational cost and both are exactly the kind of infrastructure workaround developers wanted to avoid.

In the MCP 2026-07-28 specification, every request is self-describing. There is no handshake and no session ID, so any request can land on any instance behind a standard load balancer with no shared storage. That is the difference between running MCP like a special protocol and running it like the rest of the web.

# How MCP's new stateless architecture works

The `initialize`/`initialized` exchange and the `Mcp-Session-Id` header are gone (see SEP-2575 and SEP-2567). Each request now travels on its own, carrying its protocol version, client identity, and client capabilities in `_meta`.

```http
POST /mcp HTTP/1.1
MCP-Protocol-Version: 2026-07-28
Mcp-Method: tools/call
Mcp-Name: search

{"jsonrpc":"2.0","id":1,"method":"tools/call",
 "params":{"name":"search","arguments":{"q":"otters"},
 "_meta":{"io.modelcontextprotocol/clientInfo":{"name":"my-app","version":"1.0"}}}}
```

If a client wants to learn a server's capabilities up front, there is a new optional `server/discover` RPC for that. It is not required, so clients that already know what they need can skip it and go straight to a call.

Dropping the protocol-level session does not force your application to be stateless. If your server needs to carry state across calls, mint an explicit handle from a tool and have the model pass it back as an argument. The maintainers found this works better than session state hidden in the transport, because the model can see the handle and thread it between tools deliberately.

# Multi Round-Trip Requests (MRTR)

Sometimes a tool needs something from the user mid-call, such as a confirmation or a missing parameter. In the old model, that relied on server-initiated requests like `elicitation/create` and `sampling/createMessage`, which required a held-open bidirectional stream. A stateless protocol has no stream to hold open.

**Multi Round-Trip Requests (MRTR)** solve this (SEP-2322). Instead of pushing a request down an open stream, the server returns `resultType: "input_required"` along with the requests it needs answered. The client then retries the original call with the answers attached in `inputResponses`. The interaction is preserved, but nothing has to stay connected between turns.

This is the change that makes mid-call confirmations safe over stateless transports. A tool can ask the user to approve a destructive action, like deleting data or provisioning a paid resource, before it runs.

# What is header-based routing in MCP?

Streamable HTTP requests must now include the `Mcp-Method` and `Mcp-Name` headers (SEP-2243). The method name (`tools/call`) and the tool name (`search`) travel in HTTP headers, not just the JSON body.

That means your gateway, rate limiter, or WAF can route and meter on those headers directly, without parsing the request body. A few things this unlocks:

- Route traffic for a specific tool to a dedicated backend.
- Rate limit by method or tool name at the edge.
- Apply authorization policy before the request ever touches your server logic.

# Cacheable list results

Responses from `tools/list`, `prompts/list`, `resources/list`, and `resources/read` now carry `ttlMs` and `cacheScope` (SEP-2549). Clients can use these hints to cache tool catalogs and decide when a refetch is actually needed.

The practical win is fewer redundant round trips. In the old model, a reconnect often meant re-listing everything. With cache hints and a deterministic ordering, clients can keep tool catalogs cached and keep upstream prompt caches stable across reconnects, which cuts both latency and token usage.

# OAuth and security changes in MCP 2026-07-28

Authorization is where implementers spend most of their integration time, so this release continues to tighten MCP's auth and security posture. The key changes:

| Change | Detail |
| --- | --- |
| Issuer validation (SEP-2468) | Authorization servers return the `iss` parameter per [RFC 9207](https://datatracker.ietf.org/doc/rfc9207/), and clients must validate it before redeeming a code. This closes an authorization-server mix-up hole. |
| `application_type` in DCR (SEP-837) | Clients set `application_type` so authorization servers stop rejecting localhost redirects for desktop and CLI apps. If your CLI OAuth flow ever failed with a `redirect_uri` error, this is likely why. |
| Issuer-bound credentials (SEP-2352) | Client credentials are bound to the issuer that minted them. No reuse across authorization servers. |
| DCR deprecated | Dynamic Client Registration is formally deprecated in favor of Client ID Metadata Documents (CIMD). DCR keeps working for backward compatibility but will be removed in a future version. |

If you are building auth into an MCP server, treat it like any privileged backend: validate issuers, scope credentials tightly, and require approval for sensitive actions.

# New MCP extensions: Tasks, MCP Apps, and EMA

The 2026-07-28 specification formally locks in an extensions framework, so new capabilities can ship without bloating the core spec. **Tasks** moves out of the experimental core and into the `io.modelcontextprotocol/tasks` extension (SEP-2663), with a poll-based `tasks/get` and a new `tasks/update`. Change notifications move from the old HTTP GET endpoint to a single `subscriptions/listen` stream that clients opt into per notification type.

Tasks joins other extensions in the framework, including **MCP Apps** and **Enterprise Managed Authorization (EMA)**. This is how MCP grows now: the stable core stays small, and capabilities like long-running work or enterprise auth live in versioned extensions you adopt when you need them.

# What is deprecated in MCP 2026-07-28?

Roots, Sampling, and Logging are deprecated (SEP-2577), and so is the legacy HTTP+SSE transport. Existing implementations remain compatible: everything deprecated in this release keeps working for at least twelve months under the new formal deprecation policy. That gives teams time to migrate gradually instead of upgrading immediately.

- **Do not adopt** Roots, Sampling, or Logging in new implementations.
- **Migrate off** the legacy HTTP+SSE transport to Streamable HTTP.
- **Plan your migration** during the twelve-month compatibility window before deprecated features are removed.

# Which SDKs support MCP 2026-07-28?

All four Tier 1 SDKs speak `2026-07-28` as of the release, and the Rust SDK supports it in beta.

| SDK | Status |
| --- | --- |
| TypeScript | Stable |
| Python | Stable |
| Go | Stable |
| C# | Stable |
| Rust | Beta |

There is some migration cost, especially if you depended on session identifiers, but the SDKs ship detailed migration notes for the breaking parts. If you are choosing tools to build with, our roundup of the [best MCP servers and clients](/blog/post/10-best-mcp-server-client) is a good starting point.

# How to migrate your MCP server to 2026-07-28

Migrating an MCP server to the 2026-07-28 specification comes down to removing session assumptions and adopting the new request shape. Work through these steps:

1. **Update your SDK** to a version that speaks `2026-07-28` and read its migration notes.
2. **Remove session state from the transport.** If you need cross-call state, return an explicit handle from a tool and accept it back as an argument.
3. **Replace server-initiated requests** to elicitation and sampling with the MRTR `input_required` pattern.
4. **Emit the routing headers** (`Mcp-Method`, `Mcp-Name`) on Streamable HTTP requests.
5. **Add cache hints** (`ttlMs`, `cacheScope`) to your list responses.
6. **Harden authorization**: validate the `iss` parameter and plan your move from DCR to CIMD.

# Building on the new MCP spec with Appwrite

We have updated the [Appwrite MCP server](/docs/tooling/ai/mcp-servers/api) to follow the just-released MCP 2026-07-28 specification, so you can connect AI coding agents to your Appwrite project on a stateless foundation. As of this release, our server supports:

- **Stateless core**, with no handshake or session IDs, so every request stands alone.
- **HTTP-native scaling**, where any instance can serve any request behind a load balancer.
- **Header-based routing**, so gateways can route on `Mcp-Method` and `Mcp-Name` without parsing bodies.
- **Mid-call interaction (MRTR)**, so tools can ask for confirmation or input without holding open streams.
- **Cacheable tool catalogs**, so clients do not refetch on every reconnect.


# Resources

- [Appwrite API MCP server documentation](/docs/tooling/ai/mcp-servers/api)
- [Appwrite Docs MCP server documentation](/docs/tooling/ai/mcp-servers/docs)
- [What is MCP? A complete guide for developers](/blog/post/what-is-mcp-a-complete-guide-for-developers)
- [The official MCP 2026-07-28 release announcement](https://blog.modelcontextprotocol.io/posts/2026-07-28)
- [The MCP specification](https://modelcontextprotocol.io)
- [Join the Appwrite Discord community](https://appwrite.io/discord)
import { Link } from '@tanstack/react-router'
import { ArrowRight } from 'lucide-react'
import { Circuit } from './Circuit'
import { CodeBlock, CopyButton } from './Code'
import { Factory, type Plan } from './Factory'
import type { Index } from '@/lib/docs'

// The first example of the HTTP guide, as it compiles and runs in CI. The home page shows the
// example alone: the runtime that runs it stays under the hood (the guide has the full program).
const EXAMPLE = `use serde_json::json;
use utopia_http::{Bytes, Cookie, Http, Request, Response};

let mut http = Http::new("UTC");
http.get("/session")?.inject("request").inject("response").action(async |scope| {
    let agent = scope.request.header_line_or("user-agent", "unknown");
    scope.response.add_header("Cache-Control", "no-store");
    scope.response.add_header("X-Request-Id", "req_01");
    scope.response.add_cookie(Cookie {
        name: "session".into(),
        value: Some("abc123".into()),
        http_only: Some(true),
        ..Cookie::default()
    });
    scope.response.json(&json!({ "agent": agent }))?;
    Ok(())
});

let request = Request::new("GET", "/session", [("User-Agent", "utopia")], Bytes::new());
let mut response = Response::new();
http.run(&request, &mut response, &http.context()).await?;

assert_eq!(response.status_code(), 200);
assert_eq!(response.header_line("cache-control").as_deref(), Some("no-store"));
assert_eq!(response.cookies()[0].1.name, "session");
assert_eq!(response.wire().body, br#"{"agent":"utopia"}"#);`

/** What the libraries give you, each a claim the site and the repository back up. */
const VALUES = [
  {
    title: 'One problem per crate',
    text: 'Depend on validators without pulling in a web server. Each crate is small and documents its own API.',
  },
  {
    title: 'Async end to end',
    text: 'Servers, database, cache and queue clients are async from the first byte to the last, ready for services under load.',
  },
  {
    title: 'Examples that run',
    text: 'Every guide example is a complete program, compiled and run in CI, so what you copy works.',
  },
  {
    title: 'Proven in production code',
    text: "Appwrite's Rust API is built on these crates: HTTP, authentication, databases, caches and queues.",
  },
]

/** The home page's opening: the claim and how to start, Appwrite drawn as the board the crates build, a real example, and what the libraries give you. */
export function Hero({ index, factory }: { index: Index; factory: Plan | null }) {
  const http = index.libraries.find((l) => l.slug === 'http' && l.rust)
  // The crates live in the Appwrite repository, not on crates.io.
  const install = `cargo add ${http?.rust?.name ?? 'utopia-http'} --git https://github.com/appwrite/appwrite`
  return (
    <section className="grid grid-cols-[minmax(0,1fr)] gap-24 pt-10 pb-4 sm:pt-20">
      {/* The opening: one centred column (claim, pitch, actions, install), and the board on the same axis below. */}
      <div className="relative grid min-w-0 grid-cols-[minmax(0,1fr)] gap-16">
        <Circuit className="pointer-events-none absolute inset-x-0 top-48 h-[340px] w-full" />
        <div className="relative mx-auto grid w-full max-w-[1000px] min-w-0 grid-cols-[minmax(0,1fr)] justify-items-center gap-8 text-center">
          <h1 className="m-0 text-[42px] leading-[1] tracking-[-0.04em] sm:text-[60px] xl:text-[72px]">
            Run at speed of Rust, <br className="hidden sm:block" />
            <span className="text-[1.22em] text-muted-foreground">
              one crate at a time<span className="text-highlight">.</span>
            </span>
          </h1>
          <p className="m-0 max-w-[54ch] text-[17px] leading-relaxed text-ink-soft">
            Small, focused crates for HTTP, validation, authentication, databases, queues and storage. Use one on its own, or combine them into a
            complete API server. Built and maintained by Appwrite, the open-source cloud platform.
          </p>
          <div className="flex min-w-0 flex-wrap items-center justify-center gap-x-6 gap-y-3 pt-3">
            <Link
              to="/libraries"
              className="inline-flex h-11 items-center gap-3 bg-primary px-5 font-mono text-[11.5px] tracking-[0.1em] text-primary-foreground uppercase transition-colors hover:bg-highlight"
            >
              Browse libraries
              <ArrowRight className="size-3.5" />
            </Link>
            {http ? (
              <Link
                to="/$lib"
                params={{ lib: http.slug }}
                className="inline-flex h-11 items-center gap-2 border-b border-foreground font-mono text-[11.5px] tracking-[0.1em] uppercase transition-colors hover:border-highlight hover:text-highlight"
              >
                Start with HTTP
              </Link>
            ) : null}
          </div>
          <div className="-mt-3 flex max-w-full min-w-0 items-center gap-1 border border-code-border bg-code py-1 pr-1 pl-4 font-mono text-[12.5px] text-zinc-200">
            <span className="text-highlight select-none">$</span>
            <span className="min-w-0 truncate px-2 text-left">{install}</span>
            <CopyButton text={install} label="Copy" kind="install" />
          </div>
        </div>
        {factory ? <Factory plan={factory} libraries={index.libraries} /> : null}
      </div>

      <figure className="m-0 grid min-w-0 gap-3">
        <CodeBlock code={EXAMPLE} lang="rust" title="main.rs" />
        <figcaption className="font-mono text-[11px] text-muted-foreground">
          Reads a request header, sets headers and a cookie, and returns JSON. Compiled and run in CI, like every example in the guides.
        </figcaption>
      </figure>

      <ol className="m-0 grid list-none border-t border-foreground p-0 sm:grid-cols-2 lg:grid-cols-4">
        {VALUES.map(({ title, text }, i) => (
          <li key={title} className="grid content-start gap-3 border-b border-border py-6 sm:pr-6 lg:border-b-0 lg:[&:not(:first-child)]:border-l lg:[&:not(:first-child)]:pl-6">
            <span className="font-mono text-[11px] text-highlight tabular-nums">{String(i + 1).padStart(2, '0')}</span>
            <h3 className="title m-0 text-[17px] leading-tight">{title}</h3>
            <p className="m-0 text-[13.5px] leading-relaxed text-muted-foreground">{text}</p>
          </li>
        ))}
      </ol>
    </section>
  )
}

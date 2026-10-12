import { Link } from '@tanstack/react-router'
import { ArrowRight, Boxes, Gauge, Server, SquareTerminal } from 'lucide-react'
import { CodeBlock, CopyButton } from './Code'
import type { Index } from '@/lib/docs'

// The first example of the HTTP guide, as it compiles and runs in CI.
const EXAMPLE = `use utopia_http::{Bytes, Http, Request, Response};

let mut http = Http::new("UTC");
http.get("/hello")?.inject("response").action(|scope| {
    Box::pin(async move {
        scope.response.text("Hello, World!");
        Ok(())
    })
});

let request = Request::new("GET", "/hello", [], Bytes::new());
let mut response = Response::new();
http.run(&request, &mut response, &http.context()).await?;

assert_eq!(response.status_code(), 200);`

const PROGRAM = `use utopia_http::{Bytes, Http, Request, Response};

#[tokio::main(flavor = "current_thread")]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let mut http = Http::new("UTC");
    http.get("/hello")?.inject("response").action(|scope| {
        Box::pin(async move {
            scope.response.text("Hello, World!");
            Ok(())
        })
    });

    let request = Request::new("GET", "/hello", [], Bytes::new());
    let mut response = Response::new();
    http.run(&request, &mut response, &http.context()).await?;

    assert_eq!(response.status_code(), 200);
    Ok(())
}
`

/** What the libraries give you, each a claim the site and the repository back up. */
const VALUES = [
  {
    icon: Boxes,
    title: 'One problem per crate',
    text: 'Depend on validators without pulling in a web server. Each crate is small and documents its own API.',
  },
  {
    icon: Gauge,
    title: 'Built on Tokio',
    text: 'The HTTP framework runs on hyper, and the cache and queue clients are async, ready for servers under load.',
  },
  {
    icon: SquareTerminal,
    title: 'Examples that run',
    text: 'Every guide example is a complete program, compiled and run in CI, so what you copy works.',
  },
  {
    icon: Server,
    title: 'Proven in production code',
    text: "Appwrite's Rust API is built on these crates: HTTP, authentication, databases, caches and queues.",
  },
]

/** The home page's opening: what Utopia is for, a real example, and what the libraries give you. */
export function Hero({ index }: { index: Index }) {
  const http = index.libraries.find((l) => l.slug === 'http' && l.rust)
  // The crates live in the Appwrite repository, not on crates.io.
  const install = `cargo add ${http?.rust?.name ?? 'utopia-http'} --git https://github.com/appwrite/appwrite`
  return (
    <section className="grid gap-10 pt-2">
      <div className="grid grid-cols-[minmax(0,1fr)] items-center gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] lg:gap-12">
        <div className="grid min-w-0 grid-cols-[minmax(0,1fr)] content-start justify-items-start gap-5">
          <h1 className="m-0 text-[34px] leading-[1.1] sm:text-[42px]">Rust libraries for building backend services</h1>
          <p className="m-0 max-w-[54ch] text-[15.5px] leading-relaxed text-muted-foreground">
            Small, focused crates for HTTP, validation, authentication, databases, queues and storage. Use one on its own, or combine them into a
            complete API server.
          </p>
          <div className="flex flex-wrap items-center gap-2">
            <a href="#libraries" className="inline-flex h-9 items-center rounded-md bg-primary px-3.5 text-[13.5px] font-medium text-primary-foreground hover:opacity-90">
              Browse libraries
            </a>
            {http ? (
              <Link to="/$lib" params={{ lib: http.slug }} className="inline-flex h-9 items-center gap-1.5 rounded-md px-3 text-[13.5px] font-medium hover:bg-accent">
                Start with HTTP
                <ArrowRight className="size-3.5" />
              </Link>
            ) : null}
          </div>
          <div className="flex max-w-full items-center gap-1 rounded-md border border-code-border bg-code py-0.5 pr-0.5 pl-3 font-mono text-[12.5px] text-zinc-100">
            <span className="text-zinc-500 select-none">$</span>
            <span className="min-w-0 truncate px-1.5">{install}</span>
            <CopyButton text={install} label="Copy" />
          </div>
        </div>
        <div className="min-w-0">
          <CodeBlock code={EXAMPLE} program={PROGRAM} lang="rust" title="main.rs" />
        </div>
      </div>

      <ul className="m-0 grid list-none gap-x-8 gap-y-6 border-t border-border p-0 pt-8 sm:grid-cols-2 lg:grid-cols-4">
        {VALUES.map(({ icon: Icon, title, text }) => (
          <li key={title} className="grid content-start gap-2">
            <span className="inline-flex size-8 items-center justify-center rounded-md border border-border text-foreground">
              <Icon className="size-4" strokeWidth={1.75} />
            </span>
            <h3 className="m-0 text-[14.5px]">{title}</h3>
            <p className="m-0 text-[13px] leading-relaxed text-muted-foreground">{text}</p>
          </li>
        ))}
      </ul>
    </section>
  )
}

import { Link } from '@tanstack/react-router'
import { ArrowRight, ArrowUpRight, Boxes, Gauge, Server, SquareTerminal } from 'lucide-react'
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

/** Appwrite's mark, as in public/images/logos/appwrite-icon.svg. */
function AppwriteMark({ className = '' }: { className?: string }) {
  return (
    <svg viewBox="0 0 24.5 24" className={className} aria-hidden>
      <path
        fill="#FD366E"
        d="M24.4429 17.4322V22.9096H10.7519C6.76318 22.9096 3.28044 20.7067 1.4171 17.4322C1.14622 16.9561 0.909137 16.4567 0.710264 15.9383C0.319864 14.9225 0.0744552 13.8325 0 12.6952V11.2143C0.0161646 10.9609 0.0416361 10.7094 0.0749451 10.4609C0.143032 9.95105 0.245898 9.45211 0.381093 8.96711C1.66006 4.36909 5.81877 1 10.7519 1C15.6851 1 19.8433 4.36909 21.1223 8.96711H15.2682C14.3072 7.4683 12.6437 6.4774 10.7519 6.4774C8.86017 6.4774 7.19668 7.4683 6.23562 8.96711C5.9427 9.42274 5.71542 9.92516 5.56651 10.4609C5.43425 10.936 5.36371 11.4369 5.36371 11.9548C5.36371 13.5248 6.01324 14.94 7.05463 15.9383C8.01961 16.865 9.32061 17.4322 10.7519 17.4322H24.4429Z"
      />
      <path fill="#FD366E" d="M24.4429 10.4609V15.9383H14.4492C15.4906 14.94 16.1401 13.5248 16.1401 11.9548C16.1401 11.4369 16.0696 10.936 15.9373 10.4609H24.4429Z" />
    </svg>
  )
}

/** The home page's opening: what Utopia is for, a real example, and what the libraries give you. */
export function Hero({ index }: { index: Index }) {
  const http = index.libraries.find((l) => l.slug === 'http' && l.rust)
  // The crates live in the Appwrite repository, not on crates.io.
  const install = `cargo add ${http?.rust?.name ?? 'utopia-http'} --git https://github.com/appwrite/appwrite`
  return (
    <section className="relative grid gap-16 pt-10 pb-6 sm:pt-16">
      {/* The blueprint grid and the accent's glow, behind the opening. */}
      <div className="pointer-events-none absolute -inset-x-5 -top-8 h-[560px] md:-inset-x-10" aria-hidden>
        <div className="grid-backdrop absolute inset-0" />
        <div className="glow absolute inset-0" />
      </div>
      <div className="relative mx-auto grid w-full max-w-[860px] grid-cols-[minmax(0,1fr)] justify-items-center gap-7 text-center">
        <a
          href="https://appwrite.io"
          target="_blank"
          rel="noreferrer"
          className="inline-flex items-center gap-2 rounded-full border border-border bg-background/70 py-1 pr-3 pl-1.5 font-mono text-[11.5px] text-muted-foreground backdrop-blur transition-colors hover:border-highlight-line hover:text-foreground"
        >
          <span className="inline-flex size-5 items-center justify-center rounded-full bg-muted">
            <AppwriteMark className="size-3" />
          </span>
          From the team behind Appwrite
          <ArrowUpRight className="size-3.5" />
        </a>
        <h1 className="m-0 text-[40px] leading-[1.02] tracking-[-0.045em] sm:text-[60px] lg:text-[72px]">
          <span className="text-gradient">Build services,</span>
          <br />
          <span className="bg-gradient-to-r from-highlight via-[#a78bfa] to-[#FF1650] bg-clip-text text-transparent">one crate at a time</span>
        </h1>
        <p className="m-0 max-w-[60ch] text-[16.5px] leading-relaxed text-muted-foreground">
          Small, focused crates for HTTP, validation, authentication, databases, queues and storage. Use one on its own, or combine them into a
          complete API server. Built and maintained by Appwrite, the open-source cloud platform.
        </p>
        <div className="flex flex-wrap items-center justify-center gap-2">
          <a
            href="#libraries"
            className="inline-flex h-10 items-center rounded-lg bg-primary px-4 text-[13.5px] font-medium text-primary-foreground shadow-[0_1px_0_oklch(1_0_0/0.15)_inset,0_8px_24px_-8px_var(--highlight-line)] transition-opacity hover:opacity-90"
          >
            Browse libraries
          </a>
          {http ? (
            <Link
              to="/$lib"
              params={{ lib: http.slug }}
              className="inline-flex h-10 items-center gap-1.5 rounded-lg border border-border bg-background/70 px-4 text-[13.5px] font-medium backdrop-blur transition-colors hover:border-ring hover:bg-accent"
            >
              Start with HTTP
              <ArrowRight className="size-3.5" />
            </Link>
          ) : null}
        </div>
        <div className="flex max-w-full items-center gap-1 rounded-lg border border-code-border bg-code py-1 pr-1 pl-3.5 text-left font-mono text-[12.5px] text-zinc-100 shadow-[0_12px_32px_-16px_oklch(0_0_0/0.5)]">
          <span className="text-highlight select-none">$</span>
          <span className="min-w-0 truncate px-1.5">{install}</span>
          <CopyButton text={install} label="Copy" />
        </div>
        <div className="relative mt-8 w-full min-w-0 text-left">
          {/* A soft halo of the accent under the example. */}
          <div className="pointer-events-none absolute -inset-x-6 -inset-y-4 -z-10 rounded-[28px] bg-highlight-soft blur-2xl" aria-hidden />
          <CodeBlock code={EXAMPLE} program={PROGRAM} lang="rust" title="main.rs" />
        </div>
      </div>

      <ul className="relative m-0 grid list-none gap-px overflow-hidden rounded-xl border border-border bg-border p-0 sm:grid-cols-2 lg:grid-cols-4">
        {VALUES.map(({ icon: Icon, title, text }, i) => (
          <li key={title} className="grid content-start gap-2.5 bg-background p-5">
            <div className="flex items-center justify-between">
              <span className="inline-flex size-8 items-center justify-center rounded-lg border border-highlight-line bg-highlight-soft text-highlight">
                <Icon className="size-4" strokeWidth={1.75} />
              </span>
              <span className="font-mono text-[11px] text-muted-foreground/60 tabular-nums">{String(i + 1).padStart(2, '0')}</span>
            </div>
            <h3 className="m-0 text-[14.5px] tracking-tight">{title}</h3>
            <p className="m-0 text-[13px] leading-relaxed text-muted-foreground">{text}</p>
          </li>
        ))}
      </ul>
    </section>
  )
}

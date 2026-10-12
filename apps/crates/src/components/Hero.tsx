import { Link } from '@tanstack/react-router'
import { CodeBlock, CopyButton } from './Code'
import { useMigration } from '@/lib/prefs'
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

const number = new Intl.NumberFormat('en-US')

/** The home page's opening: what Utopia is, a real example, and the catalog in numbers. */
export function Hero({ index }: { index: Index }) {
  const migration = useMigration()
  const crates = index.libraries.filter((l) => l.rust)
  const http = crates.find((l) => l.slug === 'http')
  const examples = crates.reduce((n, l) => n + l.counts.examples, 0)
  const items = crates.reduce((n, l) => n + l.counts.documented, 0)
  const speedups = crates.flatMap((l) => (l.bench ? [l.bench.speedup] : []))
  // Geometric mean, as each library's own figure is.
  const speedup = speedups.length ? Math.exp(speedups.reduce((n, s) => n + Math.log(s), 0) / speedups.length) : null
  const cases = crates.reduce((n, l) => n + (l.compat?.cases ?? 0), 0)
  const stats: { value: string; label: string; migration?: boolean }[] = [
    { value: number.format(crates.length), label: 'Libraries' },
    { value: number.format(index.categories.length), label: 'Categories' },
    { value: number.format(examples), label: 'Examples' },
    { value: number.format(items), label: 'Documented items' },
    ...(migration && speedup ? [{ value: `${speedup.toFixed(1)}×`, label: 'Faster than PHP', migration: true }] : []),
    ...(migration && cases ? [{ value: number.format(cases), label: 'Cases proven vs PHP', migration: true }] : []),
  ]
  // The crates live in the Appwrite repository, not on crates.io.
  const install = `cargo add ${http?.rust?.name ?? 'utopia-http'} --git https://github.com/appwrite/appwrite`
  return (
    <section className="overflow-hidden rounded-xl border border-border">
      <div className="grid grid-cols-[minmax(0,1fr)] items-center gap-8 px-5 py-8 md:px-8 md:py-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        <div className="grid min-w-0 grid-cols-[minmax(0,1fr)] content-start justify-items-start gap-4">
          <h1 className="m-0 text-[30px] leading-tight sm:text-[34px]">Utopia for Rust</h1>
          <p className="m-0 max-w-[56ch] text-[15px] text-muted-foreground">
            Single-purpose libraries for building services: HTTP, validation, authentication, databases, queues, storage and more. Each library solves
            one problem and documents it with examples that compile and run in CI.
          </p>
          <div className="flex flex-wrap items-center gap-2">
            <a href="#libraries" className="inline-flex h-9 items-center rounded-md bg-primary px-3.5 text-[13.5px] font-medium text-primary-foreground hover:opacity-90">
              Browse libraries
            </a>
            {http ? (
              <Link to="/$lib" params={{ lib: http.slug }} className="inline-flex h-9 items-center rounded-md border border-border px-3.5 text-[13.5px] font-medium hover:bg-accent">
                Read the HTTP guide
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

      <dl className="m-0 grid grid-cols-2 border-t border-border sm:grid-cols-3 lg:auto-cols-fr lg:grid-flow-col lg:grid-cols-none">
        {stats.map((s) => (
          <div key={s.label} className="grid gap-0.5 border-border px-5 py-3.5 not-last:border-r max-lg:border-b md:px-6">
            <dt className={`order-2 text-[12px] ${s.migration ? 'text-migration-ink' : 'text-muted-foreground'}`}>{s.label}</dt>
            <dd className="order-1 m-0 font-display text-[20px] font-medium tracking-tight tabular-nums">{s.value}</dd>
          </div>
        ))}
      </dl>
    </section>
  )
}

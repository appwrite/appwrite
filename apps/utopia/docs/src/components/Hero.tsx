import { Link } from '@tanstack/react-router'
import { ArrowRight } from 'lucide-react'
import { Circuit } from './Circuit'
import { CodeBlock, CopyButton } from './Code'
import { Factory, type Plan } from './Factory'
import { formatCount, progress, type Index } from '@/lib/docs'

// The user-agent guide's first example, compiled and run in CI, beside the PHP Appwrite calls: the
// extension answers that PHP from the same crate.
const RUST = `use utopia_user_agent::UserAgent;

let agent = UserAgent::parse(
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.127 Safari/537.36",
);
let client = agent.client();

assert!(client.is_browser());
assert_eq!(client.name.as_deref(), Some(&b"Chrome"[..]));
assert_eq!(client.version.as_deref(), Some(&b"126.0"[..]));`

const PHP = `use Utopia\\UserAgent\\UserAgent;

$agent = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.127 Safari/537.36');
$client = $agent->client();

var_dump($client->isBrowser()); // bool(true)
echo $client->name, "\\n";      // Chrome
echo $client->version, "\\n";   // 126.0`

/** What the libraries give you, each a claim the site and the repository back up. */
const VALUES = [
  {
    title: 'One problem per crate',
    text: 'Depend on a DSN parser without pulling in an image library. Each crate is small and documents its own API.',
  },
  {
    title: 'Same API as PHP',
    text: 'Each crate keeps the shape of its Utopia PHP library, so code and knowledge move across with it.',
  },
  {
    title: 'Examples that run',
    text: 'Every guide example is a complete program, compiled and run in CI, so what you copy works.',
  },
  {
    title: 'Running in Appwrite',
    text: "Appwrite's PHP extension serves these crates as the PHP classes Appwrite already calls, under its full test suite.",
  },
]

/** The home page's opening: how far Appwrite has moved to Rust, Appwrite drawn as the board the crates build, one API in both languages, and what the libraries give you. */
export function Hero({ index, factory }: { index: Index; factory: Plan | null }) {
  const p = progress(index.libraries)
  const start = index.libraries.find((l) => l.slug === 'user-agent' && l.rust)
  const install = `cargo add ${start?.rust?.name ?? 'utopia-user-agent'}`
  const stats = [
    { value: `${p.rust} / ${p.total}`, label: 'Utopia libraries with a Rust crate' },
    { value: formatCount(index.classes.rust), label: 'PHP classes Appwrite runs from Rust' },
    { value: formatCount(index.size.rust.total), label: 'lines of Rust' },
  ]
  return (
    <section className="grid grid-cols-[minmax(0,1fr)] gap-24 pt-10 pb-4 sm:pt-20">
      {/* The opening: one centred column (claim, pitch, numbers, actions, install), and the board on the same axis below. */}
      <div className="relative grid min-w-0 grid-cols-[minmax(0,1fr)] gap-16">
        <Circuit className="pointer-events-none absolute inset-x-0 top-48 h-[340px] w-full" />
        <div className="relative mx-auto grid w-full max-w-[1000px] min-w-0 grid-cols-[minmax(0,1fr)] justify-items-center gap-8 text-center">
          <h1 className="m-0 text-[42px] leading-[1] tracking-[-0.04em] sm:text-[60px] xl:text-[72px]">
            Appwrite is moving to Rust, <br className="hidden sm:block" />
            <span className="text-[1.22em] text-muted-foreground">
              one crate at a time<span className="text-highlight">.</span>
            </span>
          </h1>
          <p className="m-0 max-w-[56ch] text-[17px] leading-relaxed text-ink-soft">
            Appwrite is built on Utopia, its open-source PHP libraries. Each is being rewritten as a Rust crate, and Appwrite's PHP extension serves the crate in
            place of the PHP it replaces. The crates stand on their own too: use one in any Rust project.
          </p>
          <dl className="m-0 grid w-full max-w-[760px] grid-cols-1 border-y border-foreground sm:grid-cols-3">
            {stats.map((s) => (
              <div key={s.label} className="grid gap-1 py-5 sm:[&:not(:first-child)]:border-l sm:[&:not(:first-child)]:border-border">
                <dt className="order-2 font-mono text-[11px] tracking-[0.06em] text-muted-foreground uppercase">{s.label}</dt>
                <dd className="order-1 m-0 font-display text-[34px] leading-none tabular-nums">{s.value}</dd>
              </div>
            ))}
          </dl>
          <div className="flex min-w-0 flex-wrap items-center justify-center gap-x-6 gap-y-3">
            <Link
              to="/libraries"
              className="inline-flex h-11 items-center gap-3 bg-primary px-5 font-mono text-[11.5px] tracking-[0.1em] text-primary-foreground uppercase transition-colors hover:bg-highlight"
            >
              Browse libraries
              <ArrowRight className="size-3.5" />
            </Link>
            <Link
              to="/map"
              className="inline-flex h-11 items-center gap-2 border-b border-foreground font-mono text-[11.5px] tracking-[0.1em] uppercase transition-colors hover:border-highlight hover:text-highlight"
            >
              See the map
            </Link>
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
        <div className="grid min-w-0 gap-3 lg:grid-cols-2">
          <CodeBlock code={RUST} lang="rust" title="main.rs" />
          <CodeBlock code={PHP} lang="php" title="index.php" />
        </div>
        <figcaption className="font-mono text-[11px] text-muted-foreground">
          One user-agent parser, called from Rust and from PHP. In Appwrite the PHP call runs the Rust crate through the extension.
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

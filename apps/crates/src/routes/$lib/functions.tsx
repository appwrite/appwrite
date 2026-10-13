import { Link, createFileRoute, notFound } from '@tanstack/react-router'
import { Search, X } from 'lucide-react'
import { Fragment, useMemo, useState } from 'react'
import { PageHeader, Section } from '@/components/Page'
import { SizeTable } from '@/components/Size'
import { formatCount, loadLibrary, resolve, summary, type PhpFunction } from '@/lib/docs'

export const Route = createFileRoute('/$lib/functions')({
  loader: async ({ params }) => {
    const lib = await loadLibrary(params.lib)
    if (!lib?.php_functions) throw notFound()
    // What resolving Rust paths to their docs needs.
    const items = lib.items.map((i) => ({ name: i.name, module: i.module, owner: i.owner, kind: i.kind, export: i.export }))
    const modules = lib.modules.map((m) => ({ path: m.path }))
    return { library: lib.library, inventory: lib.php_functions, items, modules }
  },
  head: () => ({ meta: [{ title: 'PHP functions · php-std · Utopia for Rust' }] }),
  component: FunctionsPage,
  notFoundComponent: () => <div className="text-muted-foreground">This library has no PHP function inventory.</div>,
})

type Kind = PhpFunction['kind']

const KINDS: { id: Kind; label: string }[] = [
  { id: 'function', label: 'Functions' },
  { id: 'method', label: 'Methods' },
  { id: 'behaviour', label: 'Engine behaviours' },
]

const chip =
  'inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 text-[12.5px] text-muted-foreground transition-colors aria-pressed:border-foreground aria-pressed:bg-accent aria-pressed:text-foreground'

function Stat({ value, label, note }: { value: string; label: string; note?: string }) {
  return (
    <div className="grid content-start gap-0.5 rounded-lg border border-border bg-card px-4 py-3">
      <span className="font-display text-[22px] leading-tight font-medium tabular-nums">{value}</span>
      <span className="text-[12.5px] text-muted-foreground">{label}</span>
      {note ? <span className="text-[11.5px] text-muted-foreground">{note}</span> : null}
    </div>
  )
}

function FunctionsPage() {
  const { library: l, inventory, items, modules } = Route.useLoaderData()
  const [q, setQ] = useState('')
  const [group, setGroup] = useState('')
  const [kind, setKind] = useState<Kind | ''>('')
  const t = inventory.totals
  const term = q.trim().toLowerCase()
  const shown = useMemo(
    () =>
      inventory.functions.filter(
        (f) =>
          (!group || f.group === group) &&
          (!kind || f.kind === kind) &&
          (!term || [f.name, ...f.rust, ...f.ops, ...f.doc, ...(f.variants ?? []).map((v) => v.name)].join(' ').toLowerCase().includes(term)),
      ),
    [inventory, group, kind, term],
  )
  const rust = l.size?.rust
  const filtered = !!(term || group || kind)
  return (
    <div className="grid min-w-0 gap-9">
      <PageHeader
        eyebrow={
          <Link to="/$lib" params={{ lib: l.slug }} className="hover:text-foreground">
            {l.title}
          </Link>
        }
        title="PHP functions"
      >
        Every PHP function, method and engine behaviour <code className="font-mono text-[14px]">{l.rust?.crate ?? 'php_std'}</code> reimplements, where it lives in Rust, and how it is
        proven against the real PHP.
      </PageHeader>

      <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3">
        <Stat value={formatCount(t.entries)} label="PHP functions and behaviours" note={`${t.functions} functions, ${t.methods} methods, ${t.behaviours} behaviours`} />
        <Stat value={formatCount(t.groups)} label="Groups" />
        <Stat value={formatCount(t.cases)} label="Compat cases" note={`over ${t.operations} operations`} />
        <Stat value={formatCount(t.fuzz.inputs)} label="Fuzz inputs per run" note={`${t.fuzz.profiles} profiles`} />
        {rust ? <Stat value={formatCount(rust.total)} label="Lines of Rust" note={`${formatCount(rust.code)} code, ${formatCount(rust.tests.total)} in tests`} /> : null}
      </div>

      <Section id="why" title="Why php-std exists">
        <div className="grid max-w-[72ch] gap-2 text-[14px] text-muted-foreground">
          <p className="m-0">
            A Utopia crate has to behave exactly like its PHP library, and much of that behaviour comes from the PHP engine, not the library: how{' '}
            <code className="font-mono text-[13px]">"10" == "1e1"</code> compares, how a float prints as a string, which bytes <code className="font-mono text-[13px]">strtolower</code>{' '}
            touches, how <code className="font-mono text-[13px]">mb_strtolower</code> handles a final sigma, what <code className="font-mono text-[13px]">json_encode</code> escapes.
            Rust's own functions differ in these details, and every difference is a response that changes byte for byte.
          </p>
          <p className="m-0">
            So each engine function is reimplemented once, here, and checked against the real one by <code className="font-mono text-[13px]">bin/compat</code>: recorded cases,
            then fuzzing with generated inputs on every run. Crates call these functions instead of approximations of their own. Quirks PHP has are kept on purpose and pinned
            by a case; inputs a Rust type cannot represent are recorded as deviations.
          </p>
        </div>
        {l.size ? (
          <div className="grid max-w-[640px] gap-1.5">
            <h3 className="text-[15px]">Size</h3>
            <SizeTable lib={l} />
          </div>
        ) : null}
      </Section>

      <Section id="inventory" title="Inventory" description="Cases and fuzz inputs are those of the compat operations exercising each entry; an operation exercising several entries counts for each.">
        <div className="z-10 -mx-2 grid gap-2 border-b sm:sticky sm:top-14 border-border bg-background/95 px-2 py-3 backdrop-blur">
          <div className="flex flex-wrap items-center gap-2">
            <label className="flex h-9 min-w-0 flex-[1_1_240px] items-center gap-2 rounded-md border border-border bg-card px-3 text-muted-foreground focus-within:border-ring">
              <Search className="size-4 flex-none" />
              <input
                type="search"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="Search PHP names, Rust paths, operations"
                aria-label="Search functions"
                className="min-w-0 flex-1 bg-transparent text-[13.5px] text-foreground outline-none placeholder:text-muted-foreground"
              />
            </label>
            <select value={group} onChange={(e) => setGroup(e.target.value)} aria-label="Group" className="h-9 rounded-md border border-border bg-card pr-8 pl-2.5 text-[13px] text-foreground">
              <option value="">All groups</option>
              {inventory.groups.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.title} ({g.functions})
                </option>
              ))}
            </select>
            {filtered ? (
              <button
                type="button"
                onClick={() => {
                  setQ('')
                  setGroup('')
                  setKind('')
                }}
                className="inline-flex h-9 items-center gap-1.5 rounded-md px-2.5 text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground"
              >
                <X className="size-3.5" />
                Clear
              </button>
            ) : null}
          </div>
          <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Kind">
            <button type="button" aria-pressed={!kind} onClick={() => setKind('')} className={chip}>
              All <span className="tabular-nums">{t.entries}</span>
            </button>
            {KINDS.map((k) => (
              <button key={k.id} type="button" aria-pressed={kind === k.id} onClick={() => setKind(kind === k.id ? '' : k.id)} className={chip}>
                {k.label} <span className="tabular-nums">{inventory.functions.filter((f) => f.kind === k.id).length}</span>
              </button>
            ))}
            <span className="ml-auto text-[12.5px] text-muted-foreground tabular-nums">
              {shown.length} of {t.entries}
            </span>
          </div>
        </div>
        {shown.length ? null : <div className="rounded-lg border border-dashed border-border px-4 py-8 text-center text-[13px] text-muted-foreground">Nothing matches.</div>}
        {inventory.groups.map((g) => {
          const list = shown.filter((f) => f.group === g.id)
          return list.length ? (
            <div key={g.id} id={`group.${g.id}`} className="grid scroll-mt-36 gap-2 pt-3">
              <div className="grid gap-0.5">
                <h3 className="text-[16px]">{g.title}</h3>
                {g.doc ? <p className="m-0 max-w-[80ch] text-[12.5px] text-muted-foreground">{summary(g.doc).replace(/`/g, '')}</p> : null}
              </div>
              <FunctionTable list={list} slug={l.slug} items={items} modules={modules} />
            </div>
          ) : null
        })}
      </Section>
    </div>
  )
}

type Resolver = { items: Parameters<typeof resolve>[0]['items']; modules: Parameters<typeof resolve>[0]['modules'] }

function RustLink({ path, slug, lib }: { path: string; slug: string; lib: Resolver }) {
  const target = resolve(lib, path)
  const text = <code className="font-mono text-[12px] break-words">{path}</code>
  return target ? (
    <Link to="/$lib/$" params={{ lib: slug, _splat: target.splat }} hash={target.hash} className="text-foreground underline decoration-border underline-offset-2 hover:decoration-foreground">
      {text}
    </Link>
  ) : (
    text
  )
}

const KIND_LABEL: Record<Kind, string> = { function: 'function', method: 'method', behaviour: 'behaviour' }

function FunctionTable({ list, slug, items, modules }: { list: PhpFunction[]; slug: string } & Resolver) {
  const [open, setOpen] = useState<string | null>(null)
  const th = 'px-3 py-2 text-left text-[11px] font-medium tracking-wider text-muted-foreground uppercase'
  return (
    <div className="overflow-x-auto rounded-lg border border-border">
      <table className="w-full min-w-[820px] table-fixed border-collapse text-[13px]">
        <colgroup>
          <col className="w-[25%]" />
          <col className="w-[29%]" />
          <col className="w-[8%]" />
          <col className="w-[10%]" />
          <col className="w-[12%]" />
          <col className="w-[16%]" />
        </colgroup>
        <thead className="bg-muted/50">
          <tr>
            <th className={th}>PHP</th>
            <th className={th}>Rust</th>
            <th className={`${th} text-right`}>Cases</th>
            <th className={`${th} text-right`} title="Fuzz inputs per run">Fuzz</th>
            <th className={th}>Quirks</th>
            <th className={th}>Used by</th>
          </tr>
        </thead>
        <tbody>
          {list.map((f) => {
            const notes = f.quirks.length + f.deviations.length
            const expanded = open === f.name
            return (
              <Fragment key={f.name}>
                <tr className="border-t border-border align-top">
                  <td className="px-3 py-2">
                    <div className="grid gap-0.5">
                      <span className="font-mono text-[12.5px] font-medium break-words">{f.kind === 'function' ? `${f.name}()` : f.name}</span>
                      {f.kind !== 'function' ? <span className="text-[11px] text-muted-foreground">{KIND_LABEL[f.kind]}</span> : null}
                    </div>
                  </td>
                  <td className="px-3 py-2">
                    <div className="grid gap-0.5">
                      {f.rust.length ? f.rust.slice(0, 4).map((p) => <RustLink key={p} path={p} slug={slug} lib={{ items, modules }} />) : <span className="text-muted-foreground">—</span>}
                      {f.rust.length > 4 ? <span className="text-[11px] text-muted-foreground">and {f.rust.length - 4} more</span> : null}
                    </div>
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">{formatCount(f.cases)}</td>
                  <td className="px-3 py-2 text-right tabular-nums" title={`${f.fuzz.profiles} fuzz ${f.fuzz.profiles === 1 ? 'profile' : 'profiles'}`}>
                    {f.fuzz.inputs ? formatCount(f.fuzz.inputs) : <span className="text-muted-foreground">—</span>}
                  </td>
                  <td className="px-3 py-2">
                    <button
                      type="button"
                      onClick={() => setOpen(expanded ? null : f.name)}
                      aria-expanded={expanded}
                      className="text-left text-[12.5px] text-muted-foreground underline decoration-border underline-offset-2 hover:text-foreground"
                    >
                      {notes ? `${f.quirks.length ? `${f.quirks.length} ${f.quirks.length === 1 ? 'quirk' : 'quirks'}` : ''}${f.quirks.length && f.deviations.length ? ', ' : ''}${f.deviations.length ? `${f.deviations.length} ${f.deviations.length === 1 ? 'deviation' : 'deviations'}` : ''}` : 'Details'}
                    </button>
                  </td>
                  <td className="px-3 py-2">
                    <div className="flex flex-wrap gap-1">
                      {f.used_by.length ? (
                        f.used_by.map((u) => (
                          <Link key={u} to="/$lib" params={{ lib: u }} className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground hover:text-foreground">
                            {u}
                          </Link>
                        ))
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </div>
                  </td>
                </tr>
                {expanded ? (
                  <tr className="bg-muted/30">
                    <td colSpan={6} className="px-3 pt-1 pb-3">
                      <Details f={f} />
                    </td>
                  </tr>
                ) : null}
              </Fragment>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function Details({ f }: { f: PhpFunction }) {
  const label = 'text-[11px] font-medium tracking-wider text-muted-foreground uppercase'
  return (
    <div className="grid max-w-[100ch] gap-3 text-[12.5px]">
      <div className="grid gap-1">
        <span className={label}>Compat operations</span>
        {f.ops.map((op, i) => (
          <div key={op} className="flex flex-wrap gap-x-2">
            <code className="font-mono text-[12px]">{op}</code>
            {f.doc[i] ? <span className="text-muted-foreground">{f.doc[i]}</span> : null}
          </div>
        ))}
      </div>
      {f.variants?.length ? (
        <div className="grid gap-1">
          <span className={label}>Filters</span>
          <div className="overflow-x-auto">
            <table className="min-w-[480px] border-collapse">
              <tbody>
                {f.variants.map((v) => (
                  <tr key={v.value} className="border-t border-border first:border-t-0">
                    <td className="py-1 pr-4 font-mono text-[12px]">{v.name}</td>
                    <td className="py-1 pr-4 text-right tabular-nums">{v.cases} cases</td>
                    <td className="py-1 text-right text-muted-foreground tabular-nums">{v.fuzz.inputs ? `${formatCount(v.fuzz.inputs)} fuzz inputs` : 'no fuzzing'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : null}
      {f.quirks.length ? (
        <div className="grid gap-1">
          <span className={label}>Quirks kept on purpose</span>
          {f.quirks.map((q) => (
            <div key={q.case} className="grid gap-0.5">
              <span>
                <span className="font-mono text-[12px]">{q.symbol}</span>: {q.description}
              </span>
              <span className="text-[11.5px] text-muted-foreground">Pinned by {q.case}</span>
            </div>
          ))}
        </div>
      ) : null}
      {f.deviations.length ? (
        <div className="grid gap-1">
          <span className={label}>Deviations</span>
          {f.deviations.map((d) => (
            <div key={d.symbol + d.php} className="grid gap-0.5">
              <span>
                <span className="font-mono text-[12px]">{d.symbol}</span>: PHP {d.php}
              </span>
              <span className="text-[11.5px] text-muted-foreground">{d.reason}</span>
            </div>
          ))}
        </div>
      ) : null}
    </div>
  )
}

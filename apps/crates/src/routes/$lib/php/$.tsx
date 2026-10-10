import { Link, createFileRoute, getRouteApi, notFound } from '@tanstack/react-router'
import { CodeBlock } from '@/components/Code'
import { Markdown } from '@/components/Markdown'
import { PageHeader, Section, WithToc } from '@/components/Page'
import { loadLibrary, phpDescription, sourceUrl } from '@/lib/docs'
import { useMigration } from '@/lib/prefs'

const root = getRouteApi('__root__')
const layout = getRouteApi('/$lib')

export const Route = createFileRoute('/$lib/php/$')({
  loader: async ({ params }) => {
    const lib = await loadLibrary(params.lib)
    if (!lib) throw notFound()
    const name = (params._splat ?? '').split('/').filter(Boolean).join('\\')
    const methods = lib.php_api.filter((p) => (p.class ?? `${p.namespace}\\functions`) === name)
    if (!methods.length) throw notFound()
    return { name, doc: methods[0].class_doc, methods }
  },
  component: PhpClass,
  notFoundComponent: () => <div className="text-muted-foreground">No PHP class by that name.</div>,
})

const strip = phpDescription

function PhpClass() {
  const { name, doc, methods } = Route.useLoaderData()
  const nav = layout.useLoaderData()
  const index = root.useLoaderData()
  const short = name.split('\\').pop() ?? name
  const migration = useMigration()
  // A PHP class page is part of the migration view.
  if (!migration)
    return (
      <div className="max-w-[72ch] rounded-lg border border-border bg-card p-4 text-[13.5px] text-muted-foreground">
        <code className="font-mono text-foreground">{name}</code> is part of the PHP library. Turn on <b className="font-medium text-foreground">Migration</b> to read it.
      </div>
    )
  return (
    <WithToc toc={[{ id: 'top', label: short }, ...methods.map((m) => ({ id: `php.${m.name}`, label: m.name, depth: 1 }))]}>
      <div id="top" className="grid scroll-mt-20 gap-4">
        <PageHeader eyebrow={<span className="font-mono text-[12.5px]">{name.slice(0, name.length - short.length - 1)}</span>} title={short} mono aside={<span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">PHP class</span>} />
        {nav.library.rust ? null : (
          <div className="rounded-lg border border-migration-border bg-migration px-3.5 py-2.5 text-[13px] text-migration-ink">
            {nav.library.title} is not converted to Rust yet. This is its PHP API, read from the library's source; the Rust crate will port each method.
          </div>
        )}
        {doc ? <Markdown text={strip(doc)} /> : null}
      </div>
      <Section id="methods" title="Methods">
        {methods.map((m) => (
          <div key={m.symbol} id={`php.${m.name}`} className="grid min-w-0 scroll-mt-20 gap-3 border-t border-border pt-5">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="font-mono text-[15px] font-medium">{m.name}</span>
              {m.file ? (
                <a href={sourceUrl(index?.branch ?? index?.commit, m.file, m.line)} target="_blank" rel="noopener" className="text-[12px] text-muted-foreground hover:text-foreground">
                  {m.file}:{m.line}
                </a>
              ) : null}
            </div>
            <CodeBlock code={m.signature} lang="php" />
            {m.doc ? <Markdown text={strip(m.doc)} /> : <p className="m-0 text-muted-foreground italic">No docblock.</p>}
            {m.rust.length ? (
              <div className="text-[12.5px] text-muted-foreground">
                In Rust:{' '}
                {m.rust.map((r) => (
                  <Link key={r} to="/$lib" params={{ lib: nav.library.slug }} className="font-mono text-foreground underline underline-offset-2">
                    {r}
                  </Link>
                ))}
              </div>
            ) : null}
          </div>
        ))}
      </Section>
    </WithToc>
  )
}

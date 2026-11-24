import { Link, createFileRoute, notFound } from '@tanstack/react-router'
import { ExampleView } from '@/components/Code'
import { Markdown } from '@/components/Markdown'
import { PageHeader, WithToc } from '@/components/Page'
import { guideHeadings, loadLibrary, rustGuide, slug } from '@/lib/docs'
import { useMigration } from '@/lib/prefs'

export const Route = createFileRoute('/$lib/lifecycle')({
  loader: async ({ params }) => {
    const lib = await loadLibrary(params.lib)
    if (!lib?.lifecycle?.length) throw notFound()
    return { library: lib.library, lifecycle: lib.lifecycle }
  },
  head: ({ loaderData }) => ({ meta: [{ title: `Lifecycle · ${loaderData?.library.title ?? 'Library'} · Utopia for Rust` }] }),
  component: LifecyclePage,
  notFoundComponent: () => <div className="text-muted-foreground">This library has no lifecycle guide.</div>,
})

/** Lead text with `code`. */
function Inline({ text }: { text: string }) {
  return (
    <>
      {text.split('`').map((part, i) =>
        i % 2 ? (
          <code key={i} className="bg-muted px-1 py-px font-mono text-[0.88em] text-foreground">
            {part}
          </code>
        ) : (
          part
        ),
      )}
    </>
  )
}

/** A server's lifecycle guide (`lifecycle.md`): what runs, in what order, as diagrams and runnable examples. */
function LifecyclePage() {
  const { library: l, lifecycle } = Route.useLoaderData()
  const migration = useMigration()
  // Outside migration mode the guide leaves out its PHP sections, as the getting started guide does.
  const blocks = migration ? lifecycle : rustGuide(lifecycle)
  // The text before the first heading is the page's lead.
  const first = blocks[0]
  const lead = first && 'text' in first && !first.text.startsWith('## ') ? first.text.split('\n## ')[0] : ''
  const body = lead && first && 'text' in first ? [{ text: first.text.slice(lead.length).trim() }, ...blocks.slice(1)] : blocks
  const toc = guideHeadings(body).map((h) => ({ id: slug(h), label: h }))
  return (
    <WithToc toc={toc}>
      <PageHeader
        eyebrow={
          <Link to="/$lib" params={{ lib: l.slug }} className="hover:text-foreground">
            {l.title}
          </Link>
        }
        title="Lifecycle"
      >
        {lead ? <Inline text={lead} /> : `What ${l.title} runs, and in what order.`}
      </PageHeader>
      <div className="grid gap-5">
        {body.map((b, i) => ('text' in b ? <Markdown key={i} text={b.text} /> : <ExampleView key={i} example={b.example} />))}
      </div>
    </WithToc>
  )
}

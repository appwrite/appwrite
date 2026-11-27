import { createFileRoute } from '@tanstack/react-router'
import { Markdown } from '@/components/Markdown'
import { PageHeader, Section } from '@/components/Page'
import { item } from '@/components/Sidebar'
import { slug } from '@/lib/docs'
import { share } from '@/lib/share'
import guide from '../../content/contributing.md?raw'

const DESCRIPTION = 'How to build and test the Utopia crates, serve them to PHP through the extension, and work on this site.'

/** The guide's sections, one per `## ` heading. */
const SECTIONS = guide
  .split(/^## /m)
  .filter((s) => s.trim())
  .map((s) => {
    const title = s.slice(0, s.indexOf('\n')).trim()
    return { id: slug(title), title, body: s.slice(s.indexOf('\n') + 1) }
  })

export const Route = createFileRoute('/contributing')({
  head: () => ({ meta: share({ title: 'Contributing', description: DESCRIPTION }) }),
  component: Contributing,
})

function Contributing() {
  return (
    <div className="mx-auto grid max-w-[1100px] gap-10 lg:grid-cols-[11rem_minmax(0,1fr)] lg:gap-x-12">
      <aside className="hidden lg:sticky lg:top-20 lg:block lg:self-start">
        <nav className="grid gap-px" aria-label="On this page">
          <h2 className="label px-2 pb-1 text-muted-foreground">On this page</h2>
          {SECTIONS.map((s) => (
            <a key={s.id} href={`#${s.id}`} className={item}>
              <span className="min-w-0">{s.title}</span>
            </a>
          ))}
        </nav>
      </aside>
      <div className="grid min-w-0 gap-10">
        <PageHeader eyebrow="Guide" title="Contributing">
          {DESCRIPTION}
        </PageHeader>
        <div className="grid gap-10">
          {SECTIONS.map((s, i) => (
            <Section key={s.id} id={s.id} title={s.title} number={i + 1}>
              <Markdown text={s.body} />
            </Section>
          ))}
        </div>
      </div>
    </div>
  )
}

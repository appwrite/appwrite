import { createFileRoute } from '@tanstack/react-router'
import { ArrowLeftRight } from 'lucide-react'
import { Markdown } from '@/components/Markdown'
import { MigrationCard } from '@/components/Migration'
import { PageHeader, Section } from '@/components/Page'
import { MIGRATION_TITLE, item } from '@/components/Sidebar'
import { slug } from '@/lib/docs'
import { setMigration, useMigration } from '@/lib/prefs'
import { share } from '@/lib/share'
import guide from '../../content/contributing.md?raw'

const DESCRIPTION = 'How to build, test and benchmark the Utopia crates, and check them against their PHP libraries.'

/** The guide's sections, one per `## ` heading. A section about PHP is shown in migration mode, as a library's guide does. */
const SECTIONS = guide
  .split(/^## /m)
  .filter((s) => s.trim())
  .map((s) => {
    const title = s.slice(0, s.indexOf('\n')).trim()
    return { id: slug(title), title, body: s.slice(s.indexOf('\n') + 1), migration: title.includes('PHP') }
  })

export const Route = createFileRoute('/contributing')({
  head: () => ({ meta: share({ title: 'Contributing', description: DESCRIPTION }) }),
  component: Contributing,
})

function Contributing() {
  const migration = useMigration()
  const shown = SECTIONS.filter((s) => migration || !s.migration)
  return (
    <div className="mx-auto grid max-w-[1100px] gap-10 lg:grid-cols-[11rem_minmax(0,1fr)] lg:gap-x-12">
      <aside className="hidden lg:sticky lg:top-20 lg:block lg:self-start">
        <nav className="grid gap-px" aria-label="On this page">
          <h2 className="label px-2 pb-1 text-muted-foreground">On this page</h2>
          {shown.map((s) => (
            <a key={s.id} href={`#${s.id}`} className={item(s.migration)} title={s.migration ? MIGRATION_TITLE : undefined}>
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
          {shown.map((s, i) => (
            <Section key={s.id} id={s.id} title={s.title} number={i + 1}>
              {s.migration ? (
                <MigrationCard>
                  <Markdown text={s.body} />
                </MigrationCard>
              ) : (
                <Markdown text={s.body} />
              )}
            </Section>
          ))}
          {migration ? null : (
            <p className="m-0 flex flex-wrap items-center gap-x-3 gap-y-2 border border-border p-4 text-[14px] text-muted-foreground">
              <ArrowLeftRight className="size-4 flex-none text-migration-ink" aria-hidden />
              <span className="min-w-0 flex-1">Every crate is also checked against the PHP library it ports, and benchmarked against it. Turn on migration mode to see how.</span>
              <button
                type="button"
                onClick={() => setMigration(true)}
                className="inline-flex h-8 items-center border border-migration-border bg-migration px-2.5 font-mono text-[10.5px] tracking-[0.08em] text-migration-ink uppercase"
              >
                Show migration
              </button>
            </p>
          )}
        </div>
      </div>
    </div>
  )
}

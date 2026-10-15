import { createFileRoute } from '@tanstack/react-router'
import { MigrationGate } from '@/components/Appwrite'
import { PageHeader, Section } from '@/components/Page'
import { Generated, Time } from '@/components/Time'
import { loadMigration } from '@/lib/appwrite'
import { loadIndex } from '@/lib/docs'
import { FACTS, type Fact } from '@/lib/facts'

export const Route = createFileRoute('/appwrite/facts')({
  loader: async () => {
    const [index, migration] = await Promise.all([loadIndex(), loadMigration()])
    return {
      libraries: index?.libraries.length ?? null,
      complete: index?.libraries.filter((l) => l.status === 'complete').length ?? null,
      size: index?.size ?? null,
      api: migration?.summary ?? null,
      generated: migration ? { at: migration.generated_at, commit: migration.commit, commitDate: migration.commit_date } : null,
    }
  },
  head: () => ({ meta: [{ title: 'Facts and figures · Appwrite migration · Utopia for Rust' }] }),
  component: Facts,
})

const n = (v: number) => v.toLocaleString('en-US')

function Tile({ fact }: { fact: Fact }) {
  return (
    <li className="grid content-start gap-1 rounded-lg border border-border bg-card p-4">
      <span className="font-title text-[26px] leading-none tabular-nums">{fact.value}</span>
      <span className="text-[13px] font-medium">{fact.label}</span>
      <span className="text-[12.5px] leading-relaxed text-muted-foreground">{fact.note}</span>
    </li>
  )
}

function Tiles({ facts }: { facts: Fact[] }) {
  return <ul className="m-0 grid list-none gap-3 p-0 sm:grid-cols-2 lg:grid-cols-4">{facts.map((f) => <Tile key={f.label} fact={f} />)}</ul>
}

/** The project in numbers: what was converted, how it was proven, how it performs and how it was built. */
function Facts() {
  const { libraries, complete, size, api, generated } = Route.useLoaderData()
  // Read from the generated site data, so they follow every regeneration.
  const live: Fact[] = []
  if (libraries !== null && complete !== null) {
    live.push({ value: `${complete} / ${libraries}`, label: 'Utopia libraries converted', note: 'Each proven against its PHP original with bin/compat.' })
  }
  if (size) {
    live.push({ value: n(size.rust.code), label: 'lines of Rust', note: `Library code across ${size.rust.libraries} crates, against ${n(size.php.code)} lines of PHP in ${size.php.libraries} packages.` })
  }
  if (api) {
    live.push({ value: `${api.served} / ${api.endpoints}`, label: 'API endpoints served by Rust', note: `${api.ready} more implemented and waiting for routing; ${api.services} services in all.` })
    live.push({ value: `${api.hooks.done} / ${api.hooks.total}`, label: 'cross-cutting hooks done', note: `${api.hooks.partial} partly done, ${api.hooks.missing} not started; ${api.hooks.done_needed} of the ${api.hooks.needed} needed today.` })
  }
  return (
    <div className="mx-auto grid max-w-[1100px] grid-cols-[minmax(0,1fr)] gap-10">
      <PageHeader eyebrow="Appwrite migration" title="Facts and figures">
        {FACTS.summary}
        <div className="mt-2 text-[12px]">
          Figures last changed <Time date={FACTS.updated} />
          {generated ? (
            <>
              {' · '}
              <Generated at={generated.at} commit={generated.commit} commitDate={generated.commitDate} label="site data generated" />
            </>
          ) : null}
        </div>
      </PageHeader>
      <MigrationGate>
        {live.length ? (
          <Section title="Where it stands">
            <Tiles facts={live} />
          </Section>
        ) : null}
        {FACTS.sections.map((s) => (
          <Section key={s.title} title={s.title}>
            <Tiles facts={s.facts} />
          </Section>
        ))}
        <Section title="Timeline">
          <ol className="m-0 grid list-none gap-0 border-l border-border p-0">
            {FACTS.timeline.map((t) => (
              <li key={t.date + t.title} className="relative grid gap-0.5 pb-5 pl-5 last:pb-0">
                <span className="absolute top-1.5 -left-[4.5px] size-2 rounded-full bg-foreground" aria-hidden />
                <span className="font-mono text-[11.5px] text-muted-foreground">{t.date}</span>
                <span className="text-[14px] font-medium">{t.title}</span>
                <span className="max-w-[80ch] text-[13px] leading-relaxed text-muted-foreground">{t.note}</span>
              </li>
            ))}
          </ol>
        </Section>
        <Section title="Lessons">
          <ul className="m-0 grid max-w-[80ch] list-disc gap-2 pl-5 text-[13.5px] leading-relaxed">
            {FACTS.lessons.map((l) => (
              <li key={l}>{l}</li>
            ))}
          </ul>
        </Section>
      </MigrationGate>
    </div>
  )
}

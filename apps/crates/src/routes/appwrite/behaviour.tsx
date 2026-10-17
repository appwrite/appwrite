import { createFileRoute, notFound } from '@tanstack/react-router'
import { useState } from 'react'
import { HookRow, MigrationGate } from '@/components/Appwrite'
import { PageHeader } from '@/components/Page'
import { Generated, Time } from '@/components/Time'
import { HOOK_STATUS, KIND_LABEL, loadMigration, type Hook } from '@/lib/appwrite'

export const Route = createFileRoute('/appwrite/behaviour')({
  loader: async () => {
    const m = await loadMigration()
    if (!m) throw notFound()
    return {
      hooks: m.hooks,
      summary: m.summary.hooks,
      ref: m.branch ?? m.commit,
      generated: { at: m.generated_at, commit: m.commit, commitDate: m.commit_date },
      changed: m.updated?.hooks,
    }
  },
  head: () => ({ meta: [{ title: 'Cross-cutting behaviour · Appwrite migration · Utopia for Rust' }] }),
  component: Behaviour,
})

const chip =
  'inline-flex h-7 items-center gap-1.5 rounded-md border border-border px-2.5 text-[12.5px] text-muted-foreground transition-colors aria-pressed:border-foreground aria-pressed:bg-accent aria-pressed:text-foreground'

const KINDS: Hook['kind'][] = ['init', 'shutdown', 'error', 'options', 'wildcard', 'resource']

/** Every PHP hook and behaviour-carrying resource a migrated route must reproduce, and where Rust stands on each. */
function Behaviour() {
  const { hooks, summary, ref, generated, changed } = Route.useLoaderData()
  const [shown, setShown] = useState<'needed' | 'all' | 'open'>('needed')
  const list = hooks.filter((h) => (shown === 'all' ? true : shown === 'needed' ? h.needed : h.rust.status !== 'done'))
  return (
    <div className="mx-auto grid max-w-[1100px] grid-cols-[minmax(0,1fr)] gap-8">
      <PageHeader eyebrow="Appwrite migration" title="Cross-cutting behaviour">
        What every migrated route must reproduce besides its action: the PHP init, shutdown and error hooks and the request resources that carry
        behaviour, read from the code, and where appwrite-core reproduces each one (crates/appwrite/hooks.json).
        <div className="mt-2 text-[12px]">
          <Generated at={generated.at} commit={generated.commit} commitDate={generated.commitDate} label="Generated" />
          {changed ? (
            <>
              {' '}
              · hooks.json and appwrite-core last changed <Time date={changed} />
            </>
          ) : null}
        </div>
      </PageHeader>
      <MigrationGate>
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Hooks shown">
          <button type="button" aria-pressed={shown === 'needed'} onClick={() => setShown('needed')} className={chip} title="Hooks that run for a route Rust serves today">
            Needed now <span className="tabular-nums">{summary.needed}</span>
          </button>
          <button type="button" aria-pressed={shown === 'open'} onClick={() => setShown('open')} className={chip}>
            Not done <span className="tabular-nums">{hooks.filter((h) => h.rust.status !== 'done').length}</span>
          </button>
          <button type="button" aria-pressed={shown === 'all'} onClick={() => setShown('all')} className={chip}>
            All <span className="tabular-nums">{summary.total}</span>
          </button>
          <span className="ml-auto text-[12px] text-muted-foreground">
            {(['done', 'partial', 'missing'] as const).map((k) => `${hooks.filter((h) => h.rust.status === k).length} ${HOOK_STATUS[k].label.toLowerCase()}`).join(' · ')}
          </span>
        </div>
        {KINDS.map((kind) => {
          const group = list.filter((h) => h.kind === kind)
          return group.length ? (
            <section key={kind} className="grid min-w-0 gap-1.5">
              <h2 className="text-[15px]">{KIND_LABEL[kind]}</h2>
              <ul className="m-0 min-w-0 list-none rounded-lg border border-border bg-card p-0">
                {group.map((h) => (
                  <HookRow key={h.id} hook={h} gitRef={ref} kind={false} />
                ))}
              </ul>
            </section>
          ) : null
        })}
      </MigrationGate>
    </div>
  )
}

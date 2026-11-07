import { createFileRoute, notFound } from '@tanstack/react-router'
import { Search } from 'lucide-react'
import { useState } from 'react'
import { HookRow } from '@/components/Appwrite'
import { TabProgress, TestState, chip } from '@/components/Dashboard'
import { Generated, Time } from '@/components/Time'
import { KIND_LABEL, hookTests, loadMigration, type Hook, type RustE2e } from '@/lib/appwrite'
import * as progress from '@/lib/progress'

export const Route = createFileRoute('/migration/hooks')({
  loader: async () => {
    const m = await loadMigration()
    if (!m) throw notFound()
    return {
      hooks: m.hooks,
      tests: hookTests(m),
      summary: m.summary.hooks,
      ref: m.branch ?? m.commit,
      generated: { at: m.generated_at, commit: m.commit, commitDate: m.commit_date },
      changed: m.updated?.hooks,
    }
  },
  head: () => ({ meta: [{ title: 'Hooks · Migration · Utopia for Rust' }] }),
  component: Hooks,
})

const KINDS: Hook['kind'][] = ['init', 'shutdown', 'error', 'options', 'wildcard', 'resource']

/** The filter for hooks with no group: the ones that run on every request. */
const EVERY = '*'

/** The E2E suites run against Rust that go through a hook, and how their latest run went. */
function Tests({ tests }: { tests: RustE2e[] }) {
  if (!tests.length) return <TestState state="none">No E2E run against Rust goes through it yet</TestState>
  return (
    <>
      <span className="text-muted-foreground">E2E on Rust:</span>
      {tests.map((t) =>
        t.run ? (
          <TestState key={t.service} state={t.run.failures + t.run.errors ? 'fail' : 'pass'} title={`${t.run.suite} against Rust${t.run.commit ? ` at ${t.run.commit}` : ''}`}>
            {t.title} {t.run.passed}/{t.run.tests}
            <span className="text-muted-foreground">
              {' '}
              · <Time date={t.run.date} />
            </span>
          </TestState>
        ) : (
          <TestState key={t.service} state="partial" title="CI runs the suites against Rust; no run is recorded here">
            {t.title} (CI)
          </TestState>
        ),
      )}
    </>
  )
}

/** Every PHP hook and behaviour-carrying resource a migrated route must reproduce, where Rust stands on each, and the tests through it. */
function Hooks() {
  const { hooks, tests, summary, ref, generated, changed } = Route.useLoaderData()
  const [shown, setShown] = useState<'needed' | 'all' | 'open'>('all')
  const [groups, setGroups] = useState<string[]>([])
  const [q, setQ] = useState('')
  const term = q.trim().toLowerCase()
  const all = [...new Set(hooks.flatMap((h) => h.groups))].sort((a, b) => count(hooks, b) - count(hooks, a) || a.localeCompare(b))
  const toggle = (g: string) => setGroups((gs) => (gs.includes(g) ? gs.filter((x) => x !== g) : [...gs, g]))
  const list = hooks.filter(
    (h) =>
      (shown === 'all' ? true : shown === 'needed' ? h.needed : h.rust.status !== 'done') &&
      (!groups.length || groups.some((g) => (g === EVERY ? !h.groups.length : h.groups.includes(g)))) &&
      (!term || `${h.title ?? ''} ${h.id} ${h.description} ${h.file} ${h.rust.note ?? ''}`.toLowerCase().includes(term)),
  )
  return (
    <div className="grid min-w-0 gap-6">
      <TabProgress title="Hooks reproduced in Rust" progress={progress.hooks(hooks)}>
        The init, shutdown and error hooks and the request resources every migrated route runs besides its action, read from the code; where
        appwrite-core reproduces each is in crates/appwrite/hooks.json. {summary.done_needed} of the {summary.needed} that Rust's routes need today are
        done. <Generated at={generated.at} commit={generated.commit} commitDate={generated.commitDate} label="Generated" />
        {changed ? (
          <>
            ; hooks.json and appwrite-core last changed <Time date={changed} />
          </>
        ) : null}
        .
      </TabProgress>

      <div className="grid gap-2">
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Hooks shown">
          <label className="flex h-8 min-w-0 flex-[1_1_240px] items-center gap-2 rounded-md border border-border bg-card px-3 text-muted-foreground focus-within:border-ring sm:max-w-[320px]">
            <Search className="size-3.5 flex-none" />
            <input
              type="search"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Filter hooks"
              aria-label="Filter hooks"
              className="min-w-0 flex-1 bg-transparent text-[13px] text-foreground outline-none placeholder:text-muted-foreground"
            />
          </label>
          <button type="button" aria-pressed={shown === 'all'} onClick={() => setShown('all')} className={chip}>
            All <span className="tabular-nums">{summary.total}</span>
          </button>
          <button type="button" aria-pressed={shown === 'needed'} onClick={() => setShown('needed')} className={chip} title="Hooks that run for a route Rust serves today">
            Needed now <span className="tabular-nums">{summary.needed}</span>
          </button>
          <button type="button" aria-pressed={shown === 'open'} onClick={() => setShown('open')} className={chip}>
            Not done <span className="tabular-nums">{hooks.filter((h) => h.rust.status !== 'done').length}</span>
          </button>
        </div>
        <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label="Groups">
          <span className="mr-1 text-[12px] text-muted-foreground">Groups</span>
          {all.map((g) => (
            <button key={g} type="button" aria-pressed={groups.includes(g)} onClick={() => toggle(g)} className={`${chip} h-6 px-2 font-mono text-[11.5px]`}>
              {g} <span className="font-sans tabular-nums">{count(hooks, g)}</span>
            </button>
          ))}
          <button
            type="button"
            aria-pressed={groups.includes(EVERY)}
            onClick={() => toggle(EVERY)}
            className={`${chip} h-6 px-2 text-[11.5px]`}
            title="Hooks with no group: error and preflight hooks and the request resources, which run on every request"
          >
            Every request <span className="tabular-nums">{hooks.filter((h) => !h.groups.length).length}</span>
          </button>
          {groups.length ? (
            <button type="button" onClick={() => setGroups([])} className="ml-1 text-[12px] text-muted-foreground hover:text-foreground hover:underline">
              Clear
            </button>
          ) : null}
        </div>
      </div>

      {KINDS.map((kind) => {
        const group = list.filter((h) => h.kind === kind)
        return group.length ? (
          <section key={kind} className="grid min-w-0 gap-1.5">
            <h2 className="flex items-baseline gap-2 text-[15px]">
              {KIND_LABEL[kind]}
              <span className="font-mono text-[11px] text-muted-foreground tabular-nums">
                {group.filter((h) => h.rust.status === 'done').length}/{group.length} done
              </span>
            </h2>
            <ul className="m-0 min-w-0 list-none rounded-lg border border-border bg-card p-0">
              {group.map((h) => (
                <HookRow key={h.id} hook={h} gitRef={ref} kind={false} tests={<Tests tests={tests[h.id] ?? []} />} />
              ))}
            </ul>
          </section>
        ) : null
      })}
      {!list.length ? <p className="m-0 text-[13px] text-muted-foreground">No hook matches.</p> : null}
    </div>
  )
}

const count = (hooks: Hook[], group: string) => hooks.filter((h) => h.groups.includes(group)).length

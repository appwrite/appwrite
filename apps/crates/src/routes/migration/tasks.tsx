import { createFileRoute } from '@tanstack/react-router'
import { TabProgress } from '@/components/Dashboard'
import { Time } from '@/components/Time'
import * as progress from '@/lib/progress'
import { BOARD, COLUMNS } from '@/lib/tasks'

export const Route = createFileRoute('/migration/tasks')({
  head: () => ({ meta: [{ title: 'Tasks · Migration · Utopia for Rust' }] }),
  component: Tasks,
})

/** The migration's work, current and planned, in one column per status. */
function Tasks() {
  return (
    <div className="grid min-w-0 gap-6">
      <TabProgress title="Tasks done" progress={progress.tasks(BOARD)}>
        What is being worked on, what waits, what comes next and what is done, kept up to date as work moves (apps/crates/content/tasks.json). Last changed{' '}
        <Time date={BOARD.updated} />.
      </TabProgress>
      <div className="grid min-w-0 gap-x-6 gap-y-8 md:grid-cols-2 xl:grid-cols-4">
        {COLUMNS.map((c) => {
          const tasks = BOARD.tasks.filter((t) => t.status === c.status).sort((a, b) => (c.status === 'done' ? b.updated.localeCompare(a.updated) : 0))
          return (
            <section key={c.status} className="grid min-w-0 content-start gap-3" aria-label={c.label}>
              <header className="flex items-center gap-2 border-b border-border pb-2" title={c.note}>
                <span className={`size-2 flex-none rounded-full ${c.dot}`} aria-hidden />
                <h2 className="m-0 text-[14px] font-medium">{c.label}</h2>
                <span className="ml-auto font-mono text-[11px] text-muted-foreground tabular-nums">{tasks.length}</span>
              </header>
              {tasks.length ? (
                <ul className="m-0 grid min-w-0 list-none gap-3 p-0">
                  {tasks.map((t) => (
                    <li key={t.id} className="grid min-w-0 gap-2 rounded-lg border border-border bg-card p-4">
                      <span className="text-[13.5px] leading-snug font-medium [overflow-wrap:anywhere]">{t.title}</span>
                      <span className="text-[12.5px] leading-relaxed [overflow-wrap:anywhere] text-muted-foreground">{t.note}</span>
                      <span className="mt-1 flex items-center gap-2 text-[11px] text-muted-foreground">
                        <span className="rounded-sm border border-border px-1.5 py-px font-mono">{t.area}</span>
                        <Time date={t.updated} className="ml-auto" />
                      </span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="m-0 text-[12.5px] text-muted-foreground">Nothing here.</p>
              )}
            </section>
          )
        })}
      </div>
    </div>
  )
}

import { createFileRoute } from '@tanstack/react-router'
import { MigrationGate } from '@/components/Appwrite'
import { PageHeader } from '@/components/Page'
import { Time } from '@/components/Time'
import { BOARD, COLUMNS } from '@/lib/tasks'

export const Route = createFileRoute('/appwrite/tasks')({
  head: () => ({ meta: [{ title: 'Task board · Appwrite migration · Utopia for Rust' }] }),
  component: Tasks,
})

/** The migration's work, current and planned, in one column per status. */
function Tasks() {
  return (
    <div className="mx-auto grid max-w-[1400px] grid-cols-[minmax(0,1fr)] gap-8">
      <PageHeader eyebrow="Appwrite migration" title="Task board">
        What is being worked on, what waits, what comes next and what is done, kept up to date as work moves (apps/crates/content/tasks.json).
        <div className="mt-2 text-[12px]">
          Last changed <Time date={BOARD.updated} />
        </div>
      </PageHeader>
      <MigrationGate>
        <div className="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-4">
          {COLUMNS.map((c) => {
            const tasks = BOARD.tasks.filter((t) => t.status === c.status).sort((a, b) => (c.status === 'done' ? b.updated.localeCompare(a.updated) : 0))
            return (
              <section key={c.status} className="grid min-w-0 content-start gap-2" aria-label={c.label}>
                <header className="flex items-center gap-2 border-b border-border pb-2" title={c.note}>
                  <span className={`size-2 flex-none rounded-full ${c.dot}`} aria-hidden />
                  <h2 className="m-0 text-[14px] font-medium">{c.label}</h2>
                  <span className="ml-auto font-mono text-[11px] text-muted-foreground tabular-nums">{tasks.length}</span>
                </header>
                {tasks.length ? (
                  <ul className="m-0 grid list-none gap-2 p-0">
                    {tasks.map((t) => (
                      <li key={t.id} className="grid gap-1.5 rounded-lg border border-border bg-card p-3">
                        <span className="text-[13.5px] font-medium leading-snug">{t.title}</span>
                        <span className="text-[12.5px] leading-relaxed text-muted-foreground">{t.note}</span>
                        <span className="flex items-center gap-2 text-[11px] text-muted-foreground">
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
      </MigrationGate>
    </div>
  )
}

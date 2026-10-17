import { Outlet, createFileRoute } from '@tanstack/react-router'
import { MigrationGate } from '@/components/Appwrite'
import { Tabs } from '@/components/Dashboard'
import { PageHeader } from '@/components/Page'
import { loadMigration } from '@/lib/appwrite'
import { loadIndex } from '@/lib/docs'
import * as progress from '@/lib/progress'
import { BOARD } from '@/lib/tasks'

export const Route = createFileRoute('/migration')({
  loader: async () => {
    const [index, m] = await Promise.all([loadIndex(), loadMigration()])
    const utopia = index ? progress.utopia(index.libraries) : null
    const services = m ? progress.services(m.summary) : null
    const hooks = m ? progress.hooks(m.hooks) : null
    const sections = [utopia, services, hooks].filter((p): p is progress.Progress => !!p)
    return {
      progress: {
        ...(utopia ? { utopia } : {}),
        ...(services ? { services } : {}),
        ...(hooks ? { hooks } : {}),
        facts: progress.overall(sections),
        tasks: progress.tasks(BOARD),
      },
    }
  },
  head: () => ({ meta: [{ title: 'Migration · Utopia for Rust' }] }),
  component: Dashboard,
})

/** The move from PHP to Rust, one tab per part: the libraries, the API services, the hooks, the numbers and the work. */
function Dashboard() {
  const { progress } = Route.useLoaderData()
  return (
    <div className="mx-auto grid max-w-[1200px] grid-cols-[minmax(0,1fr)] gap-8">
      <PageHeader eyebrow="Migration" title="PHP to Rust">
        Appwrite moves from PHP to Rust in layers: first the Utopia libraries it stands on, each proven identical to PHP; then its API, one service at a
        time, run side by side with PHP behind Traefik on the same database, caches and queues; and with it the shared logic every request runs through.
      </PageHeader>
      <MigrationGate>
        <div className="grid min-w-0 gap-8">
          <Tabs progress={progress} />
          <Outlet />
        </div>
      </MigrationGate>
    </div>
  )
}

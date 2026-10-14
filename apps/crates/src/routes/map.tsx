import { createFileRoute, getRouteApi } from '@tanstack/react-router'
import { PageHeader } from '@/components/Page'
import { MapView } from '@/components/Map'
import { loadGraph } from '@/lib/docs'

const root = getRouteApi('__root__')

export const Route = createFileRoute('/map')({
  loader: () => loadGraph(),
  head: () => ({ meta: [{ title: 'Map · Utopia for Rust' }] }),
  component: MapPage,
})

function MapPage() {
  const graph = Route.useLoaderData()
  const index = root.useLoaderData()
  return (
    // On desktop the page is exactly the fold: the header and toolbar take
    // their height and the map the rest (the main column's 9.5rem is the top
    // bar, its top padding and its bottom padding).
    <div className="grid gap-6 md:flex md:h-[calc(100dvh-9.5rem)] md:min-h-[520px] md:flex-col md:gap-4">
      <PageHeader title="Map">Ribbon width is how libraries depend on each other.</PageHeader>
      {graph && index ? (
        <MapView graph={graph} index={index} />
      ) : (
        <div className="max-w-2xl rounded-lg border border-border bg-card p-5 text-[13.5px] text-muted-foreground">
          This documentation was generated before the map existed. Regenerate it with <code className="font-mono">bin/compat crates</code>, then reload.
        </div>
      )}
    </div>
  )
}

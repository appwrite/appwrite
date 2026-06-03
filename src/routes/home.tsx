import { createFileRoute } from '@tanstack/react-router'
import { createServerFn } from '@tanstack/react-start'
import { pageTitle } from '@/lib/utils/page-title'

const getHomeDemoValue = createServerFn({ method: 'GET' }).handler(async () => {
  return {
    value: 'This value was rendered on the server.',
    renderedAt: new Date().toISOString(),
  }
})

export const Route = createFileRoute('/home')({
  ssr: true,
  head: () => ({ meta: [{ title: pageTitle('Home') }] }),
  loader: async () => getHomeDemoValue(),
  component: HomePage,
})

function HomePage() {
  const demo = Route.useLoaderData()

  return (
    <main className="flex min-h-svh items-center justify-center bg-background px-6 py-12 text-foreground">
      <section className="w-full max-w-xl rounded-xl border border-border bg-card/50 p-6 shadow-sm">
        <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
          Public SSR demo
        </p>
        <h1 className="mt-3 text-[28px] font-semibold tracking-tight">Home</h1>
        <p className="mt-3 text-[14px] leading-6 text-muted-foreground">
          This page is publicly accessible and does not require authentication.
        </p>
        <div className="mt-6 rounded-lg border border-border bg-muted/30 p-4">
          <p className="text-[13px] font-medium">{demo.value}</p>
          <p className="mt-2 text-[12px] text-muted-foreground">
            Server timestamp: {demo.renderedAt}
          </p>
        </div>
      </section>
    </main>
  )
}

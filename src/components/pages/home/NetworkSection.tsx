import { CheckCircle2 } from 'lucide-react'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { Button } from '@/components/ui/button'
import { NetworkGlobeMount } from './NetworkGlobeMount'

const networkProtections = [
  'Global CDN',
  'DDoS protection',
  'Sub-50ms latency',
] as const

export function NetworkSection() {
  return (
    <section className="border-t border-border bg-background">
      <div className="relative overflow-hidden px-4 pt-16 pb-0 sm:px-6 sm:pt-20">
        <div className="mx-auto flex w-full max-w-4xl flex-col items-center text-center">
          <h2 className="font-aeonik-pro text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
            The Appwrite Network
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <p className="mt-5 max-w-2xl text-[14px] leading-6 text-muted-foreground sm:text-[15px]">
            Built into every Appwrite project: backend APIs, serverless functions,
            and hosted websites, with requests and assets served through our CDN
            and DDoS protection at the network edge. Choose global regions and
            edges to optimize latency, compliance, and data residency.
          </p>

          <ul
            className="mt-6 flex list-none flex-wrap items-center justify-center gap-x-5 gap-y-2"
            aria-label="Network protections included with every Appwrite project"
          >
            {networkProtections.map((label) => (
              <li
                key={label}
                className="flex items-center gap-1.5 text-[13px] font-medium text-foreground"
              >
                <CheckCircle2
                  className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                  aria-hidden
                />
                {label}
              </li>
            ))}
          </ul>

          <Button
            variant="outline"
            className="relative z-30 mt-6 h-10 text-[13px]"
            asChild
          >
            <DocsRouteLink href="/docs/products/network">
              More about the Appwrite Network
            </DocsRouteLink>
          </Button>
        </div>

        <NetworkGlobeMount className="relative z-20 -mt-2 w-full sm:-mt-6 lg:-mt-10" />

        <div
          className="pointer-events-none absolute inset-x-0 bottom-0 z-10"
          aria-hidden
        >
          <div className="h-32 w-full bg-gradient-to-b from-transparent to-background sm:h-40 lg:h-48" />
        </div>
      </div>

      <div className="w-full border-t border-border" aria-hidden />
    </section>
  )
}

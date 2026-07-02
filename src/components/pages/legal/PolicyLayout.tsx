import type { ReactNode } from 'react'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { PricingSectionHeading } from '@/components/pages/pricing/_components/PricingSectionHeading'
import type { PolicySlug } from '@/lib/legal/policies'
import { PolicyRelatedLinks } from '@/components/pages/legal/PolicyRelatedLinks'
import { PolicyToc, type PolicyTocItem } from './PolicyToc'
import { useT } from '@/lib/i18n/translate'

type PolicyLayoutProps = {
  title: string
  tocItems: readonly PolicyTocItem[]
  currentPolicy?: PolicySlug
  children: ReactNode
}

export function PolicyLayout({
  title,
  tocItems,
  currentPolicy,
  children,
}: PolicyLayoutProps) {
  const t = useT()
  return (
    <div className="relative">
      <HomeSoftLights variant="pricing" className="pointer-events-none opacity-70" />

      <div className="relative mx-auto w-full max-w-7xl px-4 pb-16 pt-12 sm:px-6 sm:pb-20 sm:pt-16">
        <div className="grid items-start gap-10 lg:grid-cols-[220px_minmax(0,1fr)] lg:gap-16 xl:grid-cols-[240px_minmax(0,1fr)]">
          <header className="space-y-4 lg:col-span-2">
            <PricingSectionHeading
              as="h1"
              align="left"
              title={t(title)}
              className="max-w-3xl"
            />
            {currentPolicy ? (
              <div className="lg:hidden">
                <PolicyRelatedLinks current={currentPolicy} />
              </div>
            ) : null}
          </header>

          <PolicyToc items={tocItems} currentPolicy={currentPolicy} />

          <div className="min-w-0 overflow-x-hidden">
            <section id="introduction" className="scroll-mt-24">
              {children}
            </section>
          </div>
        </div>
      </div>
    </div>
  )
}

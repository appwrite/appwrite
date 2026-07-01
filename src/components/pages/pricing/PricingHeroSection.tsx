'use client'

import { ArrowRight } from 'lucide-react'
import type { MouseEvent } from 'react'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { Button } from '@/components/ui/button'
import {
  PRICING_COMPARE_ANCHOR_ID,
  scrollToComparisonSection,
} from '@/lib/pricing/comparison-scroll'
import { PricingCardsGrid } from './_components/PricingShared'
import { PricingSectionHeading } from './_components/PricingSectionHeading'
import { PricingServicesAvatars } from './_components/PricingServicesAvatars'

export function PricingHeroSection() {
  return (
    <section className="relative isolate overflow-x-hidden border-b border-border bg-background pb-14 pt-10 sm:pb-16 sm:pt-14 lg:pb-20">
      <HomeSoftLights variant="pricing" />
      <div
        className="absolute inset-0 z-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]"
        aria-hidden
      />
      <div className="relative z-[1] mx-auto w-full max-w-7xl px-4 sm:px-6">
        <PricingSectionHeading
          title={
            <>
              Everything your app needs,
              <br />
              one subscription
            </>
          }
          description="Build, deploy, secure, and observe your app from one platform, all under one subscription."
        />

        <div className="mt-16 px-2 sm:mt-20 sm:px-4 lg:mt-24 lg:px-8">
          <PricingCardsGrid />
        </div>

        <PricingServicesAvatars />
      </div>
    </section>
  )
}

const beforeItems = [
  'Multiple tools with overlapping responsibilities',
  'Separate subscriptions, invoices, and renewal cycles',
  'More integration, maintenance, and ownership overhead',
] as const

const afterItems = [
  'One platform across the app lifecycle',
  'One subscription with simpler billing and procurement',
  'Fewer systems to integrate, secure, and maintain',
] as const

export function StackConsolidationSection() {
  return (
    <section className="relative overflow-x-hidden border-b border-border bg-background py-16 sm:py-20">
      <HomeSoftLights variant="testimonials" />
      <div className="relative z-10 mx-auto w-full max-w-7xl px-4 sm:px-6">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <PricingSectionHeading
            align="left"
            size="md"
            title="One platform. One subscription."
            className="max-w-2xl"
          />
          <span className="inline-flex w-fit shrink-0 rounded-full border border-border bg-background/70 px-3 py-1 text-[11px] font-medium text-muted-foreground">
            1 vendor • 1 subscription • 1 bill
          </span>
        </div>

        <p className="mt-4 max-w-3xl text-[14px] leading-7 text-muted-foreground">
          Replace fragmented backend, hosting, storage, and delivery tooling with a
          single platform built for the full application lifecycle. Reduce integration
          surface area, simplify procurement, and give your team one system to operate
          and scale.
        </p>

        <div className="mt-8 grid gap-4 md:grid-cols-2">
          <div className="rounded-xl border border-border bg-card/45 p-5">
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Before
            </p>
            <ul className="mt-4 space-y-3">
              {beforeItems.map((item) => (
                <li key={item} className="text-[13px] leading-6 text-muted-foreground">
                  {item}
                </li>
              ))}
            </ul>
          </div>
          <div className="rounded-xl border border-border bg-card/45 p-5">
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              After
            </p>
            <ul className="mt-4 space-y-3">
              {afterItems.map((item) => (
                <li key={item} className="text-[13px] leading-6 text-foreground">
                  {item}
                </li>
              ))}
            </ul>
          </div>
        </div>

        <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
          <Button variant="outline" className="h-10 text-[13px]" asChild>
            <a
              href={`#${PRICING_COMPARE_ANCHOR_ID}`}
              onClick={(event: MouseEvent<HTMLAnchorElement>) => {
                event.preventDefault()
                scrollToComparisonSection(PRICING_COMPARE_ANCHOR_ID)
              }}
            >
              See what&apos;s included
              <ArrowRight className="ms-1.5 size-4" />
            </a>
          </Button>
          <p className="text-[12px] text-muted-foreground">
            Compare plan limits, included capabilities, and scaling options.
          </p>
        </div>
      </div>
    </section>
  )
}

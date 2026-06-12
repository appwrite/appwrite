'use client'

import { Link } from '@tanstack/react-router'
import { useMemo, useState } from 'react'
import {
  MarketingCtaSection,
  MarketingHeroSection,
} from '@/components/pages/marketing/MarketingSections'
import { Button } from '@/components/ui/button'
import { getChangelogEntriesPage } from '@/lib/changelog/content'
import { ChangelogEntryCard } from './ChangelogEntryCard'

export function View() {
  const [page, setPage] = useState(1)
  const { entries, nextPage } = useMemo(() => getChangelogEntriesPage(page), [page])

  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        title="Changelog"
        description="Explore Appwrite's changelog to stay on top of all the product updates and track our journey."
        align="left"
      />

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-[49.375rem] px-4 sm:px-6">
          <ol className="relative grid min-w-0 gap-20 border-s border-border ps-8 sm:ps-0 sm:[&>li]:ps-8">
            {entries.map((entry) => (
              <li key={entry.slug} className="relative min-w-0">
                <span
                  className="absolute start-0 top-1 hidden size-2.5 -translate-x-1/2 rounded-full border-2 border-border bg-background sm:block"
                  aria-hidden
                />
                <ChangelogEntryCard entry={entry} />
              </li>
            ))}
          </ol>

          {nextPage ? (
            <div className="mt-20 flex justify-center">
              <Button
                variant="outline"
                className="min-w-44"
                onClick={() => setPage(nextPage)}
              >
                Load more
              </Button>
            </div>
          ) : null}
        </div>
      </section>

      <MarketingCtaSection title="Ready to build?">
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/sign-up" search={{ redirect: '/' }}>
            Get started
          </Link>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/pricing">View pricing</Link>
        </Button>
      </MarketingCtaSection>
    </div>
  )
}

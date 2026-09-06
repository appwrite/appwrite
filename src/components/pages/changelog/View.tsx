'use client'

import { useState } from 'react'
import {
  MarketingCtaSection,
  MarketingCtaSignupButtons,
  MarketingHeroSection,
} from '@/components/pages/marketing/MarketingSections'
import type { ChangelogEntry, ChangelogTag } from '@/lib/changelog/types'
import { ChangelogTimeline } from './ChangelogTimeline'
import { ChangelogFilters } from './ChangelogFilters'

type ViewProps = {
  entries: ChangelogEntry[]
  nextPage: number | null
}

export function View({ entries, nextPage }: ViewProps) {
  const [selectedTag, setSelectedTag] = useState<ChangelogTag | null>(null)

  function handleTagSelect(tag: ChangelogTag) {
    setSelectedTag((current) => (current === tag ? null : tag))
  }

  return (
    <div className="relative bg-background">
      <MarketingHeroSection
        title="Changelog"
        description="Explore Appwrite's changelog to stay on top of all the product updates and track our journey."
        align="left"
      />

      <section className="overflow-visible border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl overflow-visible px-4 sm:px-6">
          <div className="grid grid-cols-1 items-start gap-8 overflow-visible lg:grid-cols-[280px_1fr] lg:gap-12">
            {/* Filter Sidebar */}
            <aside className="sticky top-6 z-10 hidden max-h-[calc(100dvh-3rem)] self-start overflow-y-auto overscroll-y-contain lg:block">
              <ChangelogFilters
                selectedTag={selectedTag}
                onTagSelect={handleTagSelect}
                onClearFilters={() => setSelectedTag(null)}
              />
            </aside>

            {/* Timeline */}
            <div className="min-w-0">
              <ChangelogTimeline
                initialEntries={entries}
                initialNextPage={nextPage}
                selectedTag={selectedTag}
                onClearFilter={() => setSelectedTag(null)}
              />
            </div>
          </div>
        </div>
      </section>

      <MarketingCtaSection title="Ready to build?">
        <MarketingCtaSignupButtons />
      </MarketingCtaSection>
    </div>
  )
}

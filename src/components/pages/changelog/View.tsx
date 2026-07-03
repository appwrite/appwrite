import {
  MarketingCtaSection,
  MarketingCtaSignupButtons,
  MarketingHeroSection,
} from '@/components/pages/marketing/MarketingSections'
import type { ChangelogEntry } from '@/lib/changelog/types'
import { ChangelogTimeline } from './ChangelogTimeline'

type ViewProps = {
  entries: ChangelogEntry[]
  nextPage: number | null
}

export function View({ entries, nextPage }: ViewProps) {
  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        title="Changelog"
        description="Explore Appwrite's changelog to stay on top of all the product updates and track our journey."
        align="left"
      />

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-[49.375rem] px-4 sm:px-6">
          <ChangelogTimeline
            initialEntries={entries}
            initialNextPage={nextPage}
          />
        </div>
      </section>

      <MarketingCtaSection title="Ready to build?">
        <MarketingCtaSignupButtons />
      </MarketingCtaSection>
    </div>
  )
}

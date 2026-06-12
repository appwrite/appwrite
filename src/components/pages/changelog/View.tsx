import { Link } from '@tanstack/react-router'
import {
  MarketingCtaSection,
  MarketingHeroSection,
} from '@/components/pages/marketing/MarketingSections'
import { Button } from '@/components/ui/button'
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

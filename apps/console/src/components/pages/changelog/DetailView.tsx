import { Link } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import {
  MarketingCtaSection,
  MarketingCtaSignupButtons,
} from '@/components/pages/marketing/MarketingSections'
import { Button } from '@/components/ui/button'
import { formatDate } from '@/lib/date-utils'
import type { ChangelogEntry } from '@/lib/changelog/types'
import { ChangelogMarkdown } from './ChangelogMarkdown'

type DetailViewProps = {
  entry: ChangelogEntry
}

export function DetailView({ entry }: DetailViewProps) {
  return (
    <div className="relative overflow-x-hidden bg-background">
      <section className="border-b border-border py-10 sm:py-14">
        <div className="mx-auto max-w-[42.5rem] px-4 sm:px-6">
          <Button variant="ghost" size="sm" className="h-9 px-0 text-[13px] text-muted-foreground" asChild>
            <Link to="/changelog">
              <ArrowLeft className="me-1.5 h-4 w-4" />
              Back to Changelog
            </Link>
          </Button>

          <header className="mt-8 border-y border-border py-4">
            <time
              className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground"
              dateTime={entry.date}
            >
              {formatDate(entry.date)}
            </time>
            <h1 className="mt-4 font-aeonik-pro text-[26px] font-normal leading-tight text-foreground sm:text-[30px]">
              {entry.title}
            </h1>
          </header>

          {entry.cover ? (
            <div className="mt-8 overflow-hidden rounded-xl border border-border bg-card/40">
              <img src={entry.cover} alt="" className="block w-full" loading="lazy" />
            </div>
          ) : null}

          <div className="mt-8">
            <ChangelogMarkdown content={entry.content} />
          </div>
        </div>
      </section>

      <MarketingCtaSection title="Ready to build?">
        <MarketingCtaSignupButtons />
      </MarketingCtaSection>
    </div>
  )
}

'use client'

import { ArrowRight } from 'lucide-react'
import {
  docsContentPaddingX,
  docsPreviewPrimaryTitleClass,
  docsPreviewSectionPaddingY,
} from '@/lib/docs/docs-container'
import { cn } from '@/lib/utils'
import { DocsAgentPromptBar } from './_components/agent-onboarding/DocsAgentPromptBar'
import { DocsRouteLink } from './DocsRouteLink'

export function DocsPreviewHeroSection() {
  return (
    <section
      className={cn('border-b border-border', docsPreviewSectionPaddingY)}
    >
      <div
        className={cn(
          'mx-auto w-full max-w-6xl text-start',
          docsContentPaddingX,
        )}
      >
        <h1 className={cn('max-w-[600px]', docsPreviewPrimaryTitleClass)}>
          The platform your coding agent builds on
        </h1>

        <p className="mt-3 max-w-[600px] text-[13px] leading-[1.6] text-muted-foreground @[480px]:text-[14px]">
          Connect your agent to Appwrite with one prompt, and it can add
          sign-in, a&nbsp;database, file storage, and hosting to your app with
          no servers to set up.
        </p>

        <DocsAgentPromptBar className="mt-5" size="compact" />

        <div className="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2">
          <DocsRouteLink
            href="/docs/quick-starts"
            previewView="menu"
            className="inline-flex items-center gap-1 text-[12px] text-muted-foreground transition-colors hover:text-foreground"
          >
            Framework guides
            <ArrowRight className="size-3.5" aria-hidden />
          </DocsRouteLink>
          <DocsRouteLink
            href="/docs/references"
            className="inline-flex items-center gap-1 text-[12px] text-muted-foreground transition-colors hover:text-foreground"
          >
            API references
            <ArrowRight className="size-3.5" aria-hidden />
          </DocsRouteLink>
        </div>
      </div>
    </section>
  )
}

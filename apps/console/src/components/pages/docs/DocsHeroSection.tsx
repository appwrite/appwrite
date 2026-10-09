import { ArrowRight } from 'lucide-react'
import { docsContentPaddingX } from '@/lib/docs/docs-container'
import { cn } from '@/lib/utils'
import { DocsAgentPromptBar } from './_components/agent-onboarding/DocsAgentPromptBar'
import { DocsHeroVideo } from './_components/DocsHeroVideo'
import { DocsRouteLink } from './DocsRouteLink'

export function DocsHeroSection() {
  return (
    <section className="border-b border-border bg-background">
      <div
        className={cn(
          'mx-auto w-full max-w-6xl pb-14 pt-12',
          docsContentPaddingX,
          '@[480px]:pb-16 @[480px]:pt-16 @[900px]:pb-24 @[900px]:pt-24',
        )}
      >
        <div className="mx-auto flex max-w-[720px] flex-col items-center text-center">
          <h1 className="font-aeonik-pro max-w-[640px] text-balance text-[32px] font-normal leading-[1.08] tracking-tight text-foreground @[480px]:text-[40px] @[900px]:text-[48px]">
            The platform your coding agent builds on
            <span className="text-[var(--brand-cta)]">_</span>
          </h1>

          <p className="mt-4 max-w-[600px] text-balance text-[14px] leading-7 text-muted-foreground @[480px]:mt-5 @[480px]:text-[15px]">
            Connect your agent to Appwrite with one prompt, and it can add
            sign-in, a&nbsp;database, file storage, and hosting to your app with
            no servers to set up.
          </p>

          <DocsAgentPromptBar
            placement="docs-home"
            className="mt-8 w-full text-start @[480px]:mt-10"
          />

          <p className="mt-4 text-[13px] leading-6 text-muted-foreground">
            Your agent will ask you to sign in to Appwrite in your browser.{' '}
            <DocsRouteLink
              href="/docs/quick-starts#set-up-by-hand"
              className="inline-flex items-center gap-1 text-foreground/80 transition-colors hover:text-foreground"
            >
              Set up by hand
              <ArrowRight className="size-3.5" aria-hidden />
            </DocsRouteLink>
          </p>
        </div>

        <DocsHeroVideo className="mx-auto mt-14 max-w-[960px]" />
      </div>
    </section>
  )
}

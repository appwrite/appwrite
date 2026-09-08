import type { ReactNode } from 'react'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { DocsHomeSectionHeading } from './DocsHomeSectionHeading'
import {
  docsContentPaddingX,
  docsPreviewSectionPaddingY,
  docsSectionPaddingY,
} from '@/lib/docs/docs-container'
import { cn } from '@/lib/utils'
import { DOCS_PROSE_LINK_CLASS } from '@/lib/docs/prose-link'
import { DocsRouteLink } from '../DocsRouteLink'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'

const FAQ_LINK_CLASS = DOCS_PROSE_LINK_CLASS

type DocsHubFaqItem = {
  question: string
  answer: ReactNode
}

const FAQ_ITEMS: DocsHubFaqItem[] = [
  {
    question: 'What is Appwrite?',
    answer: (
      <>
        Appwrite is an open-source backend platform: Auth, Databases, Storage, Functions,
        Realtime, Messaging, and hosting for sites. You can build against{' '}
        <a
          href="https://cloud.appwrite.io/"
          target="_blank"
          rel="noopener noreferrer"
          className={FAQ_LINK_CLASS}
        >
          Appwrite Cloud
        </a>{' '}
        or run the same stack{' '}
        <DocsRouteLink href="/docs/advanced/self-hosting" className={FAQ_LINK_CLASS}>
          self-hosted
        </DocsRouteLink>
        .
      </>
    ),
  },
  {
    question: 'What is Appwrite used for, and what does Appwrite do?',
    answer: (
      <>
        You use it as the server side for your app: user sign-in, persisted data, file uploads,
        scheduled or event-driven logic, notifications, and live updates, exposed over APIs and{' '}
        <DocsRouteLink href="/docs/sdks" className={FAQ_LINK_CLASS}>
          SDKs
        </DocsRouteLink>{' '}
        so your client or server code stays thin. Browse{' '}
        <DocsRouteLink href="/docs/products/auth" className={FAQ_LINK_CLASS}>
          products
        </DocsRouteLink>{' '}
        for what each service covers.
      </>
    ),
  },
  {
    question: 'How do I use Appwrite from React, Next.js, or another framework?',
    answer: (
      <>
        Appwrite is framework-agnostic: you call it from the browser or server with an SDK or
        plain HTTP. Pick a{' '}
        <DocsRouteLink href="/docs/quick-starts" className={FAQ_LINK_CLASS}>
          quick start
        </DocsRouteLink>{' '}
        for your stack (for example{' '}
        <DocsRouteLink href="/docs/quick-starts/react" className={FAQ_LINK_CLASS}>
          React
        </DocsRouteLink>{' '}
        or{' '}
        <DocsRouteLink href="/docs/quick-starts/nextjs" className={FAQ_LINK_CLASS}>
          Next.js
        </DocsRouteLink>
        ), or follow a full{' '}
        <DocsRouteLink href="/docs/tutorials" className={FAQ_LINK_CLASS}>
          tutorial
        </DocsRouteLink>{' '}
        if you prefer a guided build.
      </>
    ),
  },
  {
    question: 'Where should I start in the documentation?',
    answer: (
      <>
        Use{' '}
        <DocsRouteLink href="/docs/quick-starts" className={FAQ_LINK_CLASS}>
          Quick starts
        </DocsRouteLink>{' '}
        to connect a project in minutes. Use{' '}
        <DocsRouteLink href="/docs/tutorials" className={FAQ_LINK_CLASS}>
          Tutorials
        </DocsRouteLink>{' '}
        for end-to-end apps. When you need exact request shapes and types, open the{' '}
        <DocsRouteLink href="/docs/references" className={FAQ_LINK_CLASS}>
          API references
        </DocsRouteLink>{' '}
        for your SDK and runtime.
      </>
    ),
  },
  {
    question: 'Should I use Appwrite Cloud or self-host?',
    answer: (
      <>
        Appwrite Cloud is the convenient option: we run the stack, ship upgrades, and you pay a
        predictable subscription. Self-hosting suits strict regulation, full data residency,
        air-gapped networks, or when you prefer to pay with engineering time instead of a managed
        service fee, but you operate the cluster yourself: you plan{' '}
        <DocsRouteLink
          href="/docs/advanced/self-hosting/production/updates"
          className={FAQ_LINK_CLASS}
        >
          version upgrades and data migrations
        </DocsRouteLink>{' '}
        between releases (including backups and rollback), instead of Appwrite doing that for you.
        The product surface is aligned either way; see{' '}
        <DocsRouteLink href="/docs/advanced/self-hosting" className={FAQ_LINK_CLASS}>
          self-hosting
        </DocsRouteLink>{' '}
        and compare{' '}
        <DocsRouteLink href="/pricing" className={FAQ_LINK_CLASS}>
          pricing
        </DocsRouteLink>{' '}
        with your ops cost.
      </>
    ),
  },
  {
    question: 'Where can I browse the API references?',
    answer: (
      <>
        Open{' '}
        <DocsRouteLink href="/docs/references" className={FAQ_LINK_CLASS}>
          API references
        </DocsRouteLink>{' '}
        for REST payloads, GraphQL, and Realtime, organized by platform (web, mobile, server). The{' '}
        <DocsRouteLink href="/docs/sdks" className={FAQ_LINK_CLASS}>
          SDKs
        </DocsRouteLink>{' '}
        page lists official client and server libraries.
      </>
    ),
  },
  {
    question: 'Where can I get help or report a bug?',
    answer: (
      <>
        Ask the community on{' '}
        <MarketingSiteLink className={FAQ_LINK_CLASS} href="/discord">
          Discord
        </MarketingSiteLink>
        , check{' '}
        <MarketingSiteLink href="/enterprise" className={FAQ_LINK_CLASS}>
          support
        </MarketingSiteLink>{' '}
        for product help options, and use{' '}
        <a
          href="https://github.com/appwrite/appwrite/issues"
          target="_blank"
          rel="noopener noreferrer"
          className={FAQ_LINK_CLASS}
        >
          GitHub issues
        </a>{' '}
        for reproducible bugs in the open-source server.
      </>
    ),
  },
]

type DocsHubFaqProps = {
  variant?: 'page' | 'preview'
}

export function DocsHubFaq({ variant = 'page' }: DocsHubFaqProps) {
  const sectionPaddingY =
    variant === 'preview' ? docsPreviewSectionPaddingY : docsSectionPaddingY

  return (
    <section className={sectionPaddingY}>
      <div className={cn('mx-auto w-full max-w-6xl', docsContentPaddingX)}>
        <div className="grid gap-8 @[900px]:grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)] @[900px]:gap-12">
          <DocsHomeSectionHeading
            title="Common questions"
            description="Quick answers when you are new to Appwrite or deciding how to navigate these docs."
            variant={variant}
          />

          <Accordion type="single" collapsible className="w-full">
            {FAQ_ITEMS.map((item, index) => (
              <AccordionItem key={item.question} value={`item-${index}`}>
                <AccordionTrigger className="py-5 text-start hover:no-underline">
                  <span className="pe-4 text-[14px] font-medium text-foreground">
                    {item.question}
                  </span>
                </AccordionTrigger>
                <AccordionContent className="text-[13px] leading-6 text-muted-foreground">
                  {item.answer}
                </AccordionContent>
              </AccordionItem>
            ))}
          </Accordion>
        </div>
      </div>
    </section>
  )
}

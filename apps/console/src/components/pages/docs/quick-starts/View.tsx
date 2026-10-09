import { useMemo, type ReactNode } from 'react'
import { DocsLayout } from '@/components/pages/docs/DocsLayout'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import {
  DocsHubCategorySection,
  getHubCategoryTocItem,
} from '@/components/pages/docs/_components/DocsHubCategorySection'
import { DocsAgentPromptBar } from '@/components/pages/docs/_components/agent-onboarding/DocsAgentPromptBar'
import {
  DocsAgentCapabilities,
  DocsFirstPrompts,
} from '@/components/pages/docs/_components/agent-onboarding/DocsFirstPrompts'
import { DocsManualAgentSetup } from '@/components/pages/docs/_components/agent-onboarding/DocsManualAgentSetup'
import { useDocsOnboardingAgent } from '@/components/pages/docs/_components/agent-onboarding/useDocsAgentOnboarding'
import { DocsHeadingLink } from '@/components/pages/docs/markdoc/DocsHeadingLink'
import { docsGridQuickStarts } from '@/lib/docs/docs-container'
import { QUICK_STARTS_HUB_CATEGORIES } from '@/lib/docs/quick-starts-hub'
import type { DocsTocItem } from '@/lib/docs/types'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

const SECTION_HEADING_CLASS =
  'group scroll-mt-24 font-aeonik-pro text-balance text-[20px] font-normal leading-[1.3] text-foreground/95 @[640px]:text-[22px]'

const STEP_TITLE_CLASS =
  'text-[15px] font-semibold leading-snug text-foreground'

const BODY_CLASS =
  'text-[14px] leading-6 text-muted-foreground @[640px]:text-[15px] @[640px]:leading-7'

const SECTIONS = {
  agent: { id: 'set-up-with-your-agent', label: 'Set up with your agent' },
  manual: { id: 'set-up-by-hand', label: 'Set up by hand' },
  code: { id: 'write-the-code-yourself', label: 'Write the code yourself' },
} as const

function SectionHeading({ id, children }: { id: string; children: ReactNode }) {
  return (
    <h2 id={id} className={SECTION_HEADING_CLASS}>
      <DocsHeadingLink headingId={id} linkIconSizeClass="size-4">
        {children}
      </DocsHeadingLink>
    </h2>
  )
}

function Step({
  number,
  title,
  last = false,
  children,
}: {
  number: number
  title: string
  last?: boolean
  children: ReactNode
}) {
  return (
    <li className={cn('relative flex gap-4', !last && 'pb-10')}>
      {!last ? (
        <span
          className="absolute start-[13px] top-8 bottom-2 w-px bg-border"
          aria-hidden
        />
      ) : null}
      <span className="relative z-[1] flex size-7 shrink-0 items-center justify-center rounded-full border border-border bg-background font-mono text-[12px] text-muted-foreground">
        {number}
      </span>
      <div className="min-w-0 flex-1 pt-0.5">
        <h3 className={STEP_TITLE_CLASS}>{title}</h3>
        <div className="mt-3">{children}</div>
      </div>
    </li>
  )
}

export function View() {
  const { agentId, agent, setAgentId } = useDocsOnboardingAgent()

  const toc = useMemo<DocsTocItem[]>(
    () => [
      { id: SECTIONS.agent.id, label: SECTIONS.agent.label, level: 2 },
      { id: SECTIONS.manual.id, label: SECTIONS.manual.label, level: 2 },
      { id: SECTIONS.code.id, label: SECTIONS.code.label, level: 2 },
      ...QUICK_STARTS_HUB_CATEGORIES.map((category) => ({
        ...getHubCategoryTocItem(category.title),
        level: 3,
      })),
    ],
    [],
  )

  return (
    <DocsLayout
      slug="quick-starts"
      title="Quick start"
      description="Set up Appwrite from your coding agent with one prompt, or follow a framework guide and write the code yourself."
      toc={toc}
    >
      <div className="space-y-16">
        <section className="space-y-6">
          <SectionHeading id={SECTIONS.agent.id}>
            {SECTIONS.agent.label}
          </SectionHeading>
          <p className={BODY_CLASS}>
            Your agent follows a public setup guide. It connects the Appwrite
            MCP server, installs the latest CLI and the SDK that matches your
            project, and links a project. You do not need to create a project
            first.
          </p>

          <ol className="pt-2">
            <Step number={1} title="Paste this prompt into your agent">
              <DocsAgentPromptBar
                agentId={agentId}
                onAgentChange={setAgentId}
              />
            </Step>

            <Step number={2} title="Approve the sign-ins">
              <p className={BODY_CLASS}>
                The agent opens two browser windows: one for the Appwrite MCP
                server and one for{' '}
                <code className="rounded-md border border-border bg-muted/50 px-1.5 py-0.5 font-mono text-[13px] text-foreground/85">
                  appwrite login
                </code>
                . Approve both with your Appwrite account. If the agent needs a
                restart to load the MCP server, it tells you, and you say{' '}
                <em>continue</em> when you are back.
              </p>
            </Step>

            <Step number={3} title="Ask for your first feature" last>
              <p className={cn(BODY_CLASS, 'mb-4')}>
                When setup is done, open a new chat so your agent can use
                Appwrite MCP. Then pick a prompt below or describe what you want
                to build.
              </p>
              <DocsFirstPrompts />
            </Step>
          </ol>

          <div className="space-y-3">
            <p className={BODY_CLASS}>
              Setup gives your agent these tools. Each one has its own docs.
            </p>
            <DocsAgentCapabilities />
          </div>
        </section>

        <section className="space-y-5">
          <SectionHeading id={SECTIONS.manual.id}>
            {SECTIONS.manual.label}
          </SectionHeading>
          <p className={BODY_CLASS}>
            Prefer to connect your agent yourself? These are the same steps the
            prompt runs, for each agent.
          </p>
          <DocsManualAgentSetup
            agentId={agentId}
            agent={agent}
            onAgentChange={setAgentId}
          />
          <p className="text-[13px] leading-6 text-muted-foreground">
            Running Appwrite on your own servers? Use the{' '}
            <DocsRouteLink
              href="/docs/advanced/self-hosting/mcp"
              className="text-foreground underline decoration-border underline-offset-4 hover:decoration-foreground"
            >
              self-hosted MCP server
            </DocsRouteLink>{' '}
            with a project API key.
          </p>
        </section>

        <section className="space-y-10">
          <div className="space-y-5">
            <SectionHeading id={SECTIONS.code.id}>
              {SECTIONS.code.label}
            </SectionHeading>
            <p className={BODY_CLASS}>
              Each guide creates a project in the Console and walks through the
              SDK setup for one framework. Sign in and choose a project on any
              code sample to fill in your project ID and endpoint.
            </p>
          </div>
          {QUICK_STARTS_HUB_CATEGORIES.map((category) => (
            <DocsHubCategorySection key={category.title} title={category.title}>
              <ul className={cn('grid gap-3', docsGridQuickStarts)}>
                {category.items.map((item) => (
                  <li key={item.href}>
                    <DocsRouteLink
                      href={item.href}
                      className={cn(
                        'flex h-full items-center gap-3 rounded-xl border border-border bg-card/45 px-4 py-3',
                        'transition-colors hover:bg-accent/15',
                      )}
                    >
                      <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40">
                        <img
                          src={item.iconSrc}
                          alt=""
                          className={cn('size-4', PUBLIC_ICON_MUTED_CLASSES)}
                        />
                      </span>
                      <span className="text-[13px] font-medium text-foreground">
                        {item.title}
                      </span>
                    </DocsRouteLink>
                  </li>
                ))}
              </ul>
            </DocsHubCategorySection>
          ))}
        </section>
      </div>
    </DocsLayout>
  )
}

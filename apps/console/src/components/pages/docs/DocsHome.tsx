import { DocsHomeSectionHeading } from './_components/DocsHomeSectionHeading'
import {
  DOCS_HOME_INTEGRATIONS,
  DOCS_HOME_INTEGRATION_ICONS,
  DOCS_HOME_MIGRATIONS,
  DOCS_HOME_MIGRATION_ICONS,
} from '@/lib/docs/home-content'
import {
  docsContentPaddingX,
  docsGridFiveCol,
  docsGridQuickStarts,
  docsGridTwoCol,
  docsPreviewSectionPaddingY,
  docsSectionPaddingY,
} from '@/lib/docs/docs-container'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { ArrowRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { DOCS_FRAMEWORK_STRIP } from '@/lib/docs/framework-strip'
import {
  DocsAgentCapabilities,
  DocsFirstPrompts,
} from './_components/agent-onboarding/DocsFirstPrompts'
import { DocsHubFaq } from './_components/DocsHubFaq'
import { DocsProductsBento } from './_components/DocsProductsBento'
import { DocsTutorialsScroll } from './_components/DocsTutorialsScroll'
import { DocsHeroSection } from './DocsHeroSection'
import { DocsPreviewHeroSection } from './DocsPreviewHeroSection'
import { DocsRouteLink } from './DocsRouteLink'

const TEXT_CARD_CLASS =
  'group block h-full rounded-xl border border-border bg-card/45 p-5 transition-colors hover:bg-accent/15'

type DocsHomeSectionProps = {
  title: string
  description?: React.ReactNode
  children: React.ReactNode
  className?: string
  variant?: 'page' | 'preview'
}

function DocsHomeSection({
  title,
  description,
  children,
  className,
  variant = 'page',
}: DocsHomeSectionProps) {
  const sectionPaddingY =
    variant === 'preview' ? docsPreviewSectionPaddingY : docsSectionPaddingY

  return (
    <section
      className={cn('border-b border-border', sectionPaddingY, className)}
    >
      <div className={cn('mx-auto w-full max-w-6xl', docsContentPaddingX)}>
        <DocsHomeSectionHeading
          title={title}
          description={description}
          variant={variant}
        />
        <div className="mt-8">{children}</div>
      </div>
    </section>
  )
}

function IntegrationIcon({
  title,
  iconSrc,
}: {
  title: string
  iconSrc?: string
}) {
  if (iconSrc) {
    return (
      <span className="flex size-8 items-center justify-center rounded-lg border border-border bg-muted/40">
        <img
          src={iconSrc}
          alt=""
          className={cn('size-4', PUBLIC_ICON_MUTED_CLASSES)}
        />
      </span>
    )
  }

  const Icon =
    title === 'SDKs'
      ? DOCS_HOME_INTEGRATION_ICONS.sdks
      : title === 'REST API'
        ? DOCS_HOME_INTEGRATION_ICONS.rest
        : DOCS_HOME_INTEGRATION_ICONS.realtime

  return (
    <span className="flex size-8 items-center justify-center rounded-lg border border-border bg-muted/40">
      <Icon className="size-3.5 text-muted-foreground" aria-hidden />
    </span>
  )
}

function MigrationIcon({
  title,
  iconSrc,
}: {
  title: string
  iconSrc?: string
}) {
  if (iconSrc) {
    return (
      <img
        src={iconSrc}
        alt=""
        className={cn(
          'shrink-0',
          title === 'Supabase' ? 'size-[17px]' : 'size-5',
          PUBLIC_ICON_MUTED_CLASSES,
        )}
      />
    )
  }

  const iconKey = title === 'Self-hosted' ? 'self-hosted' : null

  if (!iconKey) return null

  const Icon = DOCS_HOME_MIGRATION_ICONS[iconKey]
  return <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
}

type DocsHomeProps = {
  variant?: 'page' | 'preview'
}

const HOME_FRAMEWORK_COUNT = 12

/** Quick start pages that are still "contribute this guide" stubs. */
const STUB_FRAMEWORK_GUIDES = new Set([
  '/docs/quick-starts/astro',
  '/docs/quick-starts/qwik',
])

function DocsHomeSubheading({ children }: { children: React.ReactNode }) {
  return (
    <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
      {children}
    </p>
  )
}

function DocsHomeFrameworkGrid() {
  return (
    <ul className={cn('grid gap-3', docsGridQuickStarts)}>
      {DOCS_FRAMEWORK_STRIP.filter(
        (tool) => !STUB_FRAMEWORK_GUIDES.has(tool.href),
      )
        .slice(0, HOME_FRAMEWORK_COUNT)
        .map((tool) => (
          <li key={tool.href}>
            <DocsRouteLink
              href={tool.href}
              className="flex h-full items-center gap-3 rounded-xl border border-border bg-card/45 px-4 py-3 transition-colors hover:bg-accent/15"
            >
              <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40">
                <img
                  src={tool.iconSrc}
                  alt=""
                  className={cn('size-4', PUBLIC_ICON_MUTED_CLASSES)}
                />
              </span>
              <span className="text-[13px] font-medium text-foreground">
                {tool.name}
              </span>
            </DocsRouteLink>
          </li>
        ))}
    </ul>
  )
}

export function DocsHome({ variant = 'page' }: DocsHomeProps) {
  return (
    <>
      {variant === 'preview' ? <DocsPreviewHeroSection /> : <DocsHeroSection />}

      <DocsHomeSection
        variant={variant}
        title="Then ask for what you want to build"
        description="When setup is done, open a new chat so your agent can use Appwrite MCP. Then pick a prompt below or describe what you want to build."
      >
        <DocsFirstPrompts />
        <div className="mt-10">
          <DocsHomeSubheading>What your agent can use</DocsHomeSubheading>
          <DocsAgentCapabilities className="mt-4" />
        </div>
      </DocsHomeSection>

      <DocsHomeSection
        variant={variant}
        title="Explore capabilities"
        description="All the core functionalities you need with a scalable and flexible API. Explore Appwrite's product offerings."
      >
        <DocsProductsBento />
      </DocsHomeSection>

      <DocsHomeSection
        variant={variant}
        title="Prefer to write the code yourself?"
        description="Follow a framework guide to add the SDK step by step, or build a complete app in a tutorial."
      >
        <DocsHomeSubheading>Framework guides</DocsHomeSubheading>
        <div className="mt-4">
          <DocsHomeFrameworkGrid />
        </div>
        <div className="mt-5">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            asChild
          >
            <DocsRouteLink
              href="/docs/quick-starts#write-the-code-yourself"
              {...analyticsAttrs('docs-all-quick-starts')}
            >
              All framework guides
              <ArrowRight className="ms-1.5 size-4" aria-hidden />
            </DocsRouteLink>
          </Button>
        </div>
        <div className="mt-12">
          <DocsHomeSubheading>Tutorials</DocsHomeSubheading>
          <div className="mt-4">
            <DocsTutorialsScroll />
          </div>
        </div>
      </DocsHomeSection>

      <DocsHomeSection
        variant={variant}
        title="Explore ways to integrate"
        description="Choose how you integrate with Appwrite. Explore references for the Appwrite SDK, REST API, GraphQL API, or Realtime API."
      >
        <div className={cn('grid gap-4', docsGridTwoCol)}>
          {DOCS_HOME_INTEGRATIONS.map((item) => (
            <DocsRouteLink
              key={item.href}
              href={item.href}
              className={TEXT_CARD_CLASS}
            >
              <div className="flex items-start gap-3">
                <IntegrationIcon title={item.title} iconSrc={item.iconSrc} />
                <div>
                  <h3 className="text-[13px] font-medium text-foreground">
                    {item.title}
                  </h3>
                  <p className="mt-2 text-[13px] leading-5 text-muted-foreground">
                    {item.description}
                  </p>
                </div>
              </div>
            </DocsRouteLink>
          ))}
        </div>
      </DocsHomeSection>

      <DocsHomeSection
        variant={variant}
        title="Migrate to Appwrite"
        description="Own your data with automatic data migrations."
      >
        <div className={cn('grid gap-4', docsGridFiveCol)}>
          {DOCS_HOME_MIGRATIONS.map((item) => (
            <DocsRouteLink
              key={item.href}
              href={item.href}
              className={TEXT_CARD_CLASS}
            >
              <div className="flex items-center gap-2">
                <MigrationIcon title={item.title} iconSrc={item.iconSrc} />
                <h3 className="text-[13px] font-medium text-foreground">
                  {item.title}
                </h3>
              </div>
              <p className="mt-2 text-[13px] leading-5 text-muted-foreground">
                {item.description}
              </p>
            </DocsRouteLink>
          ))}
        </div>
      </DocsHomeSection>

      <DocsHubFaq variant={variant} />
    </>
  )
}

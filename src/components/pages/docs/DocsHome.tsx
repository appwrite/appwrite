import { MarketingSectionHeading } from '@/components/pages/marketing/MarketingSections'
import {
  DOCS_HOME_INTEGRATIONS,
  DOCS_HOME_INTEGRATION_ICONS,
  DOCS_HOME_MIGRATIONS,
  DOCS_HOME_MIGRATION_ICONS,
} from '@/lib/docs/home-content'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { DocsAiSection } from './_components/DocsAiSection'
import { DocsHubFaq } from './_components/DocsHubFaq'
import { DocsProductsBento } from './_components/DocsProductsBento'
import { DocsTutorialsScroll } from './_components/DocsTutorialsScroll'
import { DocsHeroSection } from './DocsHeroSection'
import { DocsRouteLink } from './DocsRouteLink'

const TEXT_CARD_CLASS =
  'group block h-full rounded-xl border border-border bg-card/45 p-5 transition-colors hover:bg-accent/15'

type DocsHomeSectionProps = {
  title: string
  description?: React.ReactNode
  children: React.ReactNode
  className?: string
}

function DocsHomeSection({
  title,
  description,
  children,
  className,
}: DocsHomeSectionProps) {
  return (
    <section className={cn('border-b border-border py-12 sm:py-16', className)}>
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <MarketingSectionHeading
          align="left"
          size="md"
          title={title}
          description={typeof description === 'string' ? description : undefined}
        />
        {typeof description !== 'string' && description ? (
          <div className="mt-4 max-w-3xl text-[14px] leading-7 text-muted-foreground">
            {description}
          </div>
        ) : null}
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

function MigrationIcon({ title, iconSrc }: { title: string; iconSrc?: string }) {
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

export function DocsHome() {
  return (
    <>
      <DocsHeroSection />

      <DocsHomeSection
        title="Explore capabilities"
        description="All the core functionalities you need with a scalable and flexible API. Explore Appwrite's product offerings."
      >
        <DocsProductsBento />
      </DocsHomeSection>

      <DocsAiSection />

      <DocsHomeSection
        title="Explore ways to integrate"
        description="Choose how you integrate with Appwrite. Explore references for the Appwrite SDK, REST API, GraphQL API, or Realtime API."
      >
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {DOCS_HOME_INTEGRATIONS.map((item) => (
            <DocsRouteLink key={item.href} href={item.href} className={TEXT_CARD_CLASS}>
              <div className="flex items-start gap-3">
                <IntegrationIcon title={item.title} iconSrc={item.iconSrc} />
                <div>
                  <h3 className="text-[14px] font-semibold text-foreground">
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
        title="Show me some code"
        description="If you learn best from code examples, follow one of our tutorials."
      >
        <DocsTutorialsScroll />
      </DocsHomeSection>

      <DocsHomeSection
        title="Migrate to Appwrite"
        description="Own your data with automatic data migrations."
      >
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
          {DOCS_HOME_MIGRATIONS.map((item) => (
            <DocsRouteLink key={item.href} href={item.href} className={TEXT_CARD_CLASS}>
              <div className="flex items-center gap-2">
                <MigrationIcon title={item.title} iconSrc={item.iconSrc} />
                <h3 className="text-[14px] font-semibold text-foreground">
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

      <DocsHubFaq />
    </>
  )
}

import type { ComponentType, ReactNode } from 'react'
import {
  Braces,
  Command,
  Layers,
  Radio,
  Sparkles,
  Terminal,
  ArrowDownLeft,
  ArrowUpRight,
} from 'lucide-react'
import {
  REALTIME_INCOMING_ICON_CLASS,
  REALTIME_OUTGOING_ICON_CLASS,
} from '@/lib/realtime/message-direction-styles'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { TerraformIcon } from '@/components/global/shared/TerraformIcon'
import { MarketingSectionHeading } from '@/components/pages/marketing/MarketingSections'
import {
  getProductToolsContent,
  PRODUCT_TOOLS_LINKS,
  PRODUCT_TOOLS_SDK_PLATFORMS,
  PRODUCT_TOOLS_TOTAL_SDK_COUNT,
} from '@/lib/products/tools-section'
import type { ProductId } from '@/lib/products/types'
import { cn } from '@/lib/utils'
import { useI18n } from '@/lib/i18n'

type ProductToolsSectionProps = {
  productId: ProductId
}

function ToolsTileLink({
  href,
  children,
  className,
}: {
  href: string
  children: ReactNode
  className?: string
}) {
  return (
    <a
      href={href}
      className={cn(
        'group block h-full p-4 transition-colors hover:bg-accent/15 sm:p-5',
        className,
      )}
    >
      {children}
    </a>
  )
}

function ToolsTileIcon({
  icon: Icon,
  children,
}: {
  icon?: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>
  children?: ReactNode
}) {
  return (
    <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
      {Icon ? (
        <Icon className="size-3.5 text-muted-foreground" aria-hidden />
      ) : (
        children
      )}
    </span>
  )
}

function ToolsTileTitle({ children }: { children: ReactNode }) {
  return (
    <h3 className="mt-3 text-[14px] font-semibold text-foreground group-hover:text-foreground">
      {children}
    </h3>
  )
}

function ToolsTileDescription({ children }: { children: ReactNode }) {
  return (
    <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">{children}</p>
  )
}

function ToolsTileSnippet({ children }: { children: ReactNode }) {
  return (
    <pre className="mt-2.5 overflow-x-auto rounded-md border border-border bg-muted/20 px-3 py-2 font-mono text-[11px] leading-5 text-foreground">
      <code>{children}</code>
    </pre>
  )
}

function CompactRealtimePreview({
  channel,
  event,
}: {
  channel: string
  event: string
}) {
  return (
    <div className="mt-2.5 space-y-1.5 rounded-md border border-border bg-muted/20 px-3 py-2">
      <p className="flex min-w-0 items-center gap-1.5 font-mono text-[11px] leading-4 text-foreground/90">
        <ArrowUpRight
          className={cn('size-3 shrink-0', REALTIME_OUTGOING_ICON_CLASS)}
          aria-hidden
        />
        <span className="truncate">{channel}</span>
      </p>
      <p className="flex min-w-0 items-center gap-1.5 font-mono text-[11px] leading-4 text-muted-foreground">
        <ArrowDownLeft
          className={cn('size-3 shrink-0', REALTIME_INCOMING_ICON_CLASS)}
          aria-hidden
        />
        <span className="truncate">{event}</span>
      </p>
    </div>
  )
}

export function ProductToolsSection({ productId }: ProductToolsSectionProps) {
  const { catalog } = useI18n()
  const toolsCopy = catalog.website.products.tools
  const toolsContent = getProductToolsContent(productId)
  if (!toolsContent) return null

  const { codeExample, realtime, terraform } = toolsContent

  return (
    <section className="border-b border-border py-16 sm:py-20">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <MarketingSectionHeading
          title={toolsCopy.headingTitle}
          description={toolsCopy.headingDescription}
          size="md"
        />

        <div className="mt-10 grid overflow-hidden rounded-xl border border-border bg-card/45 sm:grid-cols-2 lg:grid-cols-4">
          <div className="h-full min-h-[280px] border-b border-border sm:col-span-2 lg:col-span-2 lg:row-span-2 lg:col-start-1 lg:row-start-1 lg:border-b lg:border-e">
            <div className="flex h-full min-h-[280px] flex-col lg:min-h-0">
              <div className="shrink-0 border-b border-border bg-muted/15 px-4 py-2.5">
                <p className="text-[13px] font-semibold text-foreground">
                  {toolsCopy.developerExperienceTitle}
                </p>
                <p className="mt-0.5 text-[12px] leading-5 text-muted-foreground">
                  {codeExample.caption ?? toolsCopy.developerExperienceFallbackCaption}
                </p>
              </div>
              <ConnectCodeExample
                code={codeExample.code}
                language={codeExample.language}
                className="min-h-0 flex-1 rounded-none border-0"
                fixedHeight="100%"
              />
            </div>
          </div>

          <ToolsTileLink
            href={PRODUCT_TOOLS_LINKS.api}
            className="border-b border-border sm:border-e lg:col-start-3 lg:row-start-1 lg:border-b lg:border-e"
          >
            <ToolsTileIcon icon={Braces} />
            <ToolsTileTitle>{toolsCopy.everythingApiTitle}</ToolsTileTitle>
            <ToolsTileDescription>{toolsCopy.everythingApiDescription}</ToolsTileDescription>
          </ToolsTileLink>

          <ToolsTileLink
            href={PRODUCT_TOOLS_LINKS.console}
            className="border-b border-border lg:col-start-4 lg:row-start-1 lg:border-b"
          >
            <ToolsTileIcon icon={Command} />
            <ToolsTileTitle>{toolsCopy.consoleTitle}</ToolsTileTitle>
            <ToolsTileDescription>{toolsCopy.consoleDescription}</ToolsTileDescription>
            <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
              <span dir="ltr" className="flex items-center gap-1.5">
                <kbd className="inline-flex h-6 min-w-6 items-center justify-center rounded border border-border bg-muted/50 px-1.5 font-mono text-[11px] text-muted-foreground">
                  ⌘
                </kbd>
                <kbd className="inline-flex h-6 min-w-6 items-center justify-center rounded border border-border bg-muted/50 px-1.5 font-mono text-[11px] text-muted-foreground">
                  K
                </kbd>
              </span>
              <span className="text-[11px] text-muted-foreground">
                {toolsCopy.commandCenter}
              </span>
            </div>
          </ToolsTileLink>

          <ToolsTileLink
            href={PRODUCT_TOOLS_LINKS.realtime}
            className="border-b border-border sm:border-e lg:col-start-3 lg:row-start-2 lg:border-b lg:border-e"
          >
            <ToolsTileIcon icon={Radio} />
            <ToolsTileTitle>{toolsCopy.realtimeTitle}</ToolsTileTitle>
            <ToolsTileDescription>{toolsCopy.realtimeDescription}</ToolsTileDescription>
            <CompactRealtimePreview
              channel={realtime.channels[0] ?? ''}
              event={realtime.events[0] ?? ''}
            />
          </ToolsTileLink>

          <ToolsTileLink
            href={PRODUCT_TOOLS_LINKS.mcp}
            className="border-b border-border lg:col-start-4 lg:row-start-2 lg:border-b"
          >
            <ToolsTileIcon>
              <McpIcon className="size-3.5 text-muted-foreground" />
            </ToolsTileIcon>
            <ToolsTileTitle>{toolsCopy.mcpTitle}</ToolsTileTitle>
            <ToolsTileDescription>{toolsCopy.mcpDescription}</ToolsTileDescription>
            <ToolsTileSnippet>{`appwrite-docs\nappwrite-api`}</ToolsTileSnippet>
          </ToolsTileLink>

          <ToolsTileLink
            href={terraform.href}
            className="border-b border-border sm:border-e lg:col-start-1 lg:row-start-3 lg:border-b-0 lg:border-e"
          >
            <ToolsTileIcon>
              <TerraformIcon variant="nav" className="size-3.5 text-muted-foreground" />
            </ToolsTileIcon>
            <ToolsTileTitle>{toolsCopy.terraformTitle}</ToolsTileTitle>
            <ToolsTileDescription>{toolsCopy.terraformDescription}</ToolsTileDescription>
            <ToolsTileSnippet>{`terraform plan\nterraform apply`}</ToolsTileSnippet>
          </ToolsTileLink>

          <ToolsTileLink
            href={PRODUCT_TOOLS_LINKS.skills}
            className="border-b border-border sm:border-e lg:col-start-2 lg:row-start-3 lg:border-b-0 lg:border-e"
          >
            <ToolsTileIcon icon={Sparkles} />
            <ToolsTileTitle>{toolsCopy.agentSkillsTitle}</ToolsTileTitle>
            <ToolsTileDescription>{toolsCopy.agentSkillsDescription}</ToolsTileDescription>
            <ToolsTileSnippet>npx skills add appwrite/skills</ToolsTileSnippet>
          </ToolsTileLink>

          <ToolsTileLink
            href={PRODUCT_TOOLS_LINKS.sdks}
            className="border-b border-border sm:border-e lg:col-start-3 lg:row-start-3 lg:border-b-0 lg:border-e"
          >
            <ToolsTileIcon icon={Layers} />
            <ToolsTileTitle>{toolsCopy.sdksTitle}</ToolsTileTitle>
            <ToolsTileDescription>
              {toolsCopy.sdksDescriptionPrefix}{' '}
              {PRODUCT_TOOLS_TOTAL_SDK_COUNT} {toolsCopy.sdksDescriptionSuffix}
            </ToolsTileDescription>
            <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
              {PRODUCT_TOOLS_SDK_PLATFORMS.map((platform) => (
                <span
                  key={platform}
                  className="flex size-9 items-center justify-center rounded-md border border-border bg-background"
                  title={platform}
                >
                  <PlatformIcon platform={platform} size="sm" />
                </span>
              ))}
            </div>
          </ToolsTileLink>

          <ToolsTileLink
            href={PRODUCT_TOOLS_LINKS.cli}
            className="border-b border-border lg:col-start-4 lg:row-start-3 lg:border-b-0"
          >
            <ToolsTileIcon icon={Terminal} />
            <ToolsTileTitle>{toolsCopy.cliTitle}</ToolsTileTitle>
            <ToolsTileDescription>{toolsCopy.cliDescription}</ToolsTileDescription>
            <ToolsTileSnippet>{`appwrite login\nappwrite deploy`}</ToolsTileSnippet>
          </ToolsTileLink>
        </div>
      </div>
    </section>
  )
}

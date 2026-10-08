import { useMemo, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { cn } from '@/lib/utils'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import {
  ANALYTICS_PLATFORMS,
  ANALYTICS_PLATFORM_META,
  buildAnalyticsInstallGuide,
  type AnalyticsPlatform,
} from '@/lib/analytics-wizard/snippets'

interface IntegrationSnippetsProps {
  projectId: string
  property: Models.AnalyticsProperty
  /** Controlled platform. Omit to let the component own the tab state. */
  platform?: AnalyticsPlatform
  onPlatformChange?: (platform: AnalyticsPlatform) => void
  className?: string
}

/**
 * Per-platform tracking setup for a property. Shared by the add wizard and the
 * property settings tab so both surfaces stay in step.
 */
export function IntegrationSnippets({
  projectId,
  property,
  platform,
  onPlatformChange,
  className,
}: IntegrationSnippetsProps) {
  const t = useT()
  const [localPlatform, setLocalPlatform] = useState<AnalyticsPlatform>('web')
  const active = platform ?? localPlatform

  const setActive = (next: AnalyticsPlatform) => {
    setLocalPlatform(next)
    onPlatformChange?.(next)
  }

  // Same two blocks as the Install modal: install command, then all the code.
  const blocks = useMemo(() => {
    const guide = buildAnalyticsInstallGuide(active, {
      endpoint: getProjectApiEndpoint(projectId),
      projectId,
      // The ingestion endpoint takes either ID; the snippet ID is the one
      // meant to be embedded in client-side code.
      trackingId: property.snippetId || property.$id,
      domain: property.domain,
    })
    return [
      ...(guide.install ? [{ ...guide.install, label: 'Installation' }] : []),
      {
        ...guide.code,
        label: guide.install ? 'Add to your app' : 'Send events',
      },
    ]
  }, [active, projectId, property])

  const meta = ANALYTICS_PLATFORM_META[active]

  return (
    <div className={cn('space-y-4', className)}>
      <Tabs
        value={active}
        onValueChange={(v) => setActive(v as AnalyticsPlatform)}
      >
        <TabsList>
          {ANALYTICS_PLATFORMS.map((id) => (
            <TabsTrigger key={id} value={id}>
              {t(ANALYTICS_PLATFORM_META[id].label)}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      <div className="space-y-4">
        {blocks.map((block) => (
          <div key={block.label} className="space-y-2">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
              {t(block.label)}
            </p>
            <CodeBlock code={block.code} language={block.language} copyInside />
          </div>
        ))}
      </div>

      <p className="text-[12px] leading-relaxed text-muted-foreground">
        {t(meta.note)}
      </p>
    </div>
  )
}

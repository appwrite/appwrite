import { useMemo, useState } from 'react'
import { AlertTriangle } from 'lucide-react'
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

      {meta.unreleased && (
        <div className="flex gap-2.5 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5">
          <AlertTriangle
            className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400"
            aria-hidden
          />
          <p className="text-[12px] leading-relaxed text-amber-900 dark:text-amber-200">
            {t(
              'These helpers are not published yet. They arrive in an upcoming SDK release, and the API surface may still change, including the analytics.event() call inside the emitter, which the client SDKs have not generated yet. This code will not resolve if you copy it today. Use the REST tab to start sending events now.',
            )}
          </p>
        </div>
      )}

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

import { useEffect, useMemo, useState } from 'react'
import { AlertTriangle, Braces, Check, Loader2 } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'
import { useAnalyticsFirstEvent } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  ANALYTICS_PLATFORMS,
  ANALYTICS_PLATFORM_META,
  buildAnalyticsInstallGuide,
  type AnalyticsPlatform,
} from '@/lib/analytics-wizard/snippets'

function PlatformGlyph({ platform }: { platform: AnalyticsPlatform }) {
  // REST has no platform logo; a neutral glyph reads better than the JS one.
  return platform === 'rest' ? (
    <Braces className="h-3.5 w-3.5" />
  ) : (
    <PlatformIcon platform={ANALYTICS_PLATFORM_META[platform].iconSlug} size="sm" />
  )
}

/**
 * Install instructions for a property, in the shape of the project Connect
 * modal: platform tabs along the top, steps on the left, the code for the
 * selected step on the right. Used from the property header ("Install") and
 * the settings tab, so the instructions live in one place.
 */
export function InstallTrackingDialog({
  open,
  onOpenChange,
  projectId,
  property,
  initialPlatform = 'web',
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  property: Models.AnalyticsProperty
  initialPlatform?: AnalyticsPlatform
}) {
  const t = useT()
  const [platform, setPlatform] = useState<AnalyticsPlatform>(initialPlatform)

  // Each open starts from the requested platform.
  useEffect(() => {
    if (open) setPlatform(initialPlatform)
  }, [open, initialPlatform])

  // Two blocks: the install command, then all the code in one snippet.
  const guide = useMemo(
    () =>
      buildAnalyticsInstallGuide(platform, {
        endpoint: getProjectApiEndpoint(projectId),
        projectId,
        // Ingestion takes either ID; the snippet ID is meant for client code.
        trackingId: property.snippetId || property.$id,
        domain: property.domain,
      }),
    [platform, projectId, property],
  )
  const meta = ANALYTICS_PLATFORM_META[platform]

  // Live confirmation while the dialog is open: polls until an event lands.
  const { eventReceived, firstEventName } = useAnalyticsFirstEvent(
    projectId,
    property.$id,
    open,
  )

  const selectPlatform = (next: AnalyticsPlatform) => setPlatform(next)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex h-[min(80dvh,720px)] max-h-[80dvh] flex-col gap-0 overflow-hidden p-0 sm:max-w-5xl">
        <DialogHeader className="shrink-0 px-6 pb-4 pt-6 text-start">
          <DialogTitle>{t('Install tracking')}</DialogTitle>
          <DialogDescription className="text-[13px]">
            {t('Add tracking to your app to start collecting visitors and events.')}
          </DialogDescription>
        </DialogHeader>

        {/* Platform tabs: underline style, as in the Connect modal. */}
        <div
          role="tablist"
          aria-label={t('Platform')}
          className="flex shrink-0 gap-0 overflow-x-auto border-b border-border px-6"
        >
          {ANALYTICS_PLATFORMS.map((id) => {
            const isActive = platform === id
            return (
              <button
                key={id}
                type="button"
                role="tab"
                aria-selected={isActive}
                onClick={() => selectPlatform(id)}
                className={cn(
                  'relative flex shrink-0 cursor-pointer items-center gap-1.5 rounded-sm px-3 py-2.5 text-[13px] font-medium transition-colors',
                  'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                  isActive
                    ? 'text-foreground'
                    : 'text-muted-foreground hover:text-foreground/80',
                )}
              >
                <PlatformGlyph platform={id} />
                {t(ANALYTICS_PLATFORM_META[id].label)}
                {isActive ? (
                  <span className="absolute inset-x-0 bottom-0 h-[2px] bg-foreground" />
                ) : null}
              </button>
            )
          })}
        </div>

        <div className="grid min-h-0 flex-1 gap-6 overflow-y-auto px-6 py-5 md:grid-cols-[0.8fr_1.6fr] md:overflow-hidden">
          {/* Left: what the code does, caveats, IDs and live status. */}
          <div className="flex min-h-0 min-w-0 flex-col gap-5 md:overflow-y-auto">
            <div>
              <h4 className="text-[13px] font-semibold text-foreground">
                {t('What the code does')}
              </h4>
              <ul className="mt-2 space-y-1.5">
                {guide.parts.map((part) => (
                  <li
                    key={part}
                    className="flex items-center gap-2 text-[13px] text-muted-foreground"
                  >
                    <Check className="h-3.5 w-3.5 shrink-0 text-muted-foreground/70" />
                    {t(part)}
                  </li>
                ))}
              </ul>
            </div>

            {meta.unreleased ? (
              <div className="flex gap-2.5 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5">
                <AlertTriangle
                  className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400"
                  aria-hidden
                />
                <p className="text-[12px] leading-relaxed text-amber-900 dark:text-amber-200">
                  {t(
                    'This SDK is not published yet, so the code will not resolve today. Use the REST tab to start sending events now.',
                  )}
                </p>
              </div>
            ) : null}

            <p className="text-[12px] leading-relaxed text-muted-foreground">
              {t(meta.note)}
            </p>

            <div className="mt-auto space-y-3 border-t border-border pt-4">
              <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-[12px] text-muted-foreground">
                <span className="inline-flex items-center gap-2">
                  {t('Property ID')}
                  <CopyableId id={property.$id} size="xs" />
                </span>
                {property.snippetId ? (
                  <span className="inline-flex items-center gap-2">
                    {t('Snippet ID')}
                    <CopyableId id={property.snippetId} size="xs" />
                  </span>
                ) : null}
              </div>
              <div
                aria-live="polite"
                className={cn(
                  'flex items-center gap-2 rounded-lg border px-3 py-2 text-[12px]',
                  eventReceived
                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                    : 'border-border bg-muted/30 text-muted-foreground',
                )}
              >
                {eventReceived ? (
                  <Check className="h-3.5 w-3.5 shrink-0" />
                ) : (
                  <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin" />
                )}
                <span className={cn(eventReceived && 'font-medium')}>
                  {eventReceived
                    ? firstEventName
                      ? `${t('Receiving events')}: ${firstEventName}`
                      : t('Receiving events')
                    : t('Waiting for the first event…')}
                </span>
              </div>
            </div>
          </div>

          {/* Right: install command, then all the code as one copyable file. */}
          <div className="flex min-h-[360px] min-w-0 flex-col gap-4 md:min-h-0">
            {guide.install ? (
              <div className="shrink-0 space-y-2">
                <h4 className="text-[13px] font-semibold text-foreground">
                  {t('Installation')}
                </h4>
                <CodeBlock
                  code={guide.install.code}
                  language={guide.install.language}
                  showCopy
                />
              </div>
            ) : null}
            <div className="flex min-h-0 flex-1 flex-col gap-2">
              <h4 className="shrink-0 text-[13px] font-semibold text-foreground">
                {guide.install ? t('Add to your app') : t('Send events')}
              </h4>
              <CodeBlock
                code={guide.code.code}
                language={guide.code.language}
                showCopy
                fixedHeight="100%"
                className="flex min-h-0 w-full flex-1 flex-col [&>div:last-child]:min-h-0 [&>div:last-child]:flex-1"
              />
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}

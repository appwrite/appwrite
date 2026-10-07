import { useEffect, useMemo, useState } from 'react'
import { AlertTriangle, Braces, Check, Sparkles } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
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
  buildAnalyticsSetupPrompt,
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
 * Live setup status: a radar that pulses while we listen for the first event,
 * then settles into a solid green check once one lands.
 */
function SetupStatus({
  eventReceived,
  eventName,
}: {
  eventReceived: boolean
  eventName?: string | null
}) {
  const t = useT()
  return (
    <div
      aria-live="polite"
      className={cn(
        'relative flex items-center gap-3.5 overflow-hidden rounded-xl border px-4 py-3.5 transition-colors duration-500',
        eventReceived
          ? 'border-emerald-500/30 bg-emerald-500/[0.07]'
          : 'border-border bg-muted/20',
      )}
    >
      {/* Indicator */}
      <span className="relative flex h-9 w-9 shrink-0 items-center justify-center">
        {eventReceived ? (
          <>
            <span className="absolute inset-0 rounded-full bg-emerald-500/15" />
            <span className="relative flex h-6 w-6 items-center justify-center rounded-full bg-emerald-500 text-white shadow-[0_0_12px_rgba(16,185,129,0.45)] animate-in zoom-in-50 duration-300">
              <Check className="h-3.5 w-3.5" strokeWidth={3} />
            </span>
          </>
        ) : (
          <>
            {/* Two staggered rings: a slow "listening" sweep. */}
            <span className="absolute inset-0 rounded-full border border-muted-foreground/30 animate-ping [animation-duration:2s] motion-reduce:animate-none" />
            <span className="absolute inset-1.5 rounded-full border border-muted-foreground/30 animate-ping [animation-delay:0.6s] [animation-duration:2s] motion-reduce:animate-none" />
            <span className="relative h-2.5 w-2.5 rounded-full bg-muted-foreground/70" />
          </>
        )}
      </span>

      <div className="min-w-0">
        <p
          className={cn(
            'text-[13px] font-semibold',
            eventReceived
              ? 'text-emerald-700 dark:text-emerald-400'
              : 'text-foreground',
          )}
        >
          {eventReceived ? t('Tracking is live') : t('Listening for events')}
        </p>
        <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
          {eventReceived ? (
            eventName ? (
              <>
                {t('Latest event')}{' '}
                <code className="rounded bg-emerald-500/10 px-1 py-px font-mono text-[11px] text-emerald-700 dark:text-emerald-400">
                  {eventName}
                </code>
              </>
            ) : (
              t('Events are reaching Appwrite.')
            )
          ) : (
            t('Install the code and load a page. Checks every few seconds.')
          )}
        </p>
      </div>
    </div>
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

  // "Copy prompt": the whole setup for the selected platform, phrased for an
  // AI coding agent.
  const [copiedPrompt, setCopiedPrompt] = useState(false)
  const handleCopyPrompt = async () => {
    const prompt = buildAnalyticsSetupPrompt(platform, {
      endpoint: getProjectApiEndpoint(projectId),
      projectId,
      trackingId: property.snippetId || property.$id,
      domain: property.domain,
      propertyName: property.name,
    })
    try {
      await navigator.clipboard.writeText(prompt)
      setCopiedPrompt(true)
      toast.success(t('Prompt copied'))
      setTimeout(() => setCopiedPrompt(false), 2000)
    } catch {
      toast.error(t('Could not copy to clipboard'))
    }
  }

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
          {/* Copy prompt sits at the end of the tab row, as in Connect. */}
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
          <div className="ms-auto flex shrink-0 items-center ps-3">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 text-[12px]"
              onClick={() => void handleCopyPrompt()}
            >
              {copiedPrompt ? (
                <Check className="h-3.5 w-3.5" />
              ) : (
                <Sparkles className="h-3.5 w-3.5" />
              )}
              {t('Copy prompt')}
            </Button>
          </div>
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
              <SetupStatus
                eventReceived={eventReceived}
                eventName={firstEventName}
              />
            </div>
          </div>

          {/* Right: install command, then all the code as one copyable file. */}
          {/* Copy buttons sit inside the code frames (no separate toolbar
              row), so the headings sit right on top of the code. */}
          <div className="flex min-h-[360px] min-w-0 flex-col gap-5 md:min-h-0">
            {guide.install ? (
              <div className="shrink-0 space-y-1.5">
                <h4 className="text-[13px] font-semibold text-foreground">
                  {t('Installation')}
                </h4>
                <CodeBlock
                  code={guide.install.code}
                  language={guide.install.language}
                  copyInside
                />
              </div>
            ) : null}
            <div className="flex min-h-0 flex-1 flex-col gap-1.5">
              <h4 className="shrink-0 text-[13px] font-semibold text-foreground">
                {guide.install ? t('Add to your app') : t('Send events')}
              </h4>
              <CodeBlock
                code={guide.code.code}
                language={guide.code.language}
                copyInside
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

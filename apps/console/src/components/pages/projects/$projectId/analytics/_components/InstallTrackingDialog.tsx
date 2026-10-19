import { useEffect, useMemo, useState } from 'react'
import { Braces, Check, Sparkles } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
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
import {
  ConnectCodeExample,
  type ConnectCodeExampleTab,
} from '@/components/global/shared/ConnectCodeExample'
import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'
import { useAnalyticsFirstEvent } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  ANALYTICS_PLATFORMS,
  ANALYTICS_PLATFORM_META,
  ANALYTICS_PROXY_DEFAULT_PATH,
  buildAnalyticsInstallGuide,
  buildAnalyticsProxyRecipes,
  buildAnalyticsSetupPrompt,
  supportsAnalyticsProxy,
  type AnalyticsPlatform,
  type AnalyticsProxyRecipeId,
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

type SendMode = 'direct' | 'proxy'

/** The browser file's tab id; every other tab is a proxy recipe. */
const APP_FILE_ID = 'app'

/**
 * Install instructions for a property, in the shape of the project Connect
 * modal: platform tabs along the top, steps on the left, the code on the
 * right. Used from the property header ("Install") and the settings tab, so
 * the instructions live in one place.
 *
 * On Web, Default / Advanced picks how events travel. Both render the same
 * two cards (install command, then one code frame); Advanced (a proxy) adds
 * two lines on why to choose it, and the frame gains file tabs for the
 * ready-made proxies, Connect-style. The code comments explain the rest.
 */
export function InstallTrackingDialog({
  open,
  onOpenChange,
  projectId,
  property,
  initialPlatform = 'web',
  initialProxy = false,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  property: Models.AnalyticsProperty
  initialPlatform?: AnalyticsPlatform
  /** Open on "Send through a proxy" (the ad blockers card). */
  initialProxy?: boolean
}) {
  const t = useT()
  const [platform, setPlatform] = useState<AnalyticsPlatform>(initialPlatform)
  const [mode, setMode] = useState<SendMode>(initialProxy ? 'proxy' : 'direct')
  const [fileId, setFileId] = useState<string>(APP_FILE_ID)
  // The proxy last looked at; the prompt uses it.
  const [recipeId, setRecipeId] = useState<AnalyticsProxyRecipeId>('nextjs')

  // Web only: the browser hands events to the emitter-based tracker, which
  // posts them to one path. Flutter's tracker needs an Appwrite client, and
  // server-side REST calls aren't blocked in the first place.
  const proxyAvailable = supportsAnalyticsProxy(platform)
  const proxyPath =
    mode === 'proxy' && proxyAvailable ? ANALYTICS_PROXY_DEFAULT_PATH : undefined

  // Each open starts from the requested platform and mode.
  useEffect(() => {
    if (!open) return
    setPlatform(initialPlatform)
    setMode(initialProxy ? 'proxy' : 'direct')
    setFileId(APP_FILE_ID)
  }, [open, initialPlatform, initialProxy])

  const endpoint = getProjectApiEndpoint(projectId)
  // Ingestion takes either ID; the snippet ID is meant for client code.
  const trackingId = property.snippetId || property.$id

  // Two blocks: the install command, then all the code in one snippet.
  const guide = useMemo(
    () =>
      buildAnalyticsInstallGuide(platform, {
        endpoint,
        projectId,
        trackingId,
        domain: property.domain,
        proxyPath,
      }),
    [platform, endpoint, projectId, trackingId, property.domain, proxyPath],
  )

  // The code frame's files: the app's code, plus one file per proxy.
  const files = useMemo(() => {
    const app = {
      id: APP_FILE_ID,
      label: 'Browser',
      code: guide.code.code,
      language: guide.code.language,
    }
    if (!proxyPath) return [app]
    const recipes = buildAnalyticsProxyRecipes({
      endpoint,
      projectId,
      trackingId,
      proxyPath,
    })
    return [
      app,
      ...recipes.map((recipe) => ({
        id: recipe.id,
        label: recipe.label,
        code: recipe.code,
        language: recipe.language,
      })),
    ]
  }, [guide, proxyPath, endpoint, projectId, trackingId])
  const activeFile = files.find((file) => file.id === fileId) ?? files[0]
  const fileTabs = useMemo<ConnectCodeExampleTab[]>(
    () =>
      files.map((file) => ({
        id: file.id,
        // "Browser" is copy; framework names stay as they are.
        label: file.id === APP_FILE_ID ? t(file.label) : file.label,
      })),
    [files, t],
  )
  const meta = ANALYTICS_PLATFORM_META[platform]

  const selectMode = (next: SendMode) => {
    setMode(next)
    setFileId(APP_FILE_ID)
  }
  const selectFile = (id: string) => {
    setFileId(id)
    if (id !== APP_FILE_ID) setRecipeId(id as AnalyticsProxyRecipeId)
  }

  // Live confirmation while the dialog is open: polls until an event lands.
  const { eventReceived, firstEventName } = useAnalyticsFirstEvent(
    projectId,
    property.$id,
    open,
  )

  // "Copy prompt": the setup exactly as configured here (platform, mode and
  // the proxy last viewed), phrased for an AI coding agent.
  const [copiedPrompt, setCopiedPrompt] = useState(false)
  const handleCopyPrompt = async () => {
    const prompt = buildAnalyticsSetupPrompt(platform, {
      endpoint,
      projectId,
      trackingId,
      domain: property.domain,
      propertyName: property.name,
      proxyPath,
      proxyRecipe: recipeId,
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
      {/* Same width as the Connect modal, and taller: the code frame is the
          point of this dialog, so it gets the room. */}
      <DialogContent className="flex h-[min(88dvh,920px)] max-h-[88dvh] flex-col gap-0 overflow-hidden p-0 sm:max-w-6xl">
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
                onClick={() => {
                  setPlatform(id)
                  setFileId(APP_FILE_ID)
                }}
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

          {/* Right: a header row (Default / Advanced on Web, otherwise the
              first heading, plus Copy prompt), then the same two cards in
              every mode. */}
          <div className="flex min-h-[360px] min-w-0 flex-col gap-4 md:min-h-0">
            <div className="flex shrink-0 items-center justify-between gap-3">
              {proxyAvailable ? (
                <Tabs
                  value={mode}
                  onValueChange={(value) => selectMode(value as SendMode)}
                >
                  <TabsList>
                    <TabsTrigger value="direct">{t('Default')}</TabsTrigger>
                    <TabsTrigger value="proxy">{t('Advanced')}</TabsTrigger>
                  </TabsList>
                </Tabs>
              ) : (
                <h4 className="text-[13px] font-semibold text-foreground">
                  {guide.install ? t('Installation') : t('Send events')}
                </h4>
              )}
              {/* Builds from what this modal shows right now. */}
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 shrink-0 gap-1.5 text-[12px]"
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

            {/* Advanced: two lines on why to pick it; the code says how. */}
            {proxyPath ? (
              <div className="shrink-0 space-y-0.5 text-[12px] leading-relaxed text-muted-foreground">
                <p>
                  {t(
                    'Events go through a small proxy on your own domain, so ad blockers don\'t drop them.',
                  )}
                </p>
                <p>
                  {t(
                    'Choose it when many visitors use ad blockers. It needs a server route and an API key.',
                  )}
                </p>
              </div>
            ) : null}

            {/* Card 1: the install command. The heading lives in the header
                row when there are no mode tabs. */}
            {guide.install ? (
              <div className="shrink-0 space-y-1.5">
                {proxyAvailable ? (
                  <h4 className="text-[13px] font-semibold text-foreground">
                    {t('Installation')}
                  </h4>
                ) : null}
                <CodeBlock
                  code={guide.install.code}
                  language={guide.install.language}
                  copyInside
                />
              </div>
            ) : null}

            {/* Card 2: the code. One file normally; with a proxy, file tabs
                (browser code + one per proxy), like Connect's files. */}
            <div className="flex min-h-0 min-w-0 flex-1 flex-col gap-1.5">
              {guide.install || proxyAvailable ? (
                <h4 className="shrink-0 text-[13px] font-semibold text-foreground">
                  {guide.install ? t('Add to your app') : t('Send events')}
                </h4>
              ) : null}
              <ConnectCodeExample
                code={activeFile.code}
                language={activeFile.language}
                tabs={fileTabs}
                activeTabId={activeFile.id}
                onTabChange={selectFile}
                selectorAriaLabel={t('Select file')}
                fixedHeight="100%"
                className="min-h-0 flex-1"
              />
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}

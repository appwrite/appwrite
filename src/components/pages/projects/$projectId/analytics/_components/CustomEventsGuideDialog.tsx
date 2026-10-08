import { useMemo, useState } from 'react'
import { ArrowRight } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import {
  ANALYTICS_PLATFORMS,
  ANALYTICS_PLATFORM_META,
  buildCustomEventSnippets,
  type AnalyticsPlatform,
} from '@/lib/analytics-wizard/snippets'
import { ANALYTICS_AUTOMATIC_EVENTS } from '@/lib/react-query/hooks'

type CustomEventsGuideDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  property: Models.AnalyticsProperty | undefined
  /** Jump to the full integration setup (property Settings). */
  onOpenSetup?: () => void
}

const TIPS: { title: string; body: string }[] = [
  {
    title: 'Name the action',
    body: 'snake_case, object then verb: signup_completed, invite_sent. Keep names stable.',
  },
  {
    title: 'Add properties',
    body: 'Up to 16 key/value pairs, like plan or source. No personal data.',
  },
  {
    title: 'Skip automatic events',
    body: 'Pageviews, outbound links, downloads and engagement are tracked for you.',
  },
]

/**
 * How-to for custom events: what they are, naming and property tips, and
 * the tracking call per platform (same snippets source as the setup wizard).
 */
export function CustomEventsGuideDialog({
  open,
  onOpenChange,
  projectId,
  property,
  onOpenSetup,
}: CustomEventsGuideDialogProps) {
  const t = useT()
  const [platform, setPlatform] = useState<AnalyticsPlatform>('web')

  const blocks = useMemo(
    () =>
      property
        ? buildCustomEventSnippets(platform, {
            endpoint: getProjectApiEndpoint(projectId),
            projectId,
            // The ingestion endpoint takes either ID; the snippet ID is the
            // one meant for client-side code.
            trackingId: property.snippetId || property.$id,
            domain: property.domain,
          })
        : [],
    [platform, projectId, property],
  )
  const reserved = Array.from(ANALYTICS_AUTOMATIC_EVENTS)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[min(85vh,760px)] flex-col gap-0 overflow-hidden p-0 sm:max-w-4xl">
        <DialogHeader className="border-b border-border px-6 pb-4 pt-6 text-start">
          <DialogTitle className="text-[16px]">{t('Track custom events')}</DialogTitle>
          <DialogDescription className="text-[13px]">
            {t(
              'Track the actions that matter to your product, like sign-ups or purchases. Events you send show up in the Custom events card.',
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-5">
          {/* Tips: number inline with the title keeps each card short. */}
          <ol className="grid gap-3 sm:grid-cols-3">
            {TIPS.map((tip, index) => (
              <li
                key={tip.title}
                className="rounded-lg border border-border bg-muted/30 px-3 py-2.5"
              >
                <p className="flex items-center gap-2 text-[13px] font-medium text-foreground">
                  <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-foreground text-[10px] font-semibold text-background">
                    {index + 1}
                  </span>
                  {t(tip.title)}
                </p>
                <p className="mt-1 text-[12px] leading-snug text-muted-foreground">
                  {t(tip.body)}
                </p>
              </li>
            ))}
          </ol>

          {/* Code */}
          <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-[13px] font-medium text-foreground">
                {t('Send your first event')}
              </p>
              <Tabs value={platform} onValueChange={(v) => setPlatform(v as AnalyticsPlatform)}>
                <TabsList>
                  {ANALYTICS_PLATFORMS.map((id) => (
                    <TabsTrigger key={id} value={id}>
                      {t(ANALYTICS_PLATFORM_META[id].label)}
                    </TabsTrigger>
                  ))}
                </TabsList>
              </Tabs>
            </div>


            {blocks.map((block) => (
              <CodeBlock
                key={block.label}
                code={block.code}
                language={block.language}
                copyInside
              />
            ))}
          </div>

          {/* Reserved names: label and chips share a row. */}
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <p className="text-[12px] text-muted-foreground">{t('Reserved names')}</p>
            <div className="flex flex-wrap gap-1.5">
              {reserved.map((name) => (
                <code
                  key={name}
                  className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground"
                >
                  {name}
                </code>
              ))}
            </div>
          </div>
        </div>

        <DialogFooter className="border-t border-border px-6 py-3 sm:justify-between">
          {onOpenSetup ? (
            <Button
              variant="ghost"
              size="sm"
              className="h-8 gap-1.5 text-[12px]"
              onClick={() => {
                onOpenChange(false)
                onOpenSetup()
              }}
            >
              {t('Full setup guide')}
              <ArrowRight className="h-3.5 w-3.5" />
            </Button>
          ) : (
            <span />
          )}
          <Button size="sm" className="h-8 text-[12px]" onClick={() => onOpenChange(false)}>
            {t('Done')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

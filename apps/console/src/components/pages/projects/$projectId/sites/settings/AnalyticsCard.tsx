import { Link } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { getProjectApiEndpoint, sdk } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

const codeClassName =
  'rounded bg-muted/80 px-1.5 py-0.5 font-mono text-[12px] text-foreground/90'

interface AnalyticsCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function AnalyticsCard({ projectId, siteId, site }: AnalyticsCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [enabled, setEnabled] = useSyncStateFromServer(
    site?.analyticsEnabled ?? false,
  )

  const updateAnalyticsMutation = useMutation({
    mutationFn: async (nextEnabled: boolean) => {
      if (!projectId || !siteId)
        throw new Error('Project ID and Site ID are required')
      return await sdk
        .forProject(projectId)
        .sites.updateAnalytics({ siteId, enabled: nextEnabled })
    },
    onSuccess: (updated) => {
      toast.success(
        updated.analyticsEnabled
          ? t('Analytics enabled')
          : t('Analytics disabled'),
      )
      queryClient.setQueryData(['site', 'project', projectId, siteId], updated)
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
      // Turning analytics on can create the site's property.
      queryClient.invalidateQueries({
        queryKey: ['analytics', 'properties', projectId],
      })
    },
    onError: (error: unknown) => {
      // The switch would otherwise keep reading as on while the server value is off.
      setEnabled(site?.analyticsEnabled ?? false)
      toast.error(getErrorMessage(error, t('Failed to update analytics')))
    },
  })

  const propertyId = site?.analyticsPropertyId
  const hasChanges = enabled !== (site?.analyticsEnabled ?? false)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Analytics')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Track pageviews and visitors without adding a script. Appwrite adds the analytics tracker to pages served on your production domains, and reports them on an analytics property named after the site. Preview deployments are not tracked.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center justify-between gap-4">
          <div className="min-w-0">
            <Label htmlFor="site-analytics" className="text-[13px]">
              {t('Web analytics')}
            </Label>
            <p className="text-[12px] text-muted-foreground mt-1">
              {enabled
                ? t('Enabled - production pages include the tracker.')
                : t(
                    'Disabled - no tracker is added. Existing analytics data is kept.',
                  )}
            </p>
            <p className="text-[12px] text-muted-foreground mt-1">
              {t(
                'Tracked pageviews and events are billed as analytics usage, which adds up quickly on a high-traffic site.',
              )}
            </p>
          </div>
          <Switch
            id="site-analytics"
            checked={enabled}
            onCheckedChange={setEnabled}
            disabled={updateAnalyticsMutation.isPending}
            className="shrink-0"
          />
        </div>
        {enabled && projectId ? (
          <div className="mt-4 rounded-lg border border-border bg-muted/30 px-4 py-3">
            <p className="text-[12px] text-muted-foreground">
              {t(
                'If your site sets a Content-Security-Policy, in a header or a <meta> tag, add these sources. Otherwise the browser blocks the tracker and no data is recorded.',
              )}
            </p>
            <div className="mt-2 flex flex-col items-start gap-1.5">
              <code className={codeClassName}>
                script-src https://sdk.appwrite.io
              </code>
              <code className={codeClassName}>
                connect-src {new URL(getProjectApiEndpoint(projectId)).origin}
              </code>
            </div>
          </div>
        ) : null}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30 flex items-center justify-between gap-4">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || updateAnalyticsMutation.isPending}
          onClick={() => updateAnalyticsMutation.mutate(enabled)}
        >
          {t('Update')}
        </Button>
        {projectId && propertyId ? (
          <Button asChild variant="ghost" size="sm" className="h-9 text-[13px]">
            <Link
              to="/projects/$projectId/analytics/$propertyId"
              params={{ projectId, propertyId }}
            >
              {t('View analytics')}
            </Link>
          </Button>
        ) : null}
      </div>
    </div>
  )
}

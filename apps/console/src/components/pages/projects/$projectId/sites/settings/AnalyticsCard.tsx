import { Link } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { sdk } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

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
          </div>
          <Switch
            id="site-analytics"
            checked={enabled}
            onCheckedChange={setEnabled}
            disabled={updateAnalyticsMutation.isPending}
            className="shrink-0"
          />
        </div>
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

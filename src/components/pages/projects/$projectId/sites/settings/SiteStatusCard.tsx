/**
 * Status Card Component
 *
 * Allows users to enable or disable a site without deleting it.
 */

import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildSiteUpdateParams } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

interface SiteStatusCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function SiteStatusCard({
  projectId,
  siteId,
  site,
}: SiteStatusCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [enabled, setEnabled] = useState(false)

  useEffect(() => {
    if (site) {
      setEnabled(site.enabled !== false)
    }
  }, [site])

  const updateEnabledMutation = useMutation({
    mutationFn: async (nextEnabled: boolean) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(
        buildSiteUpdateParams(site, { enabled: nextEnabled }),
      )
    },
    onSuccess: (updated, nextEnabled) => {
      toast.success(
        nextEnabled
          ? t('Site has been enabled')
          : t('Site has been disabled'),
      )
      queryClient.setQueryData(
        ['site', 'project', projectId, siteId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
      if (site) {
        setEnabled(site.enabled !== false)
      }
    },
  })

  const currentEnabled = site?.enabled !== false
  const hasChanges = enabled !== currentEnabled

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Status')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Enable or disable this site without deleting it.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Switch
              id="toggle-site-enabled"
              checked={enabled}
              onCheckedChange={setEnabled}
              disabled={updateEnabledMutation.isPending}
            />
            <Label
              htmlFor="toggle-site-enabled"
              className="text-[13px] text-foreground"
            >
              {enabled ? t('Enabled') : t('Disabled')}
            </Label>
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || updateEnabledMutation.isPending}
          onClick={() => {
            if (hasChanges) {
              updateEnabledMutation.mutate(enabled)
            }
          }}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

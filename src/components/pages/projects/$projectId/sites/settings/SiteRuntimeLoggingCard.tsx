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

interface SiteRuntimeLoggingCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function SiteRuntimeLoggingCard({
  projectId,
  siteId,
  site,
}: SiteRuntimeLoggingCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [logging, setLogging] = useState(true)

  useEffect(() => {
    if (site && site.logging !== undefined) {
      setLogging(site.logging)
    }
  }, [site])

  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: (updated) => {
      toast.success(t('Logging updated successfully'))
      queryClient.setQueryData(
        ['site', 'project', projectId, siteId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update logging')))
    },
  })

  const handleSave = () => {
    updateSiteMutation.mutate({ logging })
  }

  const hasChanges = logging !== (site?.logging ?? true)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Logging')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Controls how much detail is captured for each request. Full logging helps you debug production issues with stdout, stderr, and stack traces in the console. Turning logging off reduces overhead and can slightly improve response time when you do not need that detail.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center justify-between gap-4">
          <div className="min-w-0">
            <Label htmlFor="site-runtime-logging" className="text-[13px]">
              {t('Full request logging')}
            </Label>
            <p className="text-[12px] text-muted-foreground mt-1">
              {logging
                ? t('Enabled - logs and errors from your site are recorded.')
                : t(
                    'Disabled - lighter request records; responses may be slightly faster.',
                  )}
            </p>
          </div>
          <Switch
            id="site-runtime-logging"
            checked={logging}
            onCheckedChange={setLogging}
            disabled={updateSiteMutation.isPending}
            className="shrink-0"
          />
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || updateSiteMutation.isPending}
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

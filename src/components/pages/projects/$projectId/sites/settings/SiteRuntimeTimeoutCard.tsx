import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildSiteUpdateParams } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

interface SiteRuntimeTimeoutCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function SiteRuntimeTimeoutCard({
  projectId,
  siteId,
  site,
}: SiteRuntimeTimeoutCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [requestTimeout, setRequestTimeout] = useState(15)

  useEffect(() => {
    if (site && site.timeout !== undefined) {
      setRequestTimeout(site.timeout)
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
      toast.success(t('Request timeout updated successfully'))
      queryClient.setQueryData(
        ['site', 'project', projectId, siteId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update request timeout')))
    },
  })

  const handleSave = () => {
    if (requestTimeout < 1 || requestTimeout > 30) {
      toast.error(t('Timeout must be between 1 and 30 seconds'))
      return
    }
    updateSiteMutation.mutate({ timeout: requestTimeout })
  }

  const hasChanges = requestTimeout !== (site?.timeout ?? 15)
  const timeoutValid = requestTimeout >= 1 && requestTimeout <= 30

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Timeout')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Upper bound on how long a single request may run before the platform stops it. Use a higher value for slow SSR or data-heavy pages; use a lower value to fail fast when something hangs. Allowed range is 1–30 seconds.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-2">
          <Label htmlFor="site-request-timeout" className="text-[13px]">
            {t('Seconds per request')}
          </Label>
          <Input
            id="site-request-timeout"
            type="number"
            min={1}
            max={30}
            value={requestTimeout}
            onChange={(e) => setRequestTimeout(Number(e.target.value))}
            className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
          />
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={
            !hasChanges || !timeoutValid || updateSiteMutation.isPending
          }
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

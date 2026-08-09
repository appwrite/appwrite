import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import {
  buildSiteUpdateParams,
  useSiteFrameworks,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

interface SiteRuntimeImageCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function SiteRuntimeImageCard({
  projectId,
  siteId,
  site,
}: SiteRuntimeImageCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { data: frameworksData } = useSiteFrameworks(projectId)

  const frameworks = useMemo(
    () => frameworksData?.frameworks || [],
    [frameworksData],
  )

  const currentFramework = useMemo(
    () => frameworks.find((f) => f.key === site?.framework),
    [frameworks, site?.framework],
  )

  const availableRuntimes = useMemo(() => {
    if (!currentFramework) return []
    return currentFramework.runtimes || []
  }, [currentFramework])

  const [buildRuntime, setBuildRuntime] = useState('')

  useEffect(() => {
    if (site) {
      setBuildRuntime(site.buildRuntime || '')
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
      toast.success(t('Runtime settings updated successfully'))
      queryClient.setQueryData(
        ['site', 'project', projectId, siteId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(
        getErrorMessage(error, t('Failed to update runtime settings')),
      )
    },
  })

  const handleSave = () => {
    updateSiteMutation.mutate({
      buildRuntime: buildRuntime || undefined,
    })
  }

  const hasRuntimeChoices = availableRuntimes.length > 0
  const hasChanges = buildRuntime !== (site?.buildRuntime || '')

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Image')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Base image used when your site runs in production (SSR, API routes, and dynamic handlers). Pick an image that matches your stack. Changes take effect after the next successful deploy.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        {hasRuntimeChoices ? (
          <div className="space-y-2">
            <Label htmlFor="site-runtime-image" className="text-[13px]">
              {t('Image')}
            </Label>
            <Select
              value={buildRuntime || undefined}
              onValueChange={setBuildRuntime}
            >
              <SelectTrigger
                id="site-runtime-image"
                className="mt-2 h-9 max-w-md border-border bg-background text-[13px]"
              >
                <SelectValue placeholder={t('Select an image')} />
              </SelectTrigger>
              <SelectContent>
                {availableRuntimes.map((runtime: unknown) => {
                  const rt = runtime as { $id?: string; name?: string }
                  const runtimeId = String(rt.$id ?? runtime)
                  return (
                    <SelectItem key={runtimeId} value={runtimeId}>
                      <div className="flex items-center gap-2">
                        <RuntimeIcon runtime={runtimeId} size="sm" />
                        {rt.name ?? runtimeId}
                      </div>
                    </SelectItem>
                  )
                })}
              </SelectContent>
            </Select>
          </div>
        ) : (
          <p className="text-[13px] text-muted-foreground">
            {site?.framework
              ? t('No images are available for this framework yet.')
              : t(
                  'Choose a framework in build settings to see compatible images.',
                )}
          </p>
        )}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={
            !hasChanges || !hasRuntimeChoices || updateSiteMutation.isPending
          }
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

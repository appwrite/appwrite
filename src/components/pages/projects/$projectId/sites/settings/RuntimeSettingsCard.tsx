/**
 * Runtime Settings Card Component
 *
 * Configures the build runtime version for the site.
 */

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

interface RuntimeSettingsCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function RuntimeSettingsCard({
  projectId,
  siteId,
  site,
}: RuntimeSettingsCardProps) {
  const queryClient = useQueryClient()
  const { data: frameworksData } = useSiteFrameworks(projectId)

  const frameworks = useMemo(
    () => frameworksData?.frameworks || [],
    [frameworksData],
  )

  const [buildRuntime, setBuildRuntime] = useState('')

  // Get current framework info
  const currentFramework = useMemo(
    () => frameworks.find((f) => f.key === site?.framework),
    [frameworks, site?.framework],
  )

  // Get available runtimes for the framework
  const availableRuntimes = useMemo(() => {
    if (!currentFramework) return []
    // Frameworks typically have runtime options
    // This would come from the framework data structure
    return currentFramework.runtimes || []
  }, [currentFramework])

  // Initialize state from site data
  useEffect(() => {
    if (site?.buildRuntime) {
      setBuildRuntime(site.buildRuntime)
    }
  }, [site])

  // Update site mutation (use full site payload so other cards' values are preserved)
  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: () => {
      toast.success('Runtime settings updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: any) => {
      toast.error(getErrorMessage(error, 'Failed to update runtime settings'))
    },
  })

  const handleSave = () => {
    updateSiteMutation.mutate({ buildRuntime: buildRuntime || undefined })
  }

  const hasChanges = buildRuntime !== site?.buildRuntime

  if (!currentFramework || availableRuntimes.length === 0) {
    return null
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Runtime Settings
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Configure the build runtime version for the site
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-2">
          <Label htmlFor="build-runtime" className="text-[13px]">
            Build Runtime
          </Label>
          <Select value={buildRuntime} onValueChange={setBuildRuntime}>
            <SelectTrigger
              id="build-runtime"
              className="mt-2 h-9 border-border bg-background text-[13px]"
            >
              <SelectValue placeholder="Select runtime" />
            </SelectTrigger>
            <SelectContent>
              {availableRuntimes.map((runtime: any) => {
                const runtimeId = runtime.$id ?? runtime
                return (
                  <SelectItem key={runtimeId} value={runtimeId}>
                    <div className="flex items-center gap-2">
                      <RuntimeIcon runtime={String(runtimeId)} size="sm" />
                      {runtime.name ?? runtime}
                    </div>
                  </SelectItem>
                )
              })}
            </SelectContent>
          </Select>
          <p className="text-[12px] text-muted-foreground">
            Runtime changes apply on redeploy
          </p>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || updateSiteMutation.isPending}
          onClick={handleSave}
        >
          Update
        </Button>
      </div>
    </div>
  )
}

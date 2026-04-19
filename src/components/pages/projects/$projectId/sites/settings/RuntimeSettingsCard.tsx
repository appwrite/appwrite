/**
 * Runtime Settings Card Component
 *
 * Configures runtime-related settings for the site: build runtime version
 * and the runtime specification (CPU/memory used at runtime, e.g. SSR).
 */

import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
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
  useSiteSpecifications,
} from '@/lib/react-query/hooks'
import {
  hasUnavailableSpecifications,
  isSpecificationAllowedInPlan,
} from '@/lib/specifications'

interface RuntimeSettingsCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
  isCloud?: boolean
}

export function RuntimeSettingsCard({
  projectId,
  siteId,
  site,
  isCloud = false,
}: RuntimeSettingsCardProps) {
  const queryClient = useQueryClient()
  const { data: frameworksData } = useSiteFrameworks(projectId)
  const { data: specificationsData } = useSiteSpecifications(projectId)

  const frameworks = useMemo(
    () => frameworksData?.frameworks || [],
    [frameworksData],
  )
  const specifications = useMemo(
    () => specificationsData?.specifications || [],
    [specificationsData],
  )

  const [buildRuntime, setBuildRuntime] = useState('')
  const [runtimeSpecification, setRuntimeSpecification] = useState('')
  const [timeout, setTimeout] = useState(15)
  const [logging, setLogging] = useState(true)

  // Get current framework info
  const currentFramework = useMemo(
    () => frameworks.find((f) => f.key === site?.framework),
    [frameworks, site?.framework],
  )

  // Get available runtimes for the framework
  const availableRuntimes = useMemo(() => {
    if (!currentFramework) return []
    return currentFramework.runtimes || []
  }, [currentFramework])

  // Initialize state from site data
  useEffect(() => {
    if (site) {
      setBuildRuntime(site.buildRuntime || '')
      setRuntimeSpecification(site.runtimeSpecification || '')
      if (site.timeout !== undefined) setTimeout(site.timeout)
      if (site.logging !== undefined) setLogging(site.logging)
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
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, 'Failed to update runtime settings'))
    },
  })

  const handleSave = () => {
    if (timeout < 1 || timeout > 30) {
      toast.error('Timeout must be between 1 and 30 seconds')
      return
    }
    updateSiteMutation.mutate({
      buildRuntime: buildRuntime || undefined,
      runtimeSpecification: runtimeSpecification || undefined,
      timeout,
      logging,
    })
  }

  const hasRuntimeSpec = isCloud && specifications.length > 0
  const hasRuntimeChoices = availableRuntimes.length > 0
  const hasChanges =
    buildRuntime !== (site?.buildRuntime || '') ||
    runtimeSpecification !== (site?.runtimeSpecification || '') ||
    timeout !== (site?.timeout ?? 15) ||
    logging !== (site?.logging ?? true)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Runtime
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Configure how your site runs after it's been built, including the
          runtime version, resources available at request time, the execution
          timeout, and request logging.
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">
          {hasRuntimeChoices && (
            <div className="space-y-2">
              <Label htmlFor="runtime-version" className="text-[13px]">
                Version
              </Label>
              <Select value={buildRuntime} onValueChange={setBuildRuntime}>
                <SelectTrigger
                  id="runtime-version"
                  className="mt-2 h-9 border-border bg-background text-[13px]"
                >
                  <SelectValue placeholder="Select runtime version" />
                </SelectTrigger>
                <SelectContent>
                  {availableRuntimes.map((runtime: unknown) => {
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
                Runtime changes apply on redeploy.
              </p>
            </div>
          )}

          {hasRuntimeSpec && (
            <div>
              <Label htmlFor="runtime-specification" className="text-[13px]">
                Specification
              </Label>
              <Select
                value={runtimeSpecification || undefined}
                onValueChange={setRuntimeSpecification}
              >
                <SelectTrigger
                  id="runtime-specification"
                  className="mt-2 h-9 border-border bg-background text-[13px]"
                >
                  <SelectValue placeholder="Select specification" />
                </SelectTrigger>
                <SelectContent>
                  {specifications
                    .filter((spec) => spec.slug && spec.slug.trim() !== '')
                    .map((spec) => (
                      <SelectItem
                        key={spec.slug}
                        value={spec.slug}
                        disabled={!isSpecificationAllowedInPlan(spec)}
                      >
                        {spec.cpus} CPU, {spec.memory}MB RAM
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
              <p className="mt-1 text-[12px] text-muted-foreground">
                CPU and memory used when your site serves traffic, including
                server-side rendering (SSR).
              </p>
              {hasUnavailableSpecifications(specifications) && (
                <div className="mt-3 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                  <p className="text-[12px] text-muted-foreground">
                    Need more resources?{' '}
                    <a
                      href="#"
                      className="font-medium text-foreground underline hover:no-underline"
                      onClick={(e) => {
                        e.preventDefault()
                      }}
                    >
                      Upgrade your plan
                    </a>{' '}
                    to unlock additional specifications.
                  </p>
                </div>
              )}
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="timeout" className="text-[13px]">
              Timeout
            </Label>
            <Input
              id="timeout"
              type="number"
              min={1}
              max={30}
              value={timeout}
              onChange={(e) => setTimeout(Number(e.target.value))}
              className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
            <p className="text-[12px] text-muted-foreground">
              Maximum execution time per request, in seconds (1-30).
            </p>
          </div>

          <div className="flex items-center justify-between pt-2">
            <div>
              <Label htmlFor="logging" className="text-[13px]">
                Logging
              </Label>
              <p className="text-[12px] text-muted-foreground">
                {logging
                  ? 'Full logging including logs and errors'
                  : 'Request logs exclude logs and errors, site responses are slightly faster'}
              </p>
            </div>
            <Switch
              id="logging"
              checked={logging}
              onCheckedChange={setLogging}
              disabled={updateSiteMutation.isPending}
            />
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={
            !hasChanges ||
            timeout < 1 ||
            timeout > 30 ||
            updateSiteMutation.isPending
          }
          onClick={handleSave}
        >
          Update
        </Button>
      </div>
    </div>
  )
}

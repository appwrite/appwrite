/**
 * Resource Limits Card Component
 *
 * Configures CPU and memory specifications for the site (Cloud only).
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
import {
  hasUnavailableSpecifications,
  isSpecificationAllowedInPlan,
} from '@/lib/specifications'
import { useSiteSpecifications } from '@/lib/react-query/hooks'

interface ResourceLimitsCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
  isCloud?: boolean
}

export function ResourceLimitsCard({
  projectId,
  siteId,
  site,
  isCloud = false,
}: ResourceLimitsCardProps) {
  const queryClient = useQueryClient()
  const { data: specificationsData } = useSiteSpecifications(projectId)

  const specifications = useMemo(
    () => specificationsData?.specifications || [],
    [specificationsData],
  )

  const [specification, setSpecification] = useState('')

  // Initialize state from site data
  useEffect(() => {
    if (site?.specification) {
      setSpecification(site.specification)
    }
  }, [site])

  // Update site mutation
  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update({
        siteId,
        name: site.name,
        ...updates,
      })
    },
    onSuccess: () => {
      toast.success('Resource limits updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: any) => {
      toast.error(getErrorMessage(error, 'Failed to update resource limits'))
    },
  })

  const handleSave = () => {
    updateSiteMutation.mutate({ specification: specification || undefined })
  }

  const hasChanges = specification !== site?.specification

  // Only show in Cloud environments
  if (!isCloud || specifications.length === 0) {
    return null
  }

  const hasUnavailableSpecs = hasUnavailableSpecifications(specifications)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Resource Limits
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Configure CPU and memory specifications for the site
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-2">
          <Label htmlFor="specification" className="text-[13px]">
            Specification
          </Label>
          <Select
            value={specification || undefined}
            onValueChange={setSpecification}
          >
            <SelectTrigger
              id="specification"
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
          {hasUnavailableSpecs && (
            <div className="mt-3 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
              <p className="text-[12px] text-muted-foreground">
                Need more resources?{' '}
                <a
                  href="#"
                  className="font-medium text-foreground underline hover:no-underline"
                  onClick={(e) => {
                    e.preventDefault()
                    // TODO: Navigate to upgrade page
                  }}
                >
                  Upgrade your plan
                </a>{' '}
                to unlock additional specifications.
              </p>
            </div>
          )}
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

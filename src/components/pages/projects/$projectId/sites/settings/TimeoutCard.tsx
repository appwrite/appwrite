/**
 * Timeout Card Component
 *
 * Allows users to configure the execution timeout for the site.
 */

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

interface TimeoutCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function TimeoutCard({ projectId, siteId, site }: TimeoutCardProps) {
  const queryClient = useQueryClient()
  const [timeout, setTimeout] = useState(15)

  // Initialize state from site data
  useEffect(() => {
    if (site?.timeout !== undefined) {
      setTimeout(site.timeout)
    }
  }, [site])

  // Update site mutation
  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: () => {
      toast.success('Timeout updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: any) => {
      toast.error(getErrorMessage(error, 'Failed to update timeout'))
    },
  })

  const handleSave = () => {
    if (timeout < 1 || timeout > 30) {
      toast.error('Timeout must be between 1 and 30 seconds')
      return
    }
    updateSiteMutation.mutate({ timeout })
  }

  const hasChanges = site?.timeout !== timeout

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">Timeout</h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Set the execution timeout limit for the site
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-2">
          <Label htmlFor="timeout" className="text-[13px]">
            Timeout (seconds)
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
            Maximum execution time in seconds (1-30)
          </p>
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

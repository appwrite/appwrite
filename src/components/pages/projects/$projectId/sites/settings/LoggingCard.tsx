/**
 * Logging Card Component
 *
 * Allows users to enable or disable logging for the site.
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

interface LoggingCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function LoggingCard({ projectId, siteId, site }: LoggingCardProps) {
  const queryClient = useQueryClient()
  const [logging, setLogging] = useState(true)

  // Initialize state from site data
  useEffect(() => {
    if (site?.logging !== undefined) {
      setLogging(site.logging)
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
      toast.success('Logging updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, 'Failed to update logging'))
      // Revert to original value on error
      if (site) {
        setLogging(site.logging ?? true)
      }
    },
  })

  const handleToggle = (enabled: boolean) => {
    setLogging(enabled)
    updateSiteMutation.mutate({ logging: enabled })
  }

  const hasChanges = site?.logging !== logging

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">Logging</h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Enable or disable logging for the site
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center justify-between">
          <div>
            <Label htmlFor="logging" className="text-[13px]">
              Enable logging
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
            onCheckedChange={handleToggle}
            disabled={updateSiteMutation.isPending}
          />
        </div>
      </div>
      {hasChanges && (
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={updateSiteMutation.isPending}
            onClick={() => {
              if (hasChanges) {
                updateSiteMutation.mutate({ logging })
              }
            }}
          >
            Update
          </Button>
        </div>
      )}
    </div>
  )
}

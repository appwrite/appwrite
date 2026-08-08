import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { buildSiteUpdateParams } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

interface GitSilentModeCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site
}

export function GitSilentModeCard({
  projectId,
  siteId,
  site,
}: GitSilentModeCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [silentMode, setSilentMode] = useState(site.providerSilentMode ?? false)

  useEffect(() => {
    setSilentMode(site.providerSilentMode ?? false)
  }, [site.providerSilentMode, site.$id])

  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId)
        throw new Error('Project ID and Site ID are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: (updated) => {
      toast.success(t('Repository settings updated successfully'))
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
        getErrorMessage(error, t('Failed to update repository settings')),
      )
    },
  })

  const hasChanges = useMemo(
    () => silentMode !== (site.providerSilentMode ?? false),
    [silentMode, site.providerSilentMode],
  )

  const handleSave = () => {
    if (!hasChanges) {
      toast.info(t('No changes to save'))
      return
    }
    updateSiteMutation.mutate({ providerSilentMode: silentMode })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Silent mode')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Control whether Appwrite posts automated comments on commits in your connected GitHub repository (for example deployment notes on pull requests). Deployments, checks, and builds are unchanged-only optional commit comments are skipped when silent mode is on.', // pragma: allowlist secret
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center justify-between gap-4">
          <Label htmlFor="site-git-silent-mode" className="text-[13px]">
            {t('Disable automated commit comments')}
          </Label>
          <Switch
            id="site-git-silent-mode"
            checked={silentMode}
            onCheckedChange={setSilentMode}
            disabled={updateSiteMutation.isPending}
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

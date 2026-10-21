import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { buildFunctionUpdateParams } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

interface GitSilentModeCardProps {
  func: Models.Function
}

export function GitSilentModeCard({ func }: GitSilentModeCardProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const queryClient = useQueryClient()
  const [silentMode, setSilentMode] = useState(func.providerSilentMode ?? false)

  useEffect(() => {
    setSilentMode(func.providerSilentMode ?? false)
  }, [func.providerSilentMode, func.$id])

  const updateFunctionMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !func.$id)
        throw new Error('Project ID and Function ID are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, updates),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Function updated successfully'))
      queryClient.setQueryData(
        ['function', 'project', projectId, func.$id],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(
        error instanceof Error ? error.message : t('Failed to update function'),
      )
    },
  })

  const hasChanges = useMemo(
    () => silentMode !== (func.providerSilentMode ?? false),
    [silentMode, func.providerSilentMode],
  )

  const handleSave = () => {
    if (!hasChanges) {
      toast.info(t('No changes to save'))
      return
    }
    updateFunctionMutation.mutate({ providerSilentMode: silentMode })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">{t('Silent mode')}</h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Control whether Appwrite posts automated comments on commits in your connected GitHub repository (for example deployment notes on pull requests). Deployments, checks, and builds are unchanged-only optional commit comments are skipped when silent mode is on.', // pragma: allowlist secret
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center justify-between gap-4">
          <Label htmlFor="function-git-silent-mode" className="text-[13px]">
            {t('Disable automated commit comments')}
          </Label>
          <Switch
            id="function-git-silent-mode"
            checked={silentMode}
            onCheckedChange={setSilentMode}
            disabled={updateFunctionMutation.isPending}
          />
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || updateFunctionMutation.isPending}
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

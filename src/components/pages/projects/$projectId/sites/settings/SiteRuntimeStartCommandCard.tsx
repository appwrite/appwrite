import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildSiteUpdateParams } from '@/lib/react-query/hooks'
import { StartCommandLabel } from '../_components/StartCommandLabel'
import { useT } from '@/lib/i18n/translate'

interface SiteRuntimeStartCommandCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function SiteRuntimeStartCommandCard({
  projectId,
  siteId,
  site,
}: SiteRuntimeStartCommandCardProps) {
  const t = useT()
  const queryClient = useQueryClient()

  const [startCommand, setStartCommand] = useState('')

  useEffect(() => {
    if (site) {
      setStartCommand(site.startCommand ?? '')
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
      toast.success(t('Start command updated successfully'))
      queryClient.setQueryData(['site', 'project', projectId, siteId], updated)
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update start command')))
    },
  })

  if (site?.adapter !== 'ssr') {
    return null
  }

  const hasChanges = startCommand !== (site?.startCommand ?? '')

  const handleSave = () => {
    updateSiteMutation.mutate({
      startCommand: startCommand || undefined,
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Start command')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Command used to start your SSR server after a successful deploy. Leave it empty to use the framework default.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-2">
          <StartCommandLabel htmlFor="site-start-command" />
          <Input
            id="site-start-command"
            value={startCommand}
            onChange={(e) => setStartCommand(e.target.value)}
            placeholder={t('Enter start command')}
            className="h-9 max-w-md font-mono text-[13px]"
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

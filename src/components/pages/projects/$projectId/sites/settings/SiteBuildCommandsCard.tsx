import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { getFrameworkAdapterDefaults } from '@/lib/frameworks'
import {
  buildSiteUpdateParams,
  useSiteFrameworks,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

interface SiteBuildCommandsCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function SiteBuildCommandsCard({
  projectId,
  siteId,
  site,
}: SiteBuildCommandsCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { data: frameworksData } = useSiteFrameworks(projectId)

  const frameworks = useMemo(
    () => frameworksData?.frameworks || [],
    [frameworksData],
  )

  const frameworkKey = site?.framework || ''
  const adapterKey = site?.adapter || ''
  const currentFramework = useMemo(
    () => frameworks.find((f) => f.key === frameworkKey),
    [frameworks, frameworkKey],
  )

  const adapterDefaults = useMemo(
    () => getFrameworkAdapterDefaults(currentFramework, adapterKey),
    [currentFramework, adapterKey],
  )

  const [installCommand, setInstallCommand] = useState('')
  const [buildCommand, setBuildCommand] = useState('')

  useEffect(() => {
    if (site) {
      setInstallCommand(site.installCommand ?? '')
      setBuildCommand(site.buildCommand ?? '')
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
      toast.success(t('Build commands updated successfully'))
      queryClient.setQueryData(
        ['site', 'project', projectId, siteId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update build commands')))
    },
  })

  const handleSave = () => {
    updateSiteMutation.mutate({
      installCommand: installCommand || undefined,
      buildCommand: buildCommand || undefined,
    })
  }

  const hasChanges =
    installCommand !== (site?.installCommand ?? '') ||
    buildCommand !== (site?.buildCommand ?? '')

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Commands')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Shell commands run on the build worker (defaults follow your framework).')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div>
          <Label htmlFor="install-command" className="text-[13px]">
            {t('Install command')}
          </Label>
          <Input
            id="install-command"
            value={installCommand}
            onChange={(e) => setInstallCommand(e.target.value)}
            placeholder={
              adapterDefaults.installCommand || t('Enter install command')
            }
            className="mt-2 h-9 font-mono text-[13px]"
          />
        </div>
        <div>
          <Label htmlFor="build-command" className="text-[13px]">
            {t('Build command')}
          </Label>
          <Input
            id="build-command"
            value={buildCommand}
            onChange={(e) => setBuildCommand(e.target.value)}
            placeholder={
              adapterDefaults.buildCommand || t('Enter build command')
            }
            className="mt-2 h-9 font-mono text-[13px]"
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

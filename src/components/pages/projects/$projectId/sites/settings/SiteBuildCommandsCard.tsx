import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildSiteUpdateParams,
  useSiteFrameworks,
} from '@/lib/react-query/hooks'
import { BuildInputWithReset } from './_components/BuildInputWithReset'

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
  const queryClient = useQueryClient()
  const { data: frameworksData } = useSiteFrameworks(projectId)

  const frameworks = useMemo(
    () => frameworksData?.frameworks || [],
    [frameworksData],
  )

  const frameworkKey = site?.framework || ''
  const currentFramework = useMemo(
    () => frameworks.find((f) => f.key === frameworkKey),
    [frameworks, frameworkKey],
  )

  const frameworkDefaults = useMemo(() => {
    if (!currentFramework) {
      return {
        installCommand: 'npm install',
        buildCommand: 'npm run build',
      }
    }
    return {
      installCommand: currentFramework.installCommand || 'npm install',
      buildCommand: currentFramework.buildCommand || 'npm run build',
    }
  }, [currentFramework])

  const [installCommand, setInstallCommand] = useState('')
  const [buildCommand, setBuildCommand] = useState('')

  useEffect(() => {
    if (site) {
      setInstallCommand(site.installCommand || '')
      setBuildCommand(site.buildCommand || '')
    }
  }, [site])

  useEffect(() => {
    if (frameworkKey && !installCommand && site) {
      setInstallCommand(frameworkDefaults.installCommand)
    }
    if (frameworkKey && !buildCommand && site) {
      setBuildCommand(frameworkDefaults.buildCommand)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [frameworkKey, frameworkDefaults, site])

  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: () => {
      toast.success('Build commands updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, 'Failed to update build commands'))
    },
  })

  const handleSave = () => {
    updateSiteMutation.mutate({
      installCommand: installCommand || undefined,
      buildCommand: buildCommand || undefined,
    })
  }

  const hasChanges =
    installCommand !== site?.installCommand ||
    buildCommand !== site?.buildCommand

  const isInstallModified = installCommand !== frameworkDefaults.installCommand
  const isBuildModified = buildCommand !== frameworkDefaults.buildCommand

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">Commands</h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Shell commands run on the build worker (defaults follow your
          framework).
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <BuildInputWithReset
          id="install-command"
          label="Install command"
          value={installCommand}
          placeholder={frameworkDefaults.installCommand}
          onChange={setInstallCommand}
          onReset={() => setInstallCommand(frameworkDefaults.installCommand)}
          isModified={isInstallModified}
        />
        <BuildInputWithReset
          id="build-command"
          label="Build command"
          value={buildCommand}
          placeholder={frameworkDefaults.buildCommand}
          onChange={setBuildCommand}
          onReset={() => setBuildCommand(frameworkDefaults.buildCommand)}
          isModified={isBuildModified}
        />
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

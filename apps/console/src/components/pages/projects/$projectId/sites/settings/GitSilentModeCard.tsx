import { Fragment, useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import {
  GitBranch,
  GitCommitHorizontal,
  MessageSquare,
  CircleCheck,
  Info,
} from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
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
  const silent = site.providerSilentMode ?? false
  const saved = {
    branch: site.providerBranch || '',
    deployments: site.providerDeployments ?? true,
    comments: !silent && (site.providerComments ?? true),
    statuses: !silent && (site.providerStatuses ?? true),
  }
  const [settings, setSettings] = useState(saved)

  useEffect(() => {
    setSettings(saved)
  }, [
    saved.branch,
    saved.deployments,
    saved.comments,
    saved.statuses,
    site.$id,
  ])

  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId)
        throw new Error('Project ID and Site ID are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: (updated) => {
      toast.success(t('Repository settings updated successfully'))
      queryClient.setQueryData(['site', 'project', projectId, siteId], updated)
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

  const hasChanges =
    settings.branch !== saved.branch ||
    settings.deployments !== saved.deployments ||
    settings.comments !== saved.comments ||
    settings.statuses !== saved.statuses

  const handleSave = () => {
    updateSiteMutation.mutate({
      // Silent mode overrides these switches, so saving them clears it
      providerSilentMode: false,
      providerBranch: settings.branch || undefined,
      providerDeployments: settings.deployments,
      providerComments: settings.comments,
      providerStatuses: settings.statuses,
    })
  }

  const rows = [
    {
      key: 'deployments',
      icon: GitCommitHorizontal,
      label: t('Deploy on push'),
      description: t(
        'Build every push that matches your branch and path rules. When off, deploy from Create deployment.',
      ),
      disabledReason: '',
    },
    {
      key: 'comments',
      icon: MessageSquare,
      label: t('Post build summaries'),
      description: t(
        'One summary per pull request with the build status and preview link, kept up to date.',
      ),
      disabledReason: settings.deployments
        ? ''
        : t('Needs deploy on push. Manual deployments have no pull request.'),
    },
    {
      key: 'statuses',
      icon: CircleCheck,
      label: t('Report commit status'),
      description: t(
        'Show building, passed or failed on each commit. Can be required in branch protection.',
      ),
      disabledReason: '',
    },
  ] as const

  const branchDisabledReason = settings.deployments
    ? ''
    : t('Needs deploy on push. Manual deployments choose whether to go live.')
  const branchRow = (
    <div
      className={`flex items-center gap-3 px-4 py-3 ${branchDisabledReason ? 'opacity-60' : ''}`}
    >
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted">
        <GitBranch className="h-4 w-4 text-muted-foreground" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-1.5">
          <p className="text-[13px] font-medium text-foreground">
            {t('Production branch')}
          </p>
          {branchDisabledReason && (
            <Tooltip>
              <TooltipTrigger asChild>
                <Info
                  className="h-3.5 w-3.5 text-muted-foreground"
                  aria-label={branchDisabledReason}
                />
              </TooltipTrigger>
              <TooltipContent className="max-w-xs text-[13px]">
                {branchDisabledReason}
              </TooltipContent>
            </Tooltip>
          )}
        </div>
        <p className="text-[12px] text-muted-foreground">
          {t(
            'Pushes to this branch go live. Other branches get preview deployments.',
          )}
        </p>
      </div>
      <BranchSelector
        className="w-56 shrink-0"
        label=""
        projectId={projectId ?? undefined}
        installationId={site.installationId}
        providerRepositoryId={site.providerRepositoryId}
        value={settings.branch}
        onChange={(branch) => setSettings((prev) => ({ ...prev, branch }))}
        disabled={branchDisabledReason !== '' || updateSiteMutation.isPending}
      />
    </div>
  )

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Git automation')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Choose what happens when you push to the connected repository.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="rounded-lg border border-border bg-background divide-y divide-border">
          {rows.map((row) => {
            const Icon = row.icon
            const disabled = row.disabledReason !== ''
            return (
              <Fragment key={row.key}>
                <label
                  htmlFor={`site-git-${row.key}`}
                  className={`flex items-center gap-3 px-4 py-3 ${disabled ? 'opacity-60' : 'cursor-pointer'}`}
                >
                  <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted">
                    <Icon className="h-4 w-4 text-muted-foreground" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-1.5">
                      <p className="text-[13px] font-medium text-foreground">
                        {row.label}
                      </p>
                      {disabled && (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Info
                              className="h-3.5 w-3.5 text-muted-foreground"
                              aria-label={row.disabledReason}
                            />
                          </TooltipTrigger>
                          <TooltipContent className="max-w-xs text-[13px]">
                            {row.disabledReason}
                          </TooltipContent>
                        </Tooltip>
                      )}
                    </div>
                    <p className="text-[12px] text-muted-foreground">
                      {row.description}
                    </p>
                  </div>
                  <Switch
                    id={`site-git-${row.key}`}
                    checked={settings[row.key] && !disabled}
                    onCheckedChange={(checked) =>
                      setSettings((prev) => ({ ...prev, [row.key]: checked }))
                    }
                    disabled={disabled || updateSiteMutation.isPending}
                  />
                </label>
                {row.key === 'deployments' && branchRow}
              </Fragment>
            )
          })}
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

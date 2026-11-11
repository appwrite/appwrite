import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { GitPullRequest, MessageSquare, CircleCheck } from 'lucide-react'
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
  const git = site as Models.Site & {
    providerAutoDeploy?: boolean
    providerComments?: boolean
    providerCommitStatus?: boolean
  }
  const silent = site.providerSilentMode ?? false
  const saved = {
    autoDeploy: git.providerAutoDeploy ?? true,
    comments: !silent && (git.providerComments ?? true),
    commitStatus: !silent && (git.providerCommitStatus ?? true),
  }
  const [settings, setSettings] = useState(saved)

  useEffect(() => {
    setSettings(saved)
  }, [saved.autoDeploy, saved.comments, saved.commitStatus, site.$id])

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
    settings.autoDeploy !== saved.autoDeploy ||
    settings.comments !== saved.comments ||
    settings.commitStatus !== saved.commitStatus

  const handleSave = () => {
    updateSiteMutation.mutate({
      providerAutoDeploy: settings.autoDeploy,
      providerComments: settings.comments,
      providerCommitStatus: settings.commitStatus,
    } as Partial<Models.Site>)
  }

  const rows = [
    {
      key: 'autoDeploy',
      icon: GitPullRequest,
      label: t('Deploy on push'),
      description: t(
        'Build every push that matches your branch and path rules. When off, deploy from Create deployment.',
      ),
      disabledReason: '',
    },
    {
      key: 'comments',
      icon: MessageSquare,
      label: t('Comment on pull requests'),
      description: t(
        'One comment per pull request with the build status and preview link, kept up to date.',
      ),
      disabledReason: settings.autoDeploy
        ? ''
        : t('Needs deploy on push. Manual deployments have no pull request.'),
    },
    {
      key: 'commitStatus',
      icon: CircleCheck,
      label: t('Report commit status'),
      description: t(
        'Show building, passed or failed on each commit. Can be required in branch protection.',
      ),
      disabledReason: '',
    },
  ] as const

  const summary = !settings.autoDeploy
    ? settings.commitStatus
      ? t(
          'Pushes do nothing. Manual deployments from Git report their status on the commit.',
        )
      : t('Pushes do nothing. Deploy manually from Create deployment.')
    : [
        t('Every matching push builds'),
        settings.commitStatus
          ? t('its commit shows the build status')
          : t('commits show no status'),
        settings.comments
          ? t('pull requests get a build comment')
          : t('pull requests stay quiet'),
      ].join(', ') + '.'

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
      <div className="px-6 py-4 space-y-3">
        <div className="rounded-lg border border-border bg-background divide-y divide-border">
          {rows.map((row) => {
            const Icon = row.icon
            const disabled = row.disabledReason !== ''
            return (
              <label
                key={row.key}
                htmlFor={`site-git-${row.key}`}
                className={`flex items-center gap-3 px-4 py-3 ${disabled ? 'opacity-60' : 'cursor-pointer'}`}
              >
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted">
                  <Icon className="h-4 w-4 text-muted-foreground" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-[13px] font-medium text-foreground">
                    {row.label}
                  </p>
                  <p className="text-[12px] text-muted-foreground">
                    {disabled ? row.disabledReason : row.description}
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
            )
          })}
        </div>
        <p className="text-[12px] text-muted-foreground">{summary}</p>
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

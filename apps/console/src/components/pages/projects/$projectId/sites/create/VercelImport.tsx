/**
 * Imports a Vercel project as a site: its repository, build settings and
 * production variables fill the repository step, so the user only reviews and deploys.
 */

import { useEffect, useRef, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { AppwriteException, VCSDetectionType } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useT } from '@/lib/i18n/translate'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import {
  fetchRepositories,
  useDeleteVercelOAuth2,
  useGetVercelProject,
  useVercelProjects,
} from '@/lib/react-query/hooks'
import { useWizard } from './WizardContext'

/** Query parameter the connect flow returns with, so the result can be shown once. */
const VERCEL_CONNECT_PARAM = 'vercel'

function returnUrl(outcome: 'connected' | 'failed'): string {
  const url = new URL(window.location.href)
  url.searchParams.set(VERCEL_CONNECT_PARAM, outcome)
  return url.toString()
}

export function VercelImport({
  projectId,
  region,
}: {
  projectId: string
  region?: string
}) {
  const t = useT()
  const navigate = useNavigate()
  const { installations, updateFormData } = useWizard()
  const [vercelProjectId, setVercelProjectId] = useState('')
  const [importing, setImporting] = useState(false)
  const projects = useVercelProjects(projectId, region)
  const getProject = useGetVercelProject(projectId, region)
  const disconnect = useDeleteVercelOAuth2(projectId, region)

  const errorType =
    projects.error instanceof AppwriteException ? projects.error.type : ''

  // The connect flow comes back with its outcome; say it once and drop it from the URL
  const reported = useRef(false)
  useEffect(() => {
    if (reported.current) return
    const url = new URL(window.location.href)
    const outcome = url.searchParams.get(VERCEL_CONNECT_PARAM)
    if (!outcome) return
    reported.current = true
    const reason = url.searchParams.get('error')
    url.searchParams.delete(VERCEL_CONNECT_PARAM)
    url.searchParams.delete('error')
    window.history.replaceState(null, '', url.toString())
    if (outcome === 'failed') {
      toast.error(
        reason === 'access_denied'
          ? t('The Vercel integration was not installed')
          : t('Unable to connect your Vercel account'),
      )
    }
  }, [t])

  const connect = () => {
    const url = new URL(`${getApiEndpoint(region)}/migrations/vercel/oauth2`)
    url.searchParams.set('success', returnUrl('connected'))
    url.searchParams.set('failure', returnUrl('failed'))
    window.location.assign(url.toString())
  }

  const importProject = async () => {
    setImporting(true)
    try {
      const vercel = await getProject.mutateAsync(vercelProjectId)

      if (!vercel.repositoryProvider || !vercel.repositoryName) {
        toast.error(
          t(
            'This Vercel project has no Git repository. Deploy it by uploading its files instead.',
          ),
        )
        return
      }

      // The site deploys from the same repository, through the user's own Git installation
      const fullName =
        `${vercel.repositoryOwner}/${vercel.repositoryName}`.toLowerCase()
      let match: { installationId: string; id: string } | null = null
      for (const installation of installations) {
        if (installation.provider !== vercel.repositoryProvider) continue
        const result = await fetchRepositories(
          projectId,
          installation.$id,
          VCSDetectionType.Framework,
          0,
          20,
          vercel.repositoryName,
        )
        const repository = (result.frameworkProviderRepositories ?? []).find(
          (repo) =>
            `${repo.organization}/${repo.name}`.toLowerCase() === fullName,
        )
        if (repository) {
          match = { installationId: installation.$id, id: repository.id }
          break
        }
      }

      if (!match) {
        toast.error(
          t(
            `Connect ${vercel.repositoryOwner}/${vercel.repositoryName} to Appwrite first, then import again.`,
          ),
        )
        return
      }

      updateFormData({
        installationId: match.installationId,
        providerRepositoryId: match.id,
        repositoryOwner: vercel.repositoryOwner,
        repositoryName: vercel.repositoryName,
        siteName: vercel.name,
        framework: vercel.framework,
        providerBranch: vercel.branch,
        providerRootDirectory: vercel.rootDirectory || './',
        installCommand: vercel.installCommand,
        buildCommand: vercel.buildCommand,
        outputDirectory: vercel.outputDirectory,
        variables: vercel.variables.map((variable) => ({
          key: variable.key,
          value: variable.value,
          secret: variable.secret,
        })),
        importedFrom: 'vercel',
      })

      navigate({
        to: '/projects/$projectId/sites/create/repositories/$installationId/$repositoryId',
        params: {
          projectId,
          installationId: match.installationId,
          repositoryId: match.id,
        },
      })
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Unable to read the Vercel project'),
      )
    } finally {
      setImporting(false)
    }
  }

  // The server has no Vercel integration configured, so there is nothing to import from.
  // Returned after every hook, so the hook order never changes between renders
  if (errorType === 'general_not_implemented') {
    return null
  }

  const connected = projects.data !== undefined

  return (
    <div className="mt-6 rounded-lg border border-border bg-muted/30 px-4 py-4">
      <p className="text-[14px] font-semibold text-foreground leading-tight mb-1.5">
        {t('Moving from Vercel?')}
      </p>
      {!connected ? (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-[12px] text-muted-foreground leading-snug">
            {t(
              'Connect your Vercel account to import a project with its build settings and environment variables.',
            )}
          </p>
          <Button size="sm" onClick={connect} disabled={projects.isLoading}>
            {t('Connect Vercel')}
          </Button>
        </div>
      ) : (
        <div className="space-y-3">
          <div className="flex flex-wrap items-center gap-3">
            <Select
              value={vercelProjectId}
              onValueChange={setVercelProjectId}
              disabled={importing}
            >
              <SelectTrigger className="h-9 min-w-56 text-[13px]">
                <SelectValue placeholder={t('Select a Vercel project')} />
              </SelectTrigger>
              <SelectContent>
                {projects.data.projects.map((project) => (
                  <SelectItem key={project.projectId} value={project.projectId}>
                    {project.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              size="sm"
              onClick={() => void importProject()}
              disabled={!vercelProjectId || importing}
            >
              {importing ? t('Importing…') : t('Import')}
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={() =>
                disconnect.mutate(undefined, {
                  onSuccess: () => setVercelProjectId(''),
                })
              }
              disabled={disconnect.isPending}
            >
              {t('Disconnect')}
            </Button>
          </div>
          <p className="text-[11px] text-muted-foreground">
            {t(
              'Sensitive Vercel variables cannot be read back, so they are added with an empty value for you to fill in.',
            )}
          </p>
        </div>
      )}
    </div>
  )
}

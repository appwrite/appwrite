import { useState } from 'react'
import {
  ExternalLink,
  XCircle,
  Loader2,
  AlertTriangle,
  Settings,
  Unplug,
  GitBranch,
} from 'lucide-react'
import { queryOptions, useQuery, useQueryClient } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useVcsInstallations,
  useDeleteVcsInstallation,
} from '@/lib/react-query/hooks/vcs'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import {
  VcsIcon,
  getKnownVcsProvider,
  getProviderOwnerUrl,
  type VcsProviderId,
} from '@/lib/vcs/providers'
import {
  menuItemRowClassName,
  MenuItemContent,
} from '@/components/global/shared/ContextMenuIcon'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Button } from '@/components/ui/button'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Pagination } from '@/components/global/shared/Pagination'
import type { Models } from '@appwrite.io/console'
import { GitInstallationContextMenu } from './GitInstallationContextMenu'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

interface GitConfigurationCardProps {
  projectId: string
  page: number
  limit: number
  onPageChange: (page: number) => void
  getGitHubAuthUrl: (mode?: 'create' | 'update') => string
  getVcsAuthUrl?: (
    provider?: VcsProviderId,
    mode?: 'create' | 'update',
  ) => string
  isSelfHosted?: boolean
  isVcsEnabled?: boolean
}

async function fetchInstallationAffectedFunctions(
  projectId: string,
  installationId: string,
) {
  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.functions.list({
    queries: [
      Query.limit(100),
      Query.equal('installationId', installationId),
    ],
  })
  return {
    functions: response.functions || [],
    total: response.total || 0,
  }
}

async function fetchInstallationAffectedSites(
  projectId: string,
  installationId: string,
) {
  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.sites.list({
    queries: [
      Query.limit(100),
      Query.equal('installationId', installationId),
    ],
  })
  return {
    sites: response.sites || [],
    total: response.total || 0,
  }
}

function installationAffectedFunctionsQueryOptions(
  projectId: string,
  installationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'functions',
      'project',
      projectId,
      'installation',
      installationId,
    ],
    queryFn: () =>
      fetchInstallationAffectedFunctions(projectId, installationId!),
    enabled: !!projectId && !!installationId,
  })
}

function installationAffectedSitesQueryOptions(
  projectId: string,
  installationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['sites', 'project', projectId, 'installation', installationId],
    queryFn: () => fetchInstallationAffectedSites(projectId, installationId!),
    enabled: !!projectId && !!installationId,
  })
}

export function GitConfigurationCard({
  projectId,
  page,
  limit,
  onPageChange,
  getGitHubAuthUrl,
  getVcsAuthUrl,
  isSelfHosted = false,
  isVcsEnabled = true,
}: GitConfigurationCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  // Fall back to the GitHub-only helper when a generalized builder isn't provided.
  const vcsAuthUrl = (
    provider: VcsProviderId,
    mode: 'create' | 'update' = 'create',
  ) => (getVcsAuthUrl ? getVcsAuthUrl(provider, mode) : getGitHubAuthUrl(mode))
  const { data: installationsData, isLoading } = useVcsInstallations(
    projectId,
    page,
    limit,
  )
  const deleteMutation = useDeleteVcsInstallation(projectId)

  const installations = installationsData?.installations || []
  const total = installationsData?.total || 0

  // State for disconnect modal
  const [disconnectModalOpen, setDisconnectModalOpen] = useState(false)
  const [selectedInstallation, setSelectedInstallation] =
    useState<Models.Installation | null>(null)

  // Prefetched before the modal opens so first paint has the final layout
  const { data: affectedFunctions } = useQuery(
    installationAffectedFunctionsQueryOptions(
      projectId,
      disconnectModalOpen ? selectedInstallation?.$id : null,
    ),
  )

  const { data: affectedSites } = useQuery(
    installationAffectedSitesQueryOptions(
      projectId,
      disconnectModalOpen ? selectedInstallation?.$id : null,
    ),
  )

  const hasAffectedResources =
    (affectedSites?.total ?? 0) > 0 || (affectedFunctions?.total ?? 0) > 0

  const handleDisconnect = async () => {
    if (!selectedInstallation) return

    const installation = selectedInstallation
    closeDialogBeforeOverlayUnmount(() => {
      setDisconnectModalOpen(false)
      setSelectedInstallation(null)
    })

    try {
      await deleteMutation.mutateAsync(installation.$id)
      toast.success(
        `${installation.organization} ${t('has been disconnected from this project')}`,
      )
    } catch (error: unknown) {
      toast.error(
        getErrorMessage(error, t('Failed to disconnect installation')),
      )
    }
  }

  const handleOpenDisconnectModal = (installation: Models.Installation) => {
    openDialogAfterOverlayCloses(() => {
      void (async () => {
        setSelectedInstallation(installation)
        try {
          await Promise.all([
            queryClient.ensureQueryData(
              installationAffectedFunctionsQueryOptions(
                projectId,
                installation.$id,
              ),
            ),
            queryClient.ensureQueryData(
              installationAffectedSitesQueryOptions(
                projectId,
                installation.$id,
              ),
            ),
          ])
        } catch {
          // Open anyway; lists stay empty if the prefetch failed
        }
        setDisconnectModalOpen(true)
      })()
    })
  }

  const getProviderUrl = (provider: string, organization: string) => {
    return getProviderOwnerUrl(provider, organization)
  }

  // Empty State: total === 0 AND (!isSelfHosted OR isVcsEnabled === true)
  if (total === 0 && (!isSelfHosted || isVcsEnabled)) {
    return (
      <div className="rounded-xl border border-dashed border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Git configuration')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-4">
            {t(
              'Add a Git installation to your project so you can connect repositories later through your function or site settings.',
            )}
          </p>
          <div className="flex flex-col items-center justify-center py-8 text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
              <GitBranch className="h-6 w-6 text-muted-foreground" />
            </div>
            <p className="mb-1 text-[14px] font-medium text-foreground">
              {t('No installation was added to the project yet')}
            </p>
            <p className="mb-4 text-[13px] text-muted-foreground">
              {t('Add an installation to connect repositories')}
            </p>
            <div className="flex flex-wrap items-center justify-center gap-2">
              <Button
                variant="secondary"
                size="sm"
                className="h-9 text-[13px]"
                asChild
              >
                <a href={vcsAuthUrl('github')} target="_blank" rel="noreferrer">
                  <VcsIcon type="github" className="me-1.5 h-4 w-4" />
                  {t('Connect to GitHub')}
                </a>
              </Button>
              <Button
                variant="secondary"
                size="sm"
                className="h-9 text-[13px]"
                asChild
              >
                <a href={vcsAuthUrl('gitlab')} target="_blank" rel="noreferrer">
                  <VcsIcon type="gitlab" className="me-1.5 h-4 w-4" />
                  {t('Connect to GitLab')}
                </a>
              </Button>
              <Button
                variant="secondary"
                size="sm"
                className="h-9 text-[13px]"
                asChild
              >
                <a
                  href={vcsAuthUrl('bitbucket')}
                  target="_blank"
                  rel="noreferrer"
                >
                  <VcsIcon type="bitbucket" className="me-1.5 h-4 w-4" />
                  {t('Connect to Bitbucket')}
                </a>
              </Button>
              <Button
                variant="secondary"
                size="sm"
                className="h-9 text-[13px]"
                asChild
              >
                <a href={vcsAuthUrl('origin')} target="_blank" rel="noreferrer">
                  <VcsIcon type="origin" className="me-1.5 h-4 w-4" />
                  {t('Connect to Origin')}
                </a>
              </Button>
            </div>
          </div>
        </div>
      </div>
    )
  }

  // Self-Hosted Warning State: total === 0 AND isSelfHosted === true AND isVcsEnabled === false
  if (total === 0 && isSelfHosted && !isVcsEnabled) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Git configuration')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <Alert>
            <AlertTriangle className="h-4 w-4" />
            <AlertDescription className="text-[13px]">
              <strong>{t('Installing Git on a self-hosted instance')}</strong>
              <br />
              {t(
                'Before installing Git in a locally hosted Appwrite project, ensure your environment variables are configured.',
              )}{' '}
              {/* pragma: allowlist secret */}
              <Button
                variant="link"
                size="sm"
                className="h-auto p-0 text-[13px] font-medium underline"
                asChild
              >
                <DocsRouteLink href="/docs/advanced/self-hosting/configuration/version-control">
                  {t('Learn more')}
                </DocsRouteLink>
              </Button>
            </AlertDescription>
          </Alert>
        </div>
      </div>
    )
  }

  // Installations List State: total > 0
  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Git configuration')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 @container">
          <div className="flex gap-6 @[600px]:flex-row flex-col">
            {/* Left side - Description */}
            <div className="@[600px]:w-64 shrink-0">
              <p className="text-[13px] text-muted-foreground">
                {t(
                  'Add a Git installation to your project so you can connect repositories later through your function or site settings.',
                )}
              </p>
            </div>

            {/* Right side - Content */}
            <div className="flex-1 min-w-0">
              {isLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                </div>
              ) : installations.length === 0 ? (
                <div className="text-center py-8 text-[13px] text-muted-foreground">
                  {t('No installations found')}
                </div>
              ) : (
                <>
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent border-b border-border">
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[150px] max-w-[500px]">
                          {t('Owner')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[150px] max-w-[500px]">
                          {t('Created')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[150px] max-w-[500px]">
                          {t('Updated')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[60px]" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {installations.map((installation) => {
                        const providerUrl = getProviderUrl(
                          installation.provider,
                          installation.organization,
                        )
                        const knownProvider = getKnownVcsProvider(
                          installation.provider,
                        )
                        return (
                          <GitInstallationContextMenu
                            key={installation.$id}
                            installation={installation}
                            configureHref={
                              knownProvider
                                ? vcsAuthUrl(knownProvider.id, 'update')
                                : null
                            }
                            onDisconnect={handleOpenDisconnectModal}
                          >
                            <TableRow>
                              <TableCell className="px-4 py-3">
                                <div className="flex items-center gap-2">
                                  <div className="flex h-8 w-8 items-center justify-center rounded-full bg-muted">
                                    <VcsIcon
                                      type={installation.provider}
                                      className="h-4 w-4"
                                    />
                                  </div>
                                  {providerUrl ? (
                                    <a
                                      href={providerUrl}
                                      target="_blank"
                                      rel="noreferrer"
                                      className="text-[13px] font-medium link-neutral"
                                    >
                                      {installation.organization}
                                    </a>
                                  ) : (
                                    <span className="text-[13px] font-medium text-foreground">
                                      {installation.organization}
                                    </span>
                                  )}
                                </div>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <DateTooltip date={installation.$createdAt} />
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <DateTooltip date={installation.$updatedAt} />
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                    <RowActionsMenuTrigger compact />
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent align="end">
                                    {knownProvider ? (
                                      <DropdownMenuItem asChild>
                                        <a
                                          href={vcsAuthUrl(
                                            knownProvider.id,
                                            'update',
                                          )}
                                          target="_blank"
                                          rel="noreferrer"
                                          className={menuItemRowClassName}
                                        >
                                          <MenuItemContent icon={Settings}>
                                            {t('Configure')}
                                          </MenuItemContent>
                                        </a>
                                      </DropdownMenuItem>
                                    ) : null}
                                    <DropdownMenuItem
                                      onClick={() =>
                                        handleOpenDisconnectModal(installation)
                                      }
                                    >
                                      <MenuItemContent icon={Unplug}>
                                        {t('Disconnect')}
                                      </MenuItemContent>
                                    </DropdownMenuItem>
                                  </DropdownMenuContent>
                                </DropdownMenu>
                              </TableCell>
                            </TableRow>
                          </GitInstallationContextMenu>
                        )
                      })}
                    </TableBody>
                  </Table>

                  {total > limit && (
                    <div className="mt-4">
                      <Pagination
                        currentPage={page + 1}
                        totalItems={total}
                        pageSize={limit}
                        pageSizeOptions={[limit]}
                        onPageChange={(newPage) => onPageChange(newPage - 1)}
                        onPageSizeChange={() => {}}
                        itemLabel={t('installations')}
                      />
                    </div>
                  )}
                </>
              )}
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
          <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
            <Button
              variant="secondary"
              size="sm"
              className="h-9 w-full text-[13px] sm:w-auto"
              asChild
            >
              <a href={vcsAuthUrl('github')} target="_blank" rel="noreferrer">
                <VcsIcon type="github" className="me-1.5 h-4 w-4" />
                {t('Connect with GitHub')}
              </a>
            </Button>
            <Button
              variant="secondary"
              size="sm"
              className="h-9 w-full text-[13px] sm:w-auto"
              asChild
            >
              <a href={vcsAuthUrl('gitlab')} target="_blank" rel="noreferrer">
                <VcsIcon type="gitlab" className="me-1.5 h-4 w-4" />
                {t('Connect with GitLab')}
              </a>
            </Button>
            <Button
              variant="secondary"
              size="sm"
              className="h-9 w-full text-[13px] sm:w-auto"
              asChild
            >
              <a
                href={vcsAuthUrl('bitbucket')}
                target="_blank"
                rel="noreferrer"
              >
                <VcsIcon type="bitbucket" className="me-1.5 h-4 w-4" />
                {t('Connect with Bitbucket')}
              </a>
            </Button>
            <Button
              variant="secondary"
              size="sm"
              className="h-9 w-full text-[13px] sm:w-auto"
              asChild
            >
              <a href={vcsAuthUrl('origin')} target="_blank" rel="noreferrer">
                <VcsIcon type="origin" className="me-1.5 h-4 w-4" />
                {t('Connect with Origin')}
              </a>
            </Button>
          </div>
        </div>
      </div>

      {/* Disconnect Modal */}
      <Dialog open={disconnectModalOpen} onOpenChange={setDisconnectModalOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Disconnect installation')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {!hasAffectedResources
                ? t(
                    'Are you sure you want to disconnect this git installation?',
                  )
                : t(
                    'Are you sure you want to disconnect this git installation? This will affect future deployments to the following sites and functions:',
                  )}
            </DialogDescription>
          </DialogHeader>
          {hasAffectedResources ? (
            <>
              <div className="border-t border-border" />
              <div className="max-h-[60dvh] overflow-y-auto px-6 pb-4 pt-0">
                {affectedSites && affectedSites.total > 0 && (
                  <div className="mb-4">
                    <p className="mb-2 text-[12px] font-medium text-foreground">
                      {t('Sites')}
                    </p>
                    <div className="space-y-2">
                          {affectedSites.sites.map((site) => (
                            <div
                              key={site.$id}
                              className="flex items-center gap-2 rounded-lg bg-muted/30 p-2"
                            >
                              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                <FrameworkIcon
                                  framework={site.framework}
                                  size="sm"
                                />
                              </div>
                              <div className="min-w-0 flex-1">
                                <p className="truncate text-[13px] font-medium text-foreground">
                                  {site.name}
                                </p>
                                <p className="text-[12px] text-muted-foreground">
                                  {t('Last deployed:')}{' '}
                                  <DateTooltip date={site.$updatedAt} />
                                </p>
                              </div>
                            </div>
                          ))}
                    </div>
                  </div>
                )}

                {affectedFunctions && affectedFunctions.total > 0 && (
                  <div>
                    <p className="mb-2 text-[12px] font-medium text-foreground">
                      {t('Functions')}
                    </p>
                    <div className="space-y-2">
                          {affectedFunctions.functions.map((func) => (
                            <div
                              key={func.$id}
                              className="flex items-center gap-2 rounded-lg bg-muted/30 p-2"
                            >
                              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                <RuntimeIcon
                                  runtime={func.runtime || ''}
                                  size="sm"
                                />
                              </div>
                              <div className="min-w-0 flex-1">
                                <p className="truncate text-[13px] font-medium text-foreground">
                                  {func.name}
                                </p>
                                <p className="text-[12px] text-muted-foreground">
                                  {t('Last deployed:')}{' '}
                                  <DateTooltip date={func.$updatedAt} />
                                </p>
                              </div>
                            </div>
                          ))}
                    </div>
                  </div>
                )}
              </div>
            </>
          ) : null}
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => {
                setDisconnectModalOpen(false)
                setSelectedInstallation(null)
              }}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDisconnect}
              disabled={deleteMutation.isPending}
            >
              {t('Disconnect')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}

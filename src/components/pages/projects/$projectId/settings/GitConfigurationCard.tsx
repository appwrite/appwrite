import { useState } from 'react'
import {
  Plus,
  MoreHorizontal,
  ExternalLink,
  XCircle,
  Loader2,
  Globe,
  Zap,
  AlertTriangle,
} from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useVcsInstallations,
  useDeleteVcsInstallation,
} from '@/lib/react-query/hooks/vcs'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
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

// GitHub Icon Component
function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

interface GitConfigurationCardProps {
  projectId: string
  page: number
  limit: number
  onPageChange: (page: number) => void
  getGitHubAuthUrl: (mode?: 'create' | 'update') => string
  isSelfHosted?: boolean
  isVcsEnabled?: boolean
}

export function GitConfigurationCard({
  projectId,
  page,
  limit,
  onPageChange,
  getGitHubAuthUrl,
  isSelfHosted = false,
  isVcsEnabled = true,
}: GitConfigurationCardProps) {
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

  // Fetch affected functions and sites when modal opens
  const { data: affectedFunctions, isLoading: functionsLoading } = useQuery({
    queryKey: [
      'functions',
      'project',
      projectId,
      'installation',
      selectedInstallation?.$id,
    ],
    queryFn: async () => {
      if (!projectId || !selectedInstallation?.$id) {
        return { functions: [], total: 0 }
      }
      const projectSdk = sdk.forProject(projectId)
      const queries = [
        Query.limit(100),
        Query.equal('installationId', selectedInstallation.$id),
      ]
      const response = await projectSdk.functions.list({ queries })
      return {
        functions: response.functions || [],
        total: response.total || 0,
      }
    },
    enabled: disconnectModalOpen && !!selectedInstallation?.$id,
  })

  const { data: affectedSites, isLoading: sitesLoading } = useQuery({
    queryKey: [
      'sites',
      'project',
      projectId,
      'installation',
      selectedInstallation?.$id,
    ],
    queryFn: async () => {
      if (!projectId || !selectedInstallation?.$id) {
        return { sites: [], total: 0 }
      }
      const projectSdk = sdk.forProject(projectId)
      const queries = [
        Query.limit(100),
        Query.equal('installationId', selectedInstallation.$id),
      ]
      const response = await projectSdk.sites.list({ queries })
      return {
        sites: response.sites || [],
        total: response.total || 0,
      }
    },
    enabled: disconnectModalOpen && !!selectedInstallation?.$id,
  })

  const handleDisconnect = async () => {
    if (!selectedInstallation) return

    try {
      await deleteMutation.mutateAsync(selectedInstallation.$id)
      toast.success(
        `${selectedInstallation.organization} has been disconnected from this project`,
      )
      setDisconnectModalOpen(false)
      setSelectedInstallation(null)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to disconnect installation'))
    }
  }

  const handleOpenDisconnectModal = (installation: Models.Installation) => {
    setSelectedInstallation(installation)
    setDisconnectModalOpen(true)
  }

  const getProviderIcon = (provider: string) => {
    if (provider === 'github') {
      return <GitHubIcon className="h-4 w-4" />
    }
    return null
  }

  const getProviderUrl = (provider: string, organization: string) => {
    if (provider === 'github') {
      return `https://github.com/${organization}`
    }
    return null
  }

  // Empty State: total === 0 AND (!isSelfHosted OR isVcsEnabled === true)
  if (total === 0 && (!isSelfHosted || isVcsEnabled)) {
    return (
      <div className="rounded-xl border border-dashed border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Git configuration
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-4">
            Add a Git installation to your project so you can connect
            repositories later through your function or site settings.
          </p>
          <div className="flex flex-col items-center justify-center py-8 text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
              <GitHubIcon className="h-6 w-6 text-muted-foreground" />
            </div>
            <p className="mb-1 text-[14px] font-medium text-foreground">
              No installation was added to the project yet
            </p>
            <p className="mb-4 text-[13px] text-muted-foreground">
              Add an installation to connect repositories
            </p>
            <Button
              variant="secondary"
              size="sm"
              className="h-9 text-[13px]"
              asChild
            >
              <a href={getGitHubAuthUrl()} target="_blank" rel="noreferrer">
                <GitHubIcon className="mr-1.5 h-4 w-4" />
                Connect to GitHub
              </a>
            </Button>
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
            Git configuration
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <Alert>
            <AlertTriangle className="h-4 w-4" />
            <AlertDescription className="text-[13px]">
              <strong>Installing Git on a self-hosted instance</strong>
              <br />
              Before installing Git in a locally hosted Appwrite project, ensure
              your environment variables are configured.{' '}
              <Button
                variant="link"
                size="sm"
                className="h-auto p-0 text-[13px] font-medium underline"
                asChild
              >
                <a
                  href="https://appwrite.io/docs/advanced/self-hosting/configuration/version-control"
                  target="_blank"
                  rel="noreferrer"
                >
                  Learn more
                </a>
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
            Git configuration
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 @container">
          <div className="flex gap-6 @[600px]:flex-row flex-col">
            {/* Left side - Description */}
            <div className="@[600px]:w-64 shrink-0">
              <p className="text-[13px] text-muted-foreground">
                Add a Git installation to your project so you can connect
                repositories later through your function or site settings.
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
                  No installations found
                </div>
              ) : (
                <>
                  <div className="rounded-lg border border-border overflow-hidden">
                    <Table>
                      <TableHeader>
                        <TableRow className="hover:bg-transparent border-b border-border">
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[150px] max-w-[500px]">
                            Owner
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[150px] max-w-[500px]">
                            Created
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[150px] max-w-[500px]">
                            Updated
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[60px]"></TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {installations.map((installation) => {
                          const providerUrl = getProviderUrl(
                            installation.provider,
                            installation.organization,
                          )
                          return (
                            <TableRow key={installation.$id}>
                              <TableCell className="px-4 py-3">
                                <div className="flex items-center gap-2">
                                  <div className="flex h-8 w-8 items-center justify-center rounded-full bg-muted">
                                    {getProviderIcon(installation.provider)}
                                  </div>
                                  {providerUrl ? (
                                    <a
                                      href={providerUrl}
                                      target="_blank"
                                      rel="noreferrer"
                                      className="text-[13px] font-medium text-foreground hover:underline"
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
                                    <Button
                                      variant="ghost"
                                      size="sm"
                                      className="h-7 w-7 p-0"
                                    >
                                      <MoreHorizontal className="h-4 w-4" />
                                    </Button>
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent align="end">
                                    <DropdownMenuItem asChild>
                                      <a
                                        href={getGitHubAuthUrl('update')}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="flex items-center"
                                      >
                                        <ExternalLink className="mr-2 h-4 w-4" />
                                        Configure
                                      </a>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                      onClick={() =>
                                        handleOpenDisconnectModal(installation)
                                      }
                                      className="text-destructive"
                                    >
                                      <XCircle className="mr-2 h-4 w-4" />
                                      Disconnect
                                    </DropdownMenuItem>
                                  </DropdownMenuContent>
                                </DropdownMenu>
                              </TableCell>
                            </TableRow>
                          )
                        })}
                      </TableBody>
                    </Table>
                  </div>

                  {total > limit && (
                    <div className="mt-4">
                      <Pagination
                        currentPage={page + 1}
                        totalItems={total}
                        pageSize={limit}
                        pageSizeOptions={[limit]}
                        onPageChange={(newPage) => onPageChange(newPage - 1)}
                        onPageSizeChange={() => {}}
                        itemLabel="installations"
                      />
                    </div>
                  )}
                </>
              )}
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
          <Button
            variant="secondary"
            size="sm"
            className="h-9 text-[13px]"
            asChild
          >
            <a href={getGitHubAuthUrl()} target="_blank" rel="noreferrer">
              <Plus className="mr-1.5 h-4 w-4" />
              Add installation
            </a>
          </Button>
        </div>
      </div>

      {/* Disconnect Modal */}
      <Dialog open={disconnectModalOpen} onOpenChange={setDisconnectModalOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Disconnect installation</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {affectedFunctions?.total === 0 && affectedSites?.total === 0
                ? 'Are you sure you want to disconnect this git installation?'
                : 'Are you sure you want to disconnect this git installation? This will affect future deployments to the following sites and functions:'}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0 max-h-[60vh] overflow-y-auto">
            {(functionsLoading || sitesLoading) && (
              <div className="flex items-center justify-center py-8">
                <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
              </div>
            )}

            {!functionsLoading && !sitesLoading && (
              <>
                {affectedSites && affectedSites.total > 0 && (
                  <div className="mb-4">
                    <p className="text-[12px] font-medium text-foreground mb-2">
                      Sites
                    </p>
                    <div className="space-y-2">
                      {affectedSites.sites.map((site) => (
                        <div
                          key={site.$id}
                          className="flex items-center gap-2 p-2 rounded-lg bg-muted/30"
                        >
                          <Globe className="h-4 w-4 text-muted-foreground" />
                          <div className="flex-1 min-w-0">
                            <p className="text-[13px] font-medium text-foreground truncate">
                              {site.name}
                            </p>
                            <p className="text-[12px] text-muted-foreground">
                              Last deployed:{' '}
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
                    <p className="text-[12px] font-medium text-foreground mb-2">
                      Functions
                    </p>
                    <div className="space-y-2">
                      {affectedFunctions.functions.map((func) => (
                        <div
                          key={func.$id}
                          className="flex items-center gap-2 p-2 rounded-lg bg-muted/30"
                        >
                          <Zap className="h-4 w-4 text-muted-foreground" />
                          <div className="flex-1 min-w-0">
                            <p className="text-[13px] font-medium text-foreground truncate">
                              {func.name}
                            </p>
                            <p className="text-[12px] text-muted-foreground">
                              Last deployed:{' '}
                              <DateTooltip date={func.$updatedAt} />
                            </p>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </>
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
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
              Cancel
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDisconnect}
              disabled={deleteMutation.isPending}
            >
              Disconnect
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}

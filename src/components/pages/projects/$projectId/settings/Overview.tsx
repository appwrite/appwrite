import { useState, useEffect, useMemo, useRef } from 'react'
import { useNavigate, useSearch } from '@tanstack/react-router'
import {
  Loader2,
  Plus,
  Code,
  Upload,
  Eye,
  EyeOff,
  Globe,
  XCircle,
  MoreHorizontal,
  AlertTriangle,
  Copy,
  Check,
  User,
  Users,
  Database,
  Zap,
  MessageSquare,
  Folder,
  Building2,
  UserCircle,
  Shield,
  CreditCard,
} from 'lucide-react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { useProject, useOrganizations } from '@/lib/react-query/hooks'
import { MCPSection } from '@/components/pages/projects/$projectId/shared/MCPSection'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Alert, AlertDescription } from '@/components/ui/alert'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Separator } from '@/components/ui/separator'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'
import { ApiService } from '@appwrite.io/console'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Checkbox } from '@/components/ui/checkbox'
import { Pagination } from '@/components/global/shared/Pagination'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import {
  useProjectVariables,
  useCreateProjectVariable,
  useUpdateProjectVariable,
  useDeleteProjectVariable,
} from '@/lib/react-query/hooks'
import { GitConfigurationCard } from './GitConfigurationCard'
import { getApiEndpoint } from '@/lib/appwrite/sdk'

// Copyable Input Component
interface CopyableInputProps {
  value: string
  label: string
  className?: string
}

function CopyableInput({ value, label, className }: CopyableInputProps) {
  const [copied, setCopied] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)

  const handleCopy = () => {
    navigator.clipboard.writeText(value)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className={cn('relative', className)}>
      <Input
        ref={inputRef}
        value={value}
        readOnly
        className="pr-10 font-mono text-[13px]"
      />
      <button
        type="button"
        onClick={handleCopy}
        className="absolute right-2 top-1/2 -translate-y-1/2 flex items-center justify-center h-7 w-7 rounded-md hover:bg-accent transition-colors"
        aria-label={`Copy ${label}`}
      >
        {copied ? (
          <Check className="h-4 w-4 text-emerald-500" />
        ) : (
          <Copy className="h-4 w-4 text-muted-foreground" />
        )}
      </button>
    </div>
  )
}

// Dependencies enum for query invalidation
enum Dependencies {
  PROJECT = 'project',
  ORGANIZATION = 'organization',
  PROJECT_VARIABLES = 'project-variables',
  PROJECT_INSTALLATIONS = 'project-installations',
}

interface ProjectSettingsOverviewProps {
  projectId: string
}

export function ProjectSettingsOverview({
  projectId,
}: ProjectSettingsOverviewProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const search = useSearch({ from: '/_public/projects/$projectId/settings' })

  // Get project data
  const { project, isLoading: projectLoading } = useProject(projectId)

  // Get raw project data for services
  const { data: rawProjectData } = useQuery({
    queryKey: ['project', projectId],
    queryFn: async () => {
      const response = await sdk.forConsole.projects.get({ projectId })
      return response
    },
    enabled: !!projectId,
    staleTime: 5 * 60 * 1000,
  })

  // Check permissions (assuming project has permissions info)
  // For now, we'll assume canWriteProjects is true if project exists
  // In real implementation, this should come from project data or account permissions
  const canWriteProjects = useMemo(() => {
    // TODO: Get actual permission from project or account
    return true // Placeholder - should check actual permissions
  }, [])

  // State for name update
  const [projectName, setProjectName] = useState('')

  // State for services
  const [updatingServices, setUpdatingServices] = useState<Set<string>>(
    new Set(),
  )
  const [services, setServices] = useState<Record<string, boolean>>({})

  // State for variables
  const [variablesPage, setVariablesPage] = useState(0)
  const variablesLimit = 10

  // State for git installations
  const [installationsPage, setInstallationsPage] = useState(0)
  const installationsLimit = 25

  // State for organization transfer
  const [selectedOrgId, setSelectedOrgId] = useState('')

  // State for delete confirmation
  const [deleteConfirmation, setDeleteConfirmation] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // Initialize project name when project loads
  useEffect(() => {
    if (project) {
      setProjectName(project.name)
    }
  }, [project])

  // Initialize services from raw project data
  useEffect(() => {
    if (rawProjectData) {
      const projectData = rawProjectData as Models.Project
      // Services are stored as serviceStatusFor{ServiceName} properties
      setServices({
        account:
          projectData.serviceStatusForAccount !== undefined
            ? projectData.serviceStatusForAccount
            : true,
        avatars:
          projectData.serviceStatusForAvatars !== undefined
            ? projectData.serviceStatusForAvatars
            : true,
        databases:
          projectData.serviceStatusForDatabases !== undefined
            ? projectData.serviceStatusForDatabases
            : true,
        functions:
          projectData.serviceStatusForFunctions !== undefined
            ? projectData.serviceStatusForFunctions
            : true,
        locale:
          projectData.serviceStatusForLocale !== undefined
            ? projectData.serviceStatusForLocale
            : true,
        messaging:
          projectData.serviceStatusForMessaging !== undefined
            ? projectData.serviceStatusForMessaging
            : true,
        storage:
          projectData.serviceStatusForStorage !== undefined
            ? projectData.serviceStatusForStorage
            : true,
        teams:
          projectData.serviceStatusForTeams !== undefined
            ? projectData.serviceStatusForTeams
            : true,
        users:
          projectData.serviceStatusForUsers !== undefined
            ? projectData.serviceStatusForUsers
            : true,
      })
    }
  }, [rawProjectData])

  // Handle URL query parameters for installation alerts
  useEffect(() => {
    const searchParams = search as { alert?: string }
    const alert = searchParams?.alert
    if (alert === 'installation-created') {
      toast.success('Git installation has imported to your project')
      navigate({
        search: ((prev: unknown) => {
          const o: Record<string, string | undefined> =
            prev && typeof prev === 'object'
              ? { ...(prev as Record<string, string | undefined>) }
              : {}
          delete o.alert
          return o
        }) as (
          prev: Record<string, string | undefined>,
        ) => Record<string, string | undefined>,
        replace: true,
      })
    } else if (alert === 'installation-updated') {
      toast.success('Git installation has been successfully updated')
      navigate({
        search: ((prev: unknown) => {
          const o: Record<string, string | undefined> =
            prev && typeof prev === 'object'
              ? { ...(prev as Record<string, string | undefined>) }
              : {}
          delete o.alert
          return o
        }) as (
          prev: Record<string, string | undefined>,
        ) => Record<string, string | undefined>,
        replace: true,
      })
    }
  }, [search, navigate])

  // Get project endpoint (centralized in SDK)
  const projectEndpoint = useMemo(
    () => getApiEndpoint(project?.region),
    [project?.region],
  )

  // Navigate to API keys page
  const handleViewApiKeys = () => {
    navigate({
      to: '/projects/$projectId/api-keys',
      params: { projectId },
    })
  }

  // Mutation to update project name
  const updateNameMutation = useMutation({
    mutationFn: async (name: string) => {
      // Validate name length (1-128 chars)
      const trimmedName = name.trim()
      if (trimmedName.length < 1 || trimmedName.length > 128) {
        throw new Error('Name must be between 1 and 128 characters')
      }
      await sdk.forConsole.projects.update({ projectId, name: trimmedName })
    },
    onSuccess: () => {
      toast.success('Project name has been updated')
      queryClient.invalidateQueries({
        queryKey: [Dependencies.PROJECT, projectId],
      })
      queryClient.invalidateQueries({ queryKey: [Dependencies.ORGANIZATION] })
      // Track analytics: Submit.ProjectUpdateName
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, 'Failed to update project name'))
    },
  })

  // Mutation to update service status
  const updateServiceMutation = useMutation({
    mutationFn: async ({
      service,
      status,
    }: {
      service: string
      status: boolean
    }) => {
      const response = await sdk.forConsole.projects.updateServiceStatus({
        projectId,
        service: service as ApiService,
        status,
      })
      return { response, service, status }
    },
    onSuccess: (data) => {
      const { service, status } = data
      const serviceLabel = service.charAt(0).toUpperCase() + service.slice(1)
      toast.success(
        `${serviceLabel} service has been ${status ? 'enabled' : 'disabled'}`,
      )

      // Update services state from response
      const projectData = data.response as Models.Project
      // Services are stored as serviceStatusFor{ServiceName} properties
      const servicePropertyMap: Record<string, string> = {
        account: 'serviceStatusForAccount',
        avatars: 'serviceStatusForAvatars',
        databases: 'serviceStatusForDatabases',
        functions: 'serviceStatusForFunctions',
        locale: 'serviceStatusForLocale',
        messaging: 'serviceStatusForMessaging',
        storage: 'serviceStatusForStorage',
        teams: 'serviceStatusForTeams',
        users: 'serviceStatusForUsers',
      }
      const serviceProperty = servicePropertyMap[service]
      const projectDataRecord = projectData as Record<string, unknown>
      if (serviceProperty && projectDataRecord[serviceProperty] !== undefined) {
        setServices((prev) => ({
          ...prev,
          [service]: projectDataRecord[serviceProperty] as boolean,
        }))
      } else {
        // Fallback to the status we just set
        setServices((prev) => ({
          ...prev,
          [service]: status,
        }))
      }

      // Invalidate queries to refresh project data
      queryClient.invalidateQueries({ queryKey: ['project', projectId] })
      queryClient.invalidateQueries({
        queryKey: [Dependencies.PROJECT, projectId],
      })

      setUpdatingServices((prev) => {
        const next = new Set(prev)
        next.delete(service)
        return next
      })
      // Track analytics: Submit.ProjectService
    },
    onError: (error: Error, variables) => {
      toast.error(getErrorMessage(error, 'Failed to update service'))
      // Revert switch state
      setServices((prev) => ({
        ...prev,
        [variables.service]: !variables.status,
      }))
      setUpdatingServices((prev) => {
        const next = new Set(prev)
        next.delete(variables.service)
        return next
      })
    },
  })

  // Mutation to update all services
  const updateAllServicesMutation = useMutation({
    mutationFn: async (status: boolean) => {
      const response = await sdk.forConsole.projects.updateServiceStatusAll({
        projectId,
        status,
      })
      return { response, status }
    },
    onSuccess: (data) => {
      const { status } = data
      toast.success(
        `All services for ${project?.name || 'project'} has been ${status ? 'enabled' : 'disabled'}.`,
      )

      // Update all services state from response
      const projectData = data.response as Models.Project
      // Services are stored as serviceStatusFor{ServiceName} properties
      setServices({
        account:
          projectData.serviceStatusForAccount !== undefined
            ? projectData.serviceStatusForAccount
            : status,
        avatars:
          projectData.serviceStatusForAvatars !== undefined
            ? projectData.serviceStatusForAvatars
            : status,
        databases:
          projectData.serviceStatusForDatabases !== undefined
            ? projectData.serviceStatusForDatabases
            : status,
        functions:
          projectData.serviceStatusForFunctions !== undefined
            ? projectData.serviceStatusForFunctions
            : status,
        locale:
          projectData.serviceStatusForLocale !== undefined
            ? projectData.serviceStatusForLocale
            : status,
        messaging:
          projectData.serviceStatusForMessaging !== undefined
            ? projectData.serviceStatusForMessaging
            : status,
        storage:
          projectData.serviceStatusForStorage !== undefined
            ? projectData.serviceStatusForStorage
            : status,
        teams:
          projectData.serviceStatusForTeams !== undefined
            ? projectData.serviceStatusForTeams
            : status,
        users:
          projectData.serviceStatusForUsers !== undefined
            ? projectData.serviceStatusForUsers
            : status,
      })

      // Invalidate queries to refresh project data
      queryClient.invalidateQueries({ queryKey: ['project', projectId] })
      queryClient.invalidateQueries({
        queryKey: [Dependencies.PROJECT, projectId],
      })
      // Track analytics: Submit.ProjectService
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, 'Failed to update services'))
    },
  })

  // Mutation to transfer project
  const transferProjectMutation = useMutation({
    mutationFn: async (teamId: string) => {
      await sdk.forConsole.projects.updateTeam({ projectId, teamId })
    },
    onSuccess: (_, teamId) => {
      const oldTeamId = project?.teamId

      // Get organization name from query cache
      const orgsData = queryClient.getQueryData([
        'organizations',
        'console',
      ]) as { teams?: Models.Team[] } | undefined
      const org = orgsData?.teams?.find((t) => t.$id === teamId)
      const orgName = org?.name || 'Organization'

      toast.success(
        `${project?.name || 'Project'} has been transferred to ${orgName}`,
      )

      // Invalidate project query to refresh project data (teamId changed)
      queryClient.invalidateQueries({ queryKey: ['project', projectId] })
      queryClient.invalidateQueries({
        queryKey: [Dependencies.PROJECT, projectId],
      })

      // Invalidate projects list for old organization (if it exists)
      if (oldTeamId) {
        queryClient.invalidateQueries({
          queryKey: ['projects', 'team', oldTeamId],
        })
      }

      // Invalidate projects list for new organization
      queryClient.invalidateQueries({ queryKey: ['projects', 'team', teamId] })

      // Invalidate organizations list (in case project count affects display)
      queryClient.invalidateQueries({ queryKey: ['organizations', 'console'] })

      // Track analytics: Submit.ProjectUpdateTeam
      navigate({
        to: '/organizations/$orgId',
        params: { orgId: teamId },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, 'Failed to transfer project'))
    },
  })

  // Mutation to delete project
  const deleteProjectMutation = useMutation({
    mutationFn: async () => {
      // Must use SDK for project's region
      const regionSdk = sdk.forConsoleIn(project?.region || 'us')
      await regionSdk.projects.delete({ projectId })
    },
    onSuccess: () => {
      toast.success(`${project?.name || 'Project'} has been deleted`)
      // Track analytics: Submit.ProjectDelete
      const orgId = project?.teamId
      if (orgId) {
        queryClient.invalidateQueries({
          queryKey: [Dependencies.ORGANIZATION, orgId],
        })
        navigate({
          to: '/organizations/$orgId',
          params: { orgId },
        })
      }
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, 'Failed to delete project'))
    },
  })

  // Handle service toggle
  const handleServiceToggle = (service: string, checked: boolean) => {
    // Optimistically update UI
    setServices((prev) => ({ ...prev, [service]: checked }))
    setUpdatingServices((prev) => new Set(prev).add(service))
    updateServiceMutation.mutate(
      { service, status: checked },
      {
        onError: () => {
          // Revert on error
          setServices((prev) => ({ ...prev, [service]: !checked }))
        },
      },
    )
  }

  // Handle bulk enable/disable
  const handleBulkServiceUpdate = (status: boolean) => {
    // Update all services in state
    const newServices: Record<string, boolean> = {}
    Object.keys(services).forEach((key) => {
      newServices[key] = status
    })
    setServices(newServices)
    updateAllServicesMutation.mutate(status)
  }

  // Get all services are enabled/disabled
  const allServicesEnabled = useMemo(() => {
    const serviceValues = Object.values(services)
    return serviceValues.length > 0 && serviceValues.every((v) => v === true)
  }, [services])

  const allServicesDisabled = useMemo(() => {
    const serviceValues = Object.values(services)
    return serviceValues.length > 0 && serviceValues.every((v) => v === false)
  }, [services])

  const anyServiceUpdating =
    updatingServices.size > 0 || updateAllServicesMutation.isPending

  // Get organizations for transfer (excluding current)
  const { organizations: allOrganizations, isLoading: organizationsLoading } =
    useOrganizations()
  const organizations = useMemo(() => {
    if (!allOrganizations || !project) return []

    return allOrganizations
      .filter((org) => org.$id !== project.teamId)
      .map((org) => ({
        value: org.$id,
        label: org.name,
      }))
  }, [allOrganizations, project])

  // Get GitHub authorization URL
  const getGitHubAuthUrl = (mode: 'create' | 'update' = 'create') => {
    const endpoint = projectEndpoint
    const alertType =
      mode === 'create' ? 'installation-created' : 'installation-updated'
    const successUrl = `${window.location.origin}/projects/${projectId}/settings?alert=${alertType}`
    const failureUrl = `${window.location.origin}/projects/${projectId}/settings`
    return `${endpoint}/vcs/github/authorize?project=${projectId}&success=${encodeURIComponent(successUrl)}&failure=${encodeURIComponent(failureUrl)}&mode=admin`
  }

  if (projectLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (!project) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <p className="text-[14px] font-medium text-foreground">
            Project not found
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-4 sm:px-6">
      {/* API Credentials Section - Always visible */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            API credentials
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-4">
            Access Appwrite services using this project's API Endpoint and
            Project ID.
          </p>
          <div className="space-y-4">
            <div>
              <Label className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5 block">
                Project ID
              </Label>
              <CopyableInput value={project.$id} label="Project ID" />
            </div>
            <div>
              <Label className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5 block">
                API Endpoint
              </Label>
              <CopyableInput value={projectEndpoint} label="API Endpoint" />
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            variant="secondary"
            size="sm"
            className="h-9 text-[13px]"
            onClick={handleViewApiKeys}
          >
            View API keys
          </Button>
        </div>
      </div>

      {/* Conditional sections - only if canWriteProjects */}
      {canWriteProjects && (
        <>
          {/* Update Name Section */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Name
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <Label
                htmlFor="name"
                className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5 block"
              >
                Name
              </Label>
              <Input
                id="name"
                value={projectName}
                onChange={(e) => setProjectName(e.target.value)}
                placeholder="Enter name"
                className="mt-2 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  projectName === project.name ||
                  !projectName.trim() ||
                  projectName.trim().length < 1 ||
                  projectName.trim().length > 128 ||
                  updateNameMutation.isPending
                }
                onClick={() => {
                  const trimmedName = projectName.trim()
                  if (
                    trimmedName &&
                    trimmedName !== project.name &&
                    trimmedName.length >= 1 &&
                    trimmedName.length <= 128
                  ) {
                    updateNameMutation.mutate(trimmedName)
                  }
                }}
              >
                Update
              </Button>
            </div>
          </div>

          {/* Update Services Section */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Services
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <p className="text-[13px] text-muted-foreground mb-4">
                Choose services you wish to enable or disable for the client
                API. When disabled, the services are not accessible to client
                SDKs but remain accessible to server SDKs.
              </p>

              {/* Bulk Actions */}
              <div className="flex items-center gap-2 mb-4">
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 text-[12px]"
                  disabled={anyServiceUpdating || allServicesEnabled}
                  onClick={() => {
                    // Open confirmation dialog for enable all
                    // For now, directly update
                    handleBulkServiceUpdate(true)
                  }}
                >
                  Enable all
                </Button>
                <Separator orientation="vertical" className="h-4" />
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 text-[12px]"
                  disabled={anyServiceUpdating || allServicesDisabled}
                  onClick={() => {
                    // Open confirmation dialog for disable all
                    // For now, directly update
                    handleBulkServiceUpdate(false)
                  }}
                >
                  Disable all
                </Button>
              </div>

              {/* Service Cards */}
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {Object.entries(services)
                  .filter(([service]) =>
                    [
                      'account',
                      'avatars',
                      'databases',
                      'functions',
                      'locale',
                      'messaging',
                      'storage',
                      'teams',
                      'users',
                    ].includes(service),
                  )
                  .map(([service, enabled]) => {
                    const serviceLabels: Record<string, string> = {
                      account: 'Account',
                      avatars: 'Avatars',
                      databases: 'Databases',
                      functions: 'Functions',
                      locale: 'Locale',
                      messaging: 'Messaging',
                      storage: 'Storage',
                      teams: 'Teams',
                      users: 'Users',
                    }
                    const serviceIcons: Record<string, typeof User> = {
                      account: User,
                      avatars: UserCircle,
                      databases: Database,
                      functions: Zap,
                      locale: Globe,
                      messaging: MessageSquare,
                      storage: Folder,
                      teams: Building2,
                      users: Users,
                    }
                    const Icon = serviceIcons[service] || User
                    const isUpdating = updatingServices.has(service)
                    return (
                      <div
                        key={service}
                        className={cn(
                          'rounded-lg border border-border bg-card/50 p-4 transition-colors',
                          isUpdating && 'opacity-75',
                          !isUpdating && 'hover:bg-card',
                        )}
                      >
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-2">
                            <Icon className="h-4 w-4 text-muted-foreground" />
                            <Label
                              htmlFor={service}
                              className="text-[13px] font-medium text-foreground cursor-pointer"
                            >
                              {serviceLabels[service] ||
                                service.charAt(0).toUpperCase() +
                                  service.slice(1)}
                            </Label>
                          </div>
                          <div className="flex items-center gap-2">
                            {isUpdating && (
                              <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                            )}
                            <Switch
                              id={service}
                              checked={enabled}
                              onCheckedChange={(checked) =>
                                handleServiceToggle(service, checked)
                              }
                              disabled={isUpdating}
                            />
                          </div>
                        </div>
                      </div>
                    )
                  })}
              </div>
            </div>
          </div>

          {/* Git Configuration Section */}
          <GitConfigurationCard
            projectId={projectId}
            page={installationsPage}
            limit={installationsLimit}
            onPageChange={setInstallationsPage}
            getGitHubAuthUrl={getGitHubAuthUrl}
            isSelfHosted={false} // TODO: Get from organization plan
            isVcsEnabled={true} // TODO: Get from project settings
          />

          {/* MCP Server Section */}
          <MCPSection />

          {/* Global Variables Section */}
          <GlobalVariablesSection
            projectId={projectId}
            page={variablesPage}
            limit={variablesLimit}
            onPageChange={setVariablesPage}
            projectName={project?.name}
          />

          {/* Change Organization Section */}
          <ChangeOrganizationSection
            project={project}
            organizations={organizations}
            organizationsLoading={organizationsLoading}
            selectedOrgId={selectedOrgId}
            onOrgChange={setSelectedOrgId}
            onTransfer={transferProjectMutation}
          />

          {/* Delete Project Section */}
          <DeleteProjectSection
            project={project}
            deleteConfirmation={deleteConfirmation}
            onDeleteConfirmationChange={setDeleteConfirmation}
            deleteDialogOpen={deleteDialogOpen}
            onDeleteDialogOpenChange={setDeleteDialogOpen}
            onDelete={deleteProjectMutation}
          />
        </>
      )}
    </div>
  )
}

// Global Variables Section Component
interface GlobalVariablesSectionProps {
  projectId: string
  page: number
  limit: number
  onPageChange: (page: number) => void
  projectName?: string
}

function GlobalVariablesSection({
  projectId,
  page,
  limit,
  onPageChange,
  projectName,
}: GlobalVariablesSectionProps) {
  const { variables, total, isLoading } = useProjectVariables(
    projectId,
    page,
    limit,
  )
  const createMutation = useCreateProjectVariable(projectId)
  const updateMutation = useUpdateProjectVariable(projectId)
  const deleteMutation = useDeleteProjectVariable(projectId)

  return (
    <VariablesSettingsCard
      title="Global variables"
      description="Set the environment variables or secret keys that will be passed to all Functions and Sites within your project."
      variables={variables}
      total={total}
      isLoading={isLoading}
      createMutation={createMutation}
      updateMutation={updateMutation}
      deleteMutation={deleteMutation}
      scopeLabel={`${projectName || 'Project'} global`}
      page={page}
      limit={limit}
      onPageChange={onPageChange}
      itemLabel="variables"
    />
  )
}

// Change Organization Section Component
interface ChangeOrganizationSectionProps {
  project: Pick<Models.Project, 'name' | 'teamId' | '$id' | 'region'> & {
    platforms?: unknown[]
  }
  organizations: Array<{ value: string; label: string }>
  organizationsLoading: boolean
  selectedOrgId: string
  onOrgChange: (orgId: string) => void
  onTransfer: { isPending: boolean; mutate: (teamId: string) => void }
}

function ChangeOrganizationSection({
  project,
  organizations,
  organizationsLoading,
  selectedOrgId,
  onOrgChange,
  onTransfer,
}: ChangeOrganizationSectionProps) {
  const [transferDialogOpen, setTransferDialogOpen] = useState(false)
  const hasNoTargetOrgs = !organizationsLoading && organizations.length === 0
  const isMoveDisabled =
    hasNoTargetOrgs ||
    !selectedOrgId ||
    selectedOrgId === project.teamId ||
    onTransfer.isPending

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Transfer project
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-4">
            To transfer this project, you must be a member of both the current
            and target organization. Select a destination below.
          </p>
          {hasNoTargetOrgs && (
            <Alert className="mb-4">
              <AlertTriangle className="h-4 w-4" />
              <AlertDescription className="text-[13px]">
                You do not have any organizations you can transfer this project
                to. Create or join another organization to transfer.
              </AlertDescription>
            </Alert>
          )}
          <Label
            htmlFor="organization"
            className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5 block"
          >
            Move to
          </Label>
          <Select
            value={selectedOrgId}
            onValueChange={onOrgChange}
            disabled={organizationsLoading}
          >
            <SelectTrigger id="organization" className="mt-2 h-9 max-w-sm">
              <SelectValue
                placeholder={
                  organizationsLoading
                    ? 'Loading organizations...'
                    : 'Select destination'
                }
              />
            </SelectTrigger>
            <SelectContent>
              {organizations.length === 0 ? (
                <div className="px-2 py-1.5 text-[13px] text-muted-foreground">
                  {organizationsLoading
                    ? 'Loading...'
                    : 'No other organizations available'}
                </div>
              ) : (
                organizations.map((org) => (
                  <SelectItem key={org.value} value={org.value}>
                    {org.label}
                  </SelectItem>
                ))
              )}
            </SelectContent>
          </Select>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="inline-block">
                  <Button
                    size="sm"
                    className="h-9 text-[13px]"
                    disabled={isMoveDisabled}
                    onClick={() => setTransferDialogOpen(true)}
                  >
                    Transfer project
                  </Button>
                </span>
              </TooltipTrigger>
              <TooltipContent>
                {hasNoTargetOrgs
                  ? 'You do not have any organizations you can transfer this project to.'
                  : !selectedOrgId || selectedOrgId === project.teamId
                    ? 'Select a different organization to transfer to.'
                    : 'Transfer this project to the selected organization'}
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        </div>
      </div>

      {/* Transfer Confirmation Dialog */}
      <Dialog open={transferDialogOpen} onOpenChange={setTransferDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Transfer project {project.name}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Consider the following before transferring your project:
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-3">
            <div className="flex gap-3">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
                <Shield className="h-4 w-4 text-muted-foreground" />
              </div>
              <div>
                <p className="text-[13px] font-medium text-foreground">
                  Permissions
                </p>
                <p className="text-[12px] text-muted-foreground mt-0.5">
                  Depending on your role in the target organization, your level
                  of access may change after transfer.
                </p>
              </div>
            </div>
            <div className="flex gap-3">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
                <Users className="h-4 w-4 text-muted-foreground" />
              </div>
              <div>
                <p className="text-[13px] font-medium text-foreground">
                  Access
                </p>
                <p className="text-[12px] text-muted-foreground mt-0.5">
                  Members who are not part of the destination organization will
                  lose access and must be invited to the new organization to
                  regain access.
                </p>
              </div>
            </div>
            <div className="flex gap-3">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
                <CreditCard className="h-4 w-4 text-muted-foreground" />
              </div>
              <div>
                <p className="text-[13px] font-medium text-foreground">
                  Features and usage
                </p>
                <p className="text-[12px] text-muted-foreground mt-0.5">
                  The target organization’s pricing plan may limit features or
                  usage (e.g. executions, storage, or team size) for this
                  project.
                </p>
              </div>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setTransferDialogOpen(false)}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={onTransfer.isPending}
              onClick={() => {
                if (selectedOrgId) {
                  onTransfer.mutate(selectedOrgId)
                  setTransferDialogOpen(false)
                }
              }}
            >
              Transfer project
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}

// Delete Project Section Component
interface DeleteProjectSectionProps {
  project: Pick<Models.Project, 'name' | 'teamId' | '$id' | 'region'>
  deleteConfirmation: string
  onDeleteConfirmationChange: (value: string) => void
  deleteDialogOpen: boolean
  onDeleteDialogOpenChange: (open: boolean) => void
  onDelete: { isPending: boolean; mutate: (v?: void) => void }
}

function DeleteProjectSection({
  project,
  deleteConfirmation,
  onDeleteConfirmationChange,
  deleteDialogOpen,
  onDeleteDialogOpenChange,
  onDelete,
}: DeleteProjectSectionProps) {
  return (
    <>
      <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Delete project
          </h3>
        </div>
        <div className="border-t border-destructive/20" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            Permanently delete this project and all associated data. This action
            cannot be undone.
          </p>

          {/* Project Info Summary */}
          {project && (
            <div className="flex items-center gap-3 mt-4">
              <InitialsAvatar name={project.name} size="md" />
              <div className="flex-1 min-w-0">
                <p className="text-[14px] font-medium text-foreground truncate">
                  {project.name}
                </p>
                {project.region && (
                  <p className="text-[12px] text-muted-foreground">
                    Region: {project.region}
                  </p>
                )}
              </div>
            </div>
          )}
        </div>
        <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
          <Dialog
            open={deleteDialogOpen}
            onOpenChange={onDeleteDialogOpenChange}
          >
            <DialogTrigger asChild>
              <Button
                variant="destructive"
                size="sm"
                className="h-9 text-[13px]"
              >
                Delete project
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md p-0">
              <DialogHeader className="px-6 pt-6 text-left">
                <DialogTitle>Delete Project</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  Are you sure you want to delete{' '}
                  {project && (
                    <span className="font-medium text-foreground">
                      {project.name}
                    </span>
                  )}{' '}
                  and all its databases, functions, and files? This action
                  cannot be undone.
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0">
                <div className="rounded-lg border border-border bg-muted/50 p-3 mb-4 mt-2">
                  {project && (
                    <div className="flex items-center gap-3">
                      <InitialsAvatar name={project.name} size="sm" />
                      <div>
                        <p className="text-[13px] font-medium text-foreground">
                          {project.name}
                        </p>
                        {project.region && (
                          <p className="text-[11px] text-muted-foreground">
                            Region: {project.region}
                          </p>
                        )}
                      </div>
                    </div>
                  )}
                </div>
                <label className="text-[13px] text-muted-foreground">
                  Type{' '}
                  {project && (
                    <span className="font-mono font-medium text-foreground bg-muted px-1.5 py-0.5 rounded">
                      {project.name}
                    </span>
                  )}{' '}
                  to confirm
                </label>
                <Input
                  value={deleteConfirmation}
                  onChange={(e) => onDeleteConfirmationChange(e.target.value)}
                  placeholder="Enter project name"
                  className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-red-500/50 focus:ring-0"
                  autoFocus
                />
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => {
                    onDeleteDialogOpenChange(false)
                    onDeleteConfirmationChange('')
                  }}
                >
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={
                    deleteConfirmation !== project.name || onDelete.isPending
                  }
                  onClick={() => {
                    if (deleteConfirmation === project.name) {
                      onDelete.mutate(undefined)
                      onDeleteDialogOpenChange(false)
                    }
                  }}
                >
                  Delete
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>
    </>
  )
}

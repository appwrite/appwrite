import { useState, useEffect, useMemo, useRef } from 'react'
import { useParams, useNavigate, useSearch } from '@tanstack/react-router'
import {
  Key,
  Loader2,
  Plus,
  Code,
  Upload,
  Eye,
  EyeOff,
  Globe,
  Trash2,
  Pencil,
  XCircle,
  MoreHorizontal,
  AlertTriangle,
  Copy,
  Check,
  FileText,
  User,
  Users,
  Database,
  Zap,
  MessageSquare,
  HardDrive,
  Building2,
  UserCircle,
  ArrowUp,
  Download,
  Shield,
  CreditCard,
} from 'lucide-react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { useProject, useOrganizations } from '@/lib/react-query/hooks'
import { getMCPIDEs } from '@/lib/config/ide'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
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
import { formatDateTime } from '@/lib/date-utils'
import { cn } from '@/lib/utils'
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
import { Textarea } from '@/components/ui/textarea'
import { Checkbox } from '@/components/ui/checkbox'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Pagination } from '@/components/global/shared/Pagination'
import { VariableEditor } from '@/components/global/shared/VariableEditor'
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
      const response = await sdk.forConsole.projects.get(projectId)
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
  }, [project])

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
      const projectData = rawProjectData as any
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
    const alert = (search as any)?.alert
    if (alert === 'installation-created') {
      toast.success('Git installation has imported to your project')
      // Remove alert from URL
      navigate({
        search: ((prev: any) => {
          const { alert, ...rest } = prev || {}
          return rest
        }) as any,
        replace: true,
      })
    } else if (alert === 'installation-updated') {
      toast.success('Git installation has been successfully updated')
      // Remove alert from URL
      navigate({
        search: ((prev: any) => {
          const { alert, ...rest } = prev || {}
          return rest
        }) as any,
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
      const projectData = data.response as any
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
      if (serviceProperty && projectData[serviceProperty] !== undefined) {
        setServices((prev) => ({
          ...prev,
          [service]: projectData[serviceProperty],
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
      const projectData = data.response as any
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
      ]) as any
      const org = orgsData?.teams?.find((t: any) => t.$id === teamId)
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
      await regionSdk.projects.delete(projectId)
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

  // MCP integration links - using centralized IDE config
  const mcpIntegrations = useMemo(() => getMCPIDEs(), [])

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
                      storage: HardDrive,
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
            projectEndpoint={projectEndpoint}
            page={installationsPage}
            limit={installationsLimit}
            onPageChange={setInstallationsPage}
            getGitHubAuthUrl={getGitHubAuthUrl}
            isSelfHosted={false} // TODO: Get from organization plan
            isVcsEnabled={true} // TODO: Get from project settings
          />

          {/* MCP Server Section */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                MCP servers
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <p className="text-[13px] text-muted-foreground mb-4">
                Appwrite offers two MCP servers that allow LLMs to interact with
                Appwrite's API and documentation. Deploy with a single click or
                view the{' '}
                <a
                  href="https://appwrite.io/docs/tooling/mcp"
                  target="_blank"
                  rel="noreferrer"
                  className="text-foreground underline hover:no-underline"
                >
                  docs
                </a>{' '}
                for instructions.
              </p>

              {/* MCP Server Types */}
              <div className="grid gap-3 sm:grid-cols-2 mb-4">
                <a
                  href="https://appwrite.io/docs/tooling/mcp/api"
                  target="_blank"
                  rel="noreferrer"
                  className="rounded-lg border border-border bg-muted/30 p-3 transition-colors hover:bg-muted/50 hover:border-border cursor-pointer"
                >
                  <div className="flex items-start gap-2">
                    <Code className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
                    <div className="flex-1 min-w-0">
                      <p className="text-[13px] font-medium text-foreground mb-1">
                        MCP for API
                      </p>
                      <p className="text-[12px] text-muted-foreground mb-2">
                        Interact with your Appwrite project directly. Create
                        users, manage databases, and perform operations using
                        natural language.
                      </p>
                      <span className="text-[12px] text-foreground">
                        Learn more →
                      </span>
                    </div>
                  </div>
                </a>

                <a
                  href="https://appwrite.io/docs/tooling/mcp/docs"
                  target="_blank"
                  rel="noreferrer"
                  className="rounded-lg border border-border bg-muted/30 p-3 transition-colors hover:bg-muted/50 hover:border-border cursor-pointer"
                >
                  <div className="flex items-start gap-2">
                    <FileText className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
                    <div className="flex-1 min-w-0">
                      <p className="text-[13px] font-medium text-foreground mb-1">
                        MCP for Docs
                      </p>
                      <p className="text-[12px] text-muted-foreground mb-2">
                        Access comprehensive Appwrite documentation. Get code
                        examples, troubleshooting help, and implementation
                        guidance.
                      </p>
                      <span className="text-[12px] text-foreground">
                        Learn more →
                      </span>
                    </div>
                  </div>
                </a>
              </div>

              {/* Integration Buttons */}
              <div className="mt-4">
                <div className="my-6 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
                  <div className="h-px flex-1 bg-border" />
                  <span className="font-medium text-foreground/80">
                    Apps
                  </span>
                  <div className="h-px flex-1 bg-border" />
                </div>
                <div className="flex flex-wrap items-center justify-center gap-2">
                  {mcpIntegrations.map((ide) => {
                    if (!ide.mcpDocsUrl) return null
                    return (
                      <Button
                        key={ide.id}
                        variant="secondary"
                        size="sm"
                        className="h-9 text-[13px]"
                        asChild
                      >
                        <a href={ide.mcpDocsUrl} target="_blank" rel="noreferrer">
                          <img
                            src={ide.iconPath}
                            alt=""
                            className="mr-1.5 h-4 w-4 brightness-0 dark:brightness-100"
                          />
                          {ide.name}
                        </a>
                      </Button>
                    )
                  })}
                </div>
              </div>
            </div>
          </div>

          {/* Global Variables Section */}
          <GlobalVariablesSection
            projectId={projectId}
            page={variablesPage}
            limit={variablesLimit}
            onPageChange={setVariablesPage}
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
}

function GlobalVariablesSection({
  projectId,
  page,
  limit,
  onPageChange,
}: GlobalVariablesSectionProps) {
  const { project } = useProject(projectId)
  const { variables, total, isLoading } = useProjectVariables(
    projectId,
    page,
    limit,
  )
  const createMutation = useCreateProjectVariable(projectId)
  const updateMutation = useUpdateProjectVariable(projectId)
  const deleteMutation = useDeleteProjectVariable(projectId)

  // Modal states
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [showUpdateModal, setShowUpdateModal] = useState(false)
  const [showDeleteModal, setShowDeleteModal] = useState(false)
  const [showSecretModal, setShowSecretModal] = useState(false)
  const [showEditorModal, setShowEditorModal] = useState(false)
  const [showImportModal, setShowImportModal] = useState(false)
  const [selectedVar, setSelectedVar] = useState<any>(null)

  // Create form state
  const [createPairs, setCreatePairs] = useState<
    Array<{ key: string; value: string }>
  >([{ key: '', value: '' }])
  const [createSecret, setCreateSecret] = useState(false)

  // Update form state
  const [updateValue, setUpdateValue] = useState('')
  const [updateSecret, setUpdateSecret] = useState(false)

  // Import state
  const [importFile, setImportFile] = useState<File | null>(null)
  const [importSecret, setImportSecret] = useState(false)
  const [importError, setImportError] = useState('')

  // Editor state
  const [editorContent, setEditorContent] = useState('')
  const [editorFormat, setEditorFormat] = useState<'env' | 'json'>('env')
  const [editorError, setEditorError] = useState('')

  // Delete error
  const [deleteError, setDeleteError] = useState('')

  // Pagination - convert 0-indexed page to 1-indexed for Pagination component
  const currentPage = page + 1

  // Parse .env file content
  const parseEnvFile = (content: string): Record<string, string> => {
    const result: Record<string, string> = {}
    const lines = content.split('\n')

    for (const line of lines) {
      const trimmed = line.trim()
      if (!trimmed || trimmed.startsWith('#')) continue

      // Match pattern: key=value or key:value
      const match = trimmed.match(/^([^=:#]+?)[=:](.*)$/)
      if (match) {
        const key = match[1].trim()
        let value = match[2].trim()
        // Remove quotes
        value = value.replace(/^["']|["']$/g, '')
        if (key) {
          result[key] = value
        }
      }
    }

    return result
  }

  // Convert variables to ENV format
  const variablesToEnv = (vars: any[]): string => {
    return vars
      .filter((v) => !v.secret)
      .map((v) => `${v.key}=${v.value}`)
      .join('\n')
  }

  // Convert variables to JSON format
  const variablesToJson = (vars: any[]): string => {
    const obj: Record<string, string> = {}
    vars
      .filter((v) => !v.secret)
      .forEach((v) => {
        obj[v.key] = v.value
      })
    return JSON.stringify(obj, null, 2)
  }

  // Convert ENV to object
  const envToObject = (content: string): Record<string, string> => {
    return parseEnvFile(content)
  }

  // Convert JSON to object
  const jsonToObject = (content: string): Record<string, string> => {
    try {
      return JSON.parse(content)
    } catch (e) {
      throw new Error('Invalid JSON format')
    }
  }

  // Initialize editor content
  useEffect(() => {
    if (showEditorModal && variables.length > 0) {
      const editableVars = variables.filter((v) => !v.secret)
      if (editorFormat === 'env') {
        setEditorContent(variablesToEnv(editableVars))
      } else {
        setEditorContent(variablesToJson(editableVars))
      }
    } else if (showEditorModal) {
      setEditorContent(editorFormat === 'env' ? '' : '{}')
    }
  }, [showEditorModal, editorFormat, variables])

  // Handle create variable
  const handleCreate = async () => {
    // Validate
    for (const pair of createPairs) {
      if (!pair.key.trim()) {
        toast.error('All variable keys are required')
        return
      }
      if (pair.value.length > 8192) {
        toast.error(
          `Variable ${pair.key} is longer than 8192 allowed characters`,
        )
        return
      }
    }

    try {
      // Create all variables
      await Promise.all(
        createPairs
          .filter((p) => p.key.trim())
          .map((pair) =>
            createMutation.mutateAsync({
              key: pair.key.trim(),
              value: pair.value,
              secret: createSecret,
            }),
          ),
      )

      toast.success(
        `${project?.name || 'Project'} global variable has been created.`,
      )
      setShowCreateModal(false)
      setCreatePairs([{ key: '', value: '' }])
      setCreateSecret(false)
    } catch (error: any) {
      toast.error(getErrorMessage(error, 'Failed to create variable'))
    }
  }

  // Handle update variable
  const handleUpdate = async () => {
    if (!selectedVar) return

    if (updateValue.length > 8192) {
      toast.error(`Variable value is longer than 8192 allowed characters`)
      return
    }

    try {
      await updateMutation.mutateAsync({
        variableId: selectedVar.$id,
        key: selectedVar.key,
        value: updateValue,
        secret: updateSecret,
      })

      toast.success(
        `${project?.name || 'Project'} global variable has been updated.`,
      )
      setShowUpdateModal(false)
      setSelectedVar(null)
      setUpdateValue('')
      setUpdateSecret(false)
    } catch (error: any) {
      toast.error(getErrorMessage(error, 'Failed to update variable'))
    }
  }

  // Handle delete variable
  const handleDelete = async () => {
    if (!selectedVar) return

    setDeleteError('')
    try {
      await deleteMutation.mutateAsync(selectedVar.$id)
      toast.success(
        `${project?.name || 'Project'} global variable has been deleted.`,
      )
      setShowDeleteModal(false)
      setSelectedVar(null)
    } catch (error: any) {
      setDeleteError(getErrorMessage(error, 'Failed to delete variable'))
    }
  }

  // Handle mark as secret
  const handleMarkSecret = async () => {
    if (!selectedVar) return

    try {
      await updateMutation.mutateAsync({
        variableId: selectedVar.$id,
        key: selectedVar.key,
        value: selectedVar.value || '',
        secret: true,
      })

      toast.success(
        `${project?.name || 'Project'} global variable has been marked as secret.`,
      )
      setShowSecretModal(false)
      setSelectedVar(null)
    } catch (error: any) {
      toast.error(getErrorMessage(error, 'Failed to mark variable as secret'))
    }
  }

  // Handle import .env
  const handleImport = async () => {
    if (!importFile) {
      setImportError('No file selected')
      return
    }

    setImportError('')
    try {
      const text = await importFile.text()
      const parsed = parseEnvFile(text)

      if (Object.keys(parsed).length === 0) {
        setImportError('No variables found')
        return
      }

      // Validate values
      for (const [key, value] of Object.entries(parsed)) {
        if (value.length > 8192) {
          setImportError(
            `Variable ${key} is longer than 8192 allowed characters`,
          )
          return
        }
      }

      // Create or update variables
      const existingKeys = new Set(variables.map((v) => v.key))
      const promises: Promise<any>[] = []

      for (const [key, value] of Object.entries(parsed)) {
        if (existingKeys.has(key)) {
          const existing = variables.find((v) => v.key === key)
          if (existing) {
            promises.push(
              updateMutation.mutateAsync({
                variableId: existing.$id,
                key,
                value,
                secret: importSecret,
              }),
            )
          }
        } else {
          promises.push(
            createMutation.mutateAsync({
              key,
              value,
              secret: importSecret,
            }),
          )
        }
      }

      await Promise.all(promises)
      toast.success('Variables have been uploaded.')
      setShowImportModal(false)
      setImportFile(null)
      setImportSecret(false)
    } catch (error: any) {
      setImportError(getErrorMessage(error, 'Failed to import variables'))
    }
  }

  // Handle editor save
  const handleEditorSave = async () => {
    setEditorError('')

    try {
      // Parse content
      let parsed: Record<string, string>
      if (editorFormat === 'env') {
        parsed = envToObject(editorContent)
      } else {
        parsed = jsonToObject(editorContent)
      }

      // Validate values
      for (const [key, value] of Object.entries(parsed)) {
        if (value.length > 8192) {
          setEditorError(
            `Variable ${key} is longer than 8192 allowed characters`,
          )
          return
        }
      }

      // Get editable variables (non-secret)
      const editableVars = variables.filter((v) => !v.secret)
      const secretVars = variables.filter((v) => v.secret)
      const secretKeys = new Set(secretVars.map((v) => v.key))

      // Update existing variables
      const updatePromises: Promise<any>[] = []
      const deletePromises: Promise<any>[] = []

      for (const variable of editableVars) {
        if (parsed[variable.key] === undefined) {
          // Variable removed
          deletePromises.push(deleteMutation.mutateAsync(variable.$id))
        } else if (parsed[variable.key] !== variable.value) {
          // Variable changed
          updatePromises.push(
            updateMutation.mutateAsync({
              variableId: variable.$id,
              key: variable.key,
              value: parsed[variable.key],
              secret: false,
            }),
          )
        }
      }

      // Create new variables
      const createPromises: Promise<any>[] = []
      for (const [key, value] of Object.entries(parsed)) {
        const existsInEditable = editableVars.some((v) => v.key === key)
        const existsInSecret = secretKeys.has(key)
        if (!existsInEditable && !existsInSecret) {
          createPromises.push(
            createMutation.mutateAsync({
              key,
              value,
              secret: false,
            }),
          )
        }
      }

      await Promise.all([
        ...updatePromises,
        ...deletePromises,
        ...createPromises,
      ])
      toast.success('Variables have been updated.')
      setShowEditorModal(false)
      setEditorContent('')
    } catch (error: any) {
      setEditorError(getErrorMessage(error, 'Failed to save variables'))
    }
  }

  // Handle download
  const handleDownload = () => {
    const editableVars = variables.filter((v) => !v.secret)
    let content = ''
    let filename = ''

    if (editorFormat === 'env') {
      content = variablesToEnv(editableVars)
      filename = 'variables.env'
    } else {
      content = variablesToJson(editableVars)
      filename = 'variables.json'
    }

    const blob = new Blob([content], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    URL.revokeObjectURL(url)
  }

  // Handle copy to clipboard
  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(editorContent)
      toast.success('Copied to clipboard')
    } catch (error) {
      toast.error('Failed to copy to clipboard')
    }
  }

  // Handle format switch in editor
  const handleFormatSwitch = (format: 'env' | 'json') => {
    if (format === editorFormat) return

    try {
      let parsed: Record<string, string>
      if (editorFormat === 'env') {
        parsed = envToObject(editorContent)
      } else {
        parsed = jsonToObject(editorContent)
      }

      if (format === 'env') {
        setEditorContent(
          Object.entries(parsed)
            .map(([k, v]) => `${k}=${v}`)
            .join('\n'),
        )
      } else {
        setEditorContent(JSON.stringify(parsed, null, 2))
      }
      setEditorFormat(format)
    } catch (error) {
      // If conversion fails, just switch format and use current variables
      const editableVars = variables.filter((v) => !v.secret)
      if (format === 'env') {
        setEditorContent(variablesToEnv(editableVars))
      } else {
        setEditorContent(variablesToJson(editableVars))
      }
      setEditorFormat(format)
    }
  }

  // Reset modals when they close
  useEffect(() => {
    if (!showCreateModal) {
      setCreatePairs([{ key: '', value: '' }])
      setCreateSecret(false)
    }
  }, [showCreateModal])

  useEffect(() => {
    if (!showUpdateModal) {
      setSelectedVar(null)
      setUpdateValue('')
      setUpdateSecret(false)
    } else if (selectedVar) {
      setUpdateValue(selectedVar.secret ? '' : selectedVar.value || '')
      setUpdateSecret(selectedVar.secret || false)
    }
  }, [showUpdateModal, selectedVar])

  useEffect(() => {
    if (!showDeleteModal) {
      setSelectedVar(null)
      setDeleteError('')
    }
  }, [showDeleteModal])

  useEffect(() => {
    if (!showSecretModal) {
      setSelectedVar(null)
    }
  }, [showSecretModal])

  useEffect(() => {
    if (!showImportModal) {
      setImportFile(null)
      setImportSecret(false)
      setImportError('')
    }
  }, [showImportModal])

  useEffect(() => {
    if (!showEditorModal) {
      setEditorContent('')
      setEditorError('')
      setEditorFormat('env')
    }
  }, [showEditorModal])

  // Variables are already paginated by the API
  const paginatedVariables = variables

  // Copyable text component (for both keys and values)
  const CopyableText = ({
    value,
    hideValue = false,
  }: {
    value: string
    hideValue?: boolean
  }) => {
    const [copied, setCopied] = useState(false)
    const [showValue, setShowValue] = useState(false)

    const handleCopy = () => {
      navigator.clipboard.writeText(value)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    }

    const displayValue = hideValue && !showValue ? '•'.repeat(20) : value

    return (
      <div className="flex items-center gap-2 group cursor-pointer">
        <span
          className={cn(
            'text-[13px] font-mono text-foreground truncate',
            hideValue && 'w-[200px]',
          )}
          title={value}
        >
          {displayValue}
        </span>
        <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
          {hideValue && (
            <button
              type="button"
              onClick={() => setShowValue(!showValue)}
              className="text-muted-foreground hover:text-foreground cursor-pointer"
            >
              {showValue ? (
                <EyeOff className="h-3.5 w-3.5" />
              ) : (
                <Eye className="h-3.5 w-3.5" />
              )}
            </button>
          )}
          <button type="button" onClick={handleCopy} className="cursor-pointer">
            {copied ? (
              <Check className="h-3.5 w-3.5 text-emerald-500" />
            ) : (
              <Copy className="h-3.5 w-3.5 text-muted-foreground" />
            )}
          </button>
        </div>
      </div>
    )
  }

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Global variables
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 @container">
          <div className="flex gap-6 @[600px]:flex-row flex-col">
            {/* Left side - Description */}
            <div className="@[600px]:w-64 shrink-0">
              <p className="text-[13px] text-muted-foreground">
                Set the environment variables or secret keys that will be passed
                to all Functions and Sites within your project.
              </p>
            </div>

            {/* Right side - Content */}
            <div className="flex-1 min-w-0">
              {/* Action Buttons */}
              <div className="flex items-center justify-between gap-2 mb-4">
                <div className="flex items-center gap-2">
                  <Button
                    variant="secondary"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => setShowEditorModal(true)}
                    disabled={isLoading}
                  >
                    <Code className="mr-2 h-4 w-4" />
                    Editor
                  </Button>
                  <Button
                    variant="secondary"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => setShowImportModal(true)}
                    disabled={isLoading}
                  >
                    <Upload className="mr-2 h-4 w-4" />
                    Import .env
                  </Button>
                </div>
                {total > 0 && (
                  <Button
                    variant="secondary"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => setShowCreateModal(true)}
                    disabled={isLoading}
                  >
                    <Plus className="mr-2 h-4 w-4" />
                    Create variable
                  </Button>
                )}
              </div>

              {/* Variables Table */}
              {isLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                </div>
              ) : total === 0 ? (
                <div
                  className="text-center py-8 text-[13px] text-muted-foreground cursor-pointer hover:text-foreground transition-colors"
                  onClick={() => setShowCreateModal(true)}
                >
                  Create a global variable to get started
                </div>
              ) : (
                <>
                  <div className="rounded-lg border border-border overflow-hidden">
                    <Table>
                      <TableHeader>
                        <TableRow className="hover:bg-transparent border-b border-border">
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[200px] max-w-[400px]">
                            Key
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider min-w-[200px] max-w-[400px]">
                            Value
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]"></TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {paginatedVariables.map((variable) => (
                          <TableRow key={variable.$id}>
                            <TableCell className="px-4 py-3">
                              <CopyableText value={variable.key} />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              {variable.secret ? (
                                <Badge
                                  variant="secondary"
                                  className="text-[12px]"
                                >
                                  Secret
                                </Badge>
                              ) : (
                                <CopyableText
                                  value={variable.value || ''}
                                  hideValue={true}
                                />
                              )}
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                  <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 w-7 p-0"
                                    onClick={(e) => e.stopPropagation()}
                                  >
                                    <MoreHorizontal className="h-4 w-4" />
                                  </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                  <DropdownMenuItem
                                    onClick={() => {
                                      setSelectedVar(variable)
                                      setShowUpdateModal(true)
                                    }}
                                  >
                                    Update
                                  </DropdownMenuItem>
                                  {!variable.secret && (
                                    <DropdownMenuItem
                                      onClick={() => {
                                        setSelectedVar(variable)
                                        setShowSecretModal(true)
                                      }}
                                    >
                                      Secret
                                    </DropdownMenuItem>
                                  )}
                                  <DropdownMenuItem
                                    className="text-destructive"
                                    onClick={() => {
                                      setSelectedVar(variable)
                                      setShowDeleteModal(true)
                                    }}
                                  >
                                    Delete
                                  </DropdownMenuItem>
                                </DropdownMenuContent>
                              </DropdownMenu>
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>

                  {total > limit && (
                    <div className="mt-4">
                      <Pagination
                        currentPage={currentPage}
                        totalItems={total}
                        pageSize={limit}
                        pageSizeOptions={[10]}
                        onPageChange={(newPage) => onPageChange(newPage - 1)}
                        onPageSizeChange={() => {}}
                        itemLabel="variables"
                      />
                    </div>
                  )}
                </>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Create Variable Modal */}
      <Dialog open={showCreateModal} onOpenChange={setShowCreateModal}>
        <DialogContent className="sm:max-w-2xl p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Create variable</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Add one or more environment variables. You can add multiple
              variables at once.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0 max-h-[60vh] overflow-y-auto">
            <div className="space-y-4">
              {createPairs.map((pair, index) => (
                <div
                  key={index}
                  className="space-y-3 p-4 border border-border rounded-lg"
                >
                  <div className="flex items-center justify-between">
                    <Label className="text-[13px] font-medium">
                      Variable {index + 1}
                    </Label>
                    {createPairs.length > 1 && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={() => {
                          setCreatePairs(
                            createPairs.filter((_, i) => i !== index),
                          )
                        }}
                        disabled={
                          createPairs.length === 1 && !pair.key && !pair.value
                        }
                      >
                        <XCircle className="h-4 w-4" />
                      </Button>
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor={`key-${index}`} className="text-[12px]">
                      Key <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id={`key-${index}`}
                      value={pair.key}
                      onChange={(e) => {
                        const newPairs = [...createPairs]
                        newPairs[index].key = e.target.value
                        setCreatePairs(newPairs)
                      }}
                      placeholder="ENTER_KEY"
                      autoFocus={index === 0}
                      autoComplete="off"
                      className="font-mono text-[13px]"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor={`value-${index}`} className="text-[12px]">
                      Value <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id={`value-${index}`}
                      type="password"
                      value={pair.value}
                      onChange={(e) => {
                        const newPairs = [...createPairs]
                        newPairs[index].value = e.target.value
                        setCreatePairs(newPairs)
                      }}
                      placeholder="Enter value"
                      className="font-mono text-[13px]"
                    />
                  </div>
                </div>
              ))}

              <Button
                type="button"
                variant="outline"
                size="sm"
                className="w-full h-9 text-[13px]"
                onClick={() => {
                  setCreatePairs([...createPairs, { key: '', value: '' }])
                }}
                disabled={!createPairs[createPairs.length - 1]?.key}
              >
                <Plus className="mr-2 h-4 w-4" />
                Add variable
              </Button>

              <div className="flex items-center space-x-2 pt-2">
                <Checkbox
                  id="create-secret"
                  checked={createSecret}
                  onCheckedChange={(checked) =>
                    setCreateSecret(checked === true)
                  }
                />
                <Label
                  htmlFor="create-secret"
                  className="text-[13px] cursor-pointer"
                >
                  Secret
                </Label>
              </div>
              <p className="text-[12px] text-muted-foreground -mt-2">
                If selected, you and your team won't be able to read the values
                after creation.
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowCreateModal(false)}
              disabled={createMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleCreate}
              disabled={
                createMutation.isPending ||
                createPairs.some((p) => !p.key.trim()) ||
                createPairs.some((p) => p.value.length > 8192)
              }
            >
              Create
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Update Variable Modal */}
      <Dialog open={showUpdateModal} onOpenChange={setShowUpdateModal}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Update variable</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Update the value of this variable. The key cannot be changed.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-4">
              <div className="space-y-2">
                <Label className="text-[12px]">Key</Label>
                <Input
                  value={selectedVar?.key || ''}
                  readOnly
                  className="font-mono text-[13px] bg-muted"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="update-value" className="text-[12px]">
                  Value <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="update-value"
                  type="password"
                  value={updateValue}
                  onChange={(e) => setUpdateValue(e.target.value)}
                  placeholder="Enter value"
                  className="font-mono text-[13px]"
                />
              </div>
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="update-secret"
                  checked={updateSecret}
                  onCheckedChange={(checked) =>
                    setUpdateSecret(checked === true)
                  }
                  disabled={selectedVar?.secret}
                />
                <Label
                  htmlFor="update-secret"
                  className="text-[13px] cursor-pointer"
                >
                  Secret
                </Label>
              </div>
              {selectedVar?.secret && (
                <p className="text-[12px] text-muted-foreground">
                  This variable is already secret and cannot be changed.
                </p>
              )}
              {!selectedVar?.secret && (
                <p className="text-[12px] text-muted-foreground">
                  If selected, you and your team won't be able to read the
                  values after creation.
                </p>
              )}
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowUpdateModal(false)}
              disabled={updateMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleUpdate}
              disabled={updateMutation.isPending || updateValue.length > 8192}
            >
              Update
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Delete Variable Modal */}
      <Dialog open={showDeleteModal} onOpenChange={setShowDeleteModal}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete variable</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this variable? This action cannot
              be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            {deleteError && (
              <Alert variant="destructive" className="mb-4">
                <AlertTriangle className="h-4 w-4" />
                <AlertDescription className="text-[13px]">
                  {deleteError}
                </AlertDescription>
              </Alert>
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowDeleteModal(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Mark as Secret Modal */}
      <Dialog open={showSecretModal} onOpenChange={setShowSecretModal}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Secret variable</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Secret variables are hidden from both the UI and API. Once a
              variable is marked as secret, this action cannot be reversed.
              <br />
              <br />
              Are you sure you want to make this variable secret?
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowSecretModal(false)}
              disabled={updateMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleMarkSecret}
              disabled={updateMutation.isPending}
            >
              Mark as secret
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Import .env Modal */}
      <Dialog open={showImportModal} onOpenChange={setShowImportModal}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Import .env</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Upload a .env file to import variables. Existing variables with
              the same key will be updated.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-4">
              {variables.length > 0 && (
                <Alert>
                  <AlertTriangle className="h-4 w-4" />
                  <AlertDescription className="text-[13px]">
                    This action can create and update variables but can not
                    delete them.
                  </AlertDescription>
                </Alert>
              )}

              <div className="space-y-2">
                <Label htmlFor="import-file" className="text-[12px]">
                  File <span className="text-destructive">*</span>
                </Label>
                <div className="border-2 border-dashed border-border rounded-lg p-6 text-center">
                  <input
                    id="import-file"
                    type="file"
                    accept=".env"
                    onChange={(e) => {
                      const file = e.target.files?.[0]
                      setImportFile(file || null)
                      setImportError('')
                    }}
                    className="hidden"
                  />
                  <label
                    htmlFor="import-file"
                    className="cursor-pointer flex flex-col items-center gap-2"
                  >
                    <Upload className="h-8 w-8 text-muted-foreground" />
                    <span className="text-[13px] text-foreground">
                      {importFile
                        ? importFile.name
                        : 'Click to upload or drag and drop'}
                    </span>
                    <span className="text-[12px] text-muted-foreground">
                      Only .env files allowed
                    </span>
                  </label>
                </div>
                {importError && (
                  <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertDescription className="text-[13px]">
                      {importError}
                    </AlertDescription>
                  </Alert>
                )}
              </div>

              <div className="flex items-center space-x-2">
                <Checkbox
                  id="import-secret"
                  checked={importSecret}
                  onCheckedChange={(checked) =>
                    setImportSecret(checked === true)
                  }
                />
                <Label
                  htmlFor="import-secret"
                  className="text-[13px] cursor-pointer"
                >
                  Secret
                </Label>
              </div>
              <p className="text-[12px] text-muted-foreground -mt-2">
                If selected, you and your team won't be able to read the values
                after creation.
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setShowImportModal(false)}
              disabled={createMutation.isPending || updateMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleImport}
              disabled={
                !importFile ||
                createMutation.isPending ||
                updateMutation.isPending
              }
            >
              Import
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Raw Editor Modal */}
      <VariableEditor
        open={showEditorModal}
        onOpenChange={setShowEditorModal}
        content={editorContent}
        onContentChange={(content) => {
          setEditorContent(content)
          setEditorError('')
        }}
        format={editorFormat}
        onFormatChange={handleFormatSwitch}
        error={editorError}
        onSave={handleEditorSave}
        onCopy={handleCopy}
        onDownload={handleDownload}
        isSaving={
          createMutation.isPending ||
          updateMutation.isPending ||
          deleteMutation.isPending
        }
      />
    </>
  )
}

// Change Organization Section Component
interface ChangeOrganizationSectionProps {
  project: any
  organizations: Array<{ value: string; label: string }>
  organizationsLoading: boolean
  selectedOrgId: string
  onOrgChange: (orgId: string) => void
  onTransfer: ReturnType<typeof useMutation> | any
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
  const selectedOrg = organizations.find((org) => org.value === selectedOrgId)
  const hasNoTargetOrgs =
    !organizationsLoading && organizations.length === 0
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
  project: any
  deleteConfirmation: string
  onDeleteConfirmationChange: (value: string) => void
  deleteDialogOpen: boolean
  onDeleteDialogOpenChange: (open: boolean) => void
  onDelete: ReturnType<typeof useMutation> | any
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

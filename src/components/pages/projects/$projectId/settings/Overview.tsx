import { useState, useEffect, useMemo, useRef } from 'react'
import { useNavigate, useSearch } from '@tanstack/react-router'
import {
  Loader2,
  Code,
  Upload,
  ArrowUpDown,
  Globe,
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
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { updateConsoleProject } from '@/lib/appwrite/console-projects'
import { sdk } from '@/lib/appwrite/sdk'
import {
  deleteProject,
  useProject,
  useOrganizations,
  PROJECT_NAME_MAX_LENGTH,
  formatProjectNameForDisplay,
  getProjectNameDisplayTitle,
} from '@/lib/react-query/hooks'
import { MCPSection } from '@/components/pages/projects/$projectId/shared/MCPSection'
import { RESOURCE_CARD_GRID_CLASSNAME } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
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
import {
  ProjectProtocolId,
  ProjectServiceId,
  type Models,
} from '@appwrite.io/console'
import {
  protocolsRecordFromProject,
  servicesRecordFromProject,
  patchProjectProtocolsInCache,
} from '@/lib/project-settings'
import { GitConfigurationCard } from './GitConfigurationCard'
import { PremiumGeoDBCard } from './_components/PremiumGeoDBCard'
import { buildVcsAuthUrl, type VcsProviderId } from '@/lib/vcs/providers'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { useScrollToCard } from '@/hooks/use-scroll-to-card'
import { useT } from '@/lib/i18n/translate'

// Copyable Input Component
interface CopyableInputProps {
  value: string
  label: string
  className?: string
}

function CopyableInput({ value, label, className }: CopyableInputProps) {
  const t = useT()
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
        className="pe-10 font-mono text-[13px]"
      />
      <button
        type="button"
        onClick={handleCopy}
        className="absolute end-2 top-1/2 -translate-y-1/2 flex items-center justify-center h-7 w-7 rounded-md hover:bg-accent transition-colors"
        aria-label={`${t('Copy')} ${label}`}
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

type ProjectProtocol = {
  id: ProjectProtocolId
  label: string
  description: string
  icon: typeof Globe
}

type ProjectService = {
  id: ProjectServiceId
  label: string
  icon: typeof User
}

const PROJECT_PROTOCOLS: ProjectProtocol[] = [
  {
    id: ProjectProtocolId.Rest,
    label: 'REST',
    description: 'Standard HTTP API requests from client SDKs.',
    icon: ArrowUpDown,
  },
  {
    id: ProjectProtocolId.Graphql,
    label: 'GraphQL',
    description: 'GraphQL API access for queries and mutations.',
    icon: Code,
  },
  {
    id: ProjectProtocolId.Websocket,
    label: 'WebSocket',
    description: 'Realtime subscriptions over WebSocket connections.',
    icon: MessageSquare,
  },
]

const PROJECT_SERVICES: ProjectService[] = [
  { id: ProjectServiceId.Account, label: 'Account', icon: User },
  { id: ProjectServiceId.Avatars, label: 'Avatars', icon: UserCircle },
  { id: ProjectServiceId.Databases, label: 'Databases', icon: Database },
  { id: ProjectServiceId.Tablesdb, label: 'TablesDB', icon: Database },
  { id: ProjectServiceId.Functions, label: 'Functions', icon: Zap },
  { id: ProjectServiceId.Locale, label: 'Locale', icon: Globe },
  { id: ProjectServiceId.Messaging, label: 'Messaging', icon: MessageSquare },
  { id: ProjectServiceId.Migrations, label: 'Migrations', icon: Upload },
  { id: ProjectServiceId.Project, label: 'Project', icon: Folder },
  { id: ProjectServiceId.Storage, label: 'Storage', icon: Folder },
  { id: ProjectServiceId.Sites, label: 'Sites', icon: Globe },
  { id: ProjectServiceId.Teams, label: 'Teams', icon: Building2 },
  { id: ProjectServiceId.Users, label: 'Users', icon: Users },
]

function removeAlertSearchParam(prev: unknown) {
  const next: Record<string, string | undefined> =
    prev && typeof prev === 'object'
      ? { ...(prev as Record<string, string | undefined>) }
      : {}
  delete next.alert
  return next
}

interface ProjectSettingsOverviewProps {
  projectId: string
}

export function ProjectSettingsOverview({
  projectId,
}: ProjectSettingsOverviewProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const supportsMultiRegion = features.multiRegion
  const supportsMultiTenancy = features.multiTenancy
  const navigate = useNavigate()
  useScrollToCard()
  const queryClient = useQueryClient()
  const search = useSearch({ from: '/_public/projects/$projectId/settings' })

  const {
    project,
    projectData: rawProjectData,
    isLoading: projectLoading,
  } = useProject(projectId)

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

  // State for protocols
  const [updatingProtocols, setUpdatingProtocols] = useState<
    Set<ProjectProtocolId>
  >(new Set())
  const [protocols, setProtocols] = useState<Record<ProjectProtocolId, boolean>>(
    {
      [ProjectProtocolId.Rest]: true,
      [ProjectProtocolId.Graphql]: true,
      [ProjectProtocolId.Websocket]: true,
    },
  )
  const [protocolDialogOpen, setProtocolDialogOpen] = useState(false)
  const [protocolBulkStatus, setProtocolBulkStatus] = useState<boolean | null>(
    null,
  )

  // State for git installations
  const [installationsPage, setInstallationsPage] = useState(0)
  const installationsLimit = 25

  // State for organization transfer
  const [selectedOrgId, setSelectedOrgId] = useState('')

  // State for delete confirmation
  const [deleteConfirmation, setDeleteConfirmation] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const refetchProjectLists = async () => {
    await queryClient.refetchQueries({
      predicate: (query) => query.queryKey[0] === 'projects',
      type: 'all',
    })
    await queryClient.refetchQueries({
      queryKey: ['organizations', 'console'],
      type: 'all',
    })
  }

  // Initialize project name when project loads
  useEffect(() => {
    if (project) {
      setProjectName(project.name)
    }
  }, [project])

  useEffect(() => {
    if (rawProjectData) {
      setServices(
        servicesRecordFromProject(
          rawProjectData as Models.Project,
          PROJECT_SERVICES.map((s) => s.id),
        ),
      )
      setProtocols(protocolsRecordFromProject(rawProjectData as Models.Project))
    }
  }, [rawProjectData])

  // Handle URL query parameters for installation alerts
  useEffect(() => {
    const searchParams = search as { alert?: string }
    const alert = searchParams?.alert
    if (alert === 'installation-created') {
      toast.success(t('Git installation has imported to your project'))
      navigate({
        search: removeAlertSearchParam as never,
        replace: true,
      })
    } else if (alert === 'installation-updated') {
      toast.success(t('Git installation has been successfully updated'))
      navigate({
        search: removeAlertSearchParam as never,
        replace: true,
      })
    }
  }, [search, navigate, t])

  // Get project endpoint (centralized in SDK)
  const projectEndpoint = useMemo(
    () => getApiEndpoint(project?.region),
    [project?.region],
  )

  const projectRegion = useMemo(
    () => rawProjectData?.region || project?.region,
    [rawProjectData?.region, project?.region],
  )

  const updateProjectService = async (
    serviceId: ProjectServiceId,
    enabled: boolean,
  ): Promise<Models.Project> => {
    return await sdk
      .forProject(projectId, projectRegion)
      .project.updateService({
        serviceId,
        enabled,
      })
  }

  const updateProjectProtocol = async (
    protocolId: ProjectProtocolId,
    enabled: boolean,
  ): Promise<Models.Project> => {
    return await sdk
      .forProject(projectId, projectRegion)
      .project.updateProtocol({
        protocolId,
        enabled,
      })
  }

  const patchCachedProtocol = (
    protocolId: ProjectProtocolId,
    enabled: boolean,
  ) => {
    queryClient.setQueryData<Models.Project | undefined>(
      ['project', projectId],
      (current) =>
        current
          ? patchProjectProtocolsInCache(current, protocolId, enabled)
          : current,
    )
  }

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
      if (
        trimmedName.length < 1 ||
        trimmedName.length > PROJECT_NAME_MAX_LENGTH
      ) {
        throw new Error(
          `${t('Name must be between 1 and')} ${PROJECT_NAME_MAX_LENGTH} ${t('characters')}`,
        )
      }
      if (!project?.teamId) {
        throw new Error(t('Organization not found for this project'))
      }
      await updateConsoleProject({
        projectId,
        name: trimmedName,
        organizationId: project.teamId,
      })
    },
    onSuccess: async () => {
      toast.success(t('Project name has been updated'))
      await queryClient.refetchQueries({
        queryKey: [Dependencies.PROJECT, projectId],
        exact: true,
        type: 'all',
      })
      await refetchProjectLists()
      // Track analytics: Submit.ProjectUpdateName
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, t('Failed to update project name')))
    },
  })

  const setServicesFromProjectResponse = (response: Models.Project) => {
    setServices(
      servicesRecordFromProject(
        response,
        PROJECT_SERVICES.map((service) => service.id),
      ),
    )
    queryClient.setQueryData<Models.Project>(['project', projectId], response)
  }

  // Mutation to update service status
  const updateServiceMutation = useMutation({
    mutationFn: async ({
      service,
      status,
    }: {
      service: ProjectServiceId
      status: boolean
    }) => {
      const response = await updateProjectService(service, status)
      return { response, service, status }
    },
    onSuccess: (data) => {
      const { response, service, status } = data
      const serviceLabel =
        PROJECT_SERVICES.find((item) => item.id === service)?.label ?? service
      toast.success(
        `${t(serviceLabel)} ${status ? t('service has been enabled') : t('service has been disabled')}`,
      )

      setServicesFromProjectResponse(response)

      setUpdatingServices((prev) => {
        const next = new Set(prev)
        next.delete(service)
        return next
      })
      // Track analytics: Submit.ProjectService
    },
    onError: (error: Error, variables) => {
      toast.error(getErrorMessage(error, t('Failed to update service')))
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
      let response: Models.Project | null = null
      for (const service of PROJECT_SERVICES) {
        if (services[service.id] === status) continue
        response = await updateProjectService(service.id, status)
      }
      return { response, status }
    },
    onSuccess: (data) => {
      const { response, status } = data
      toast.success(
        `${t('All services for')} ${project?.name || t('project')} ${status ? t('have been enabled.') : t('have been disabled.')}`,
      )

      if (response) {
        setServicesFromProjectResponse(response)
      }
      // Track analytics: Submit.ProjectService
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, t('Failed to update services')))
    },
  })

  // Mutation to update protocol status
  const updateProtocolMutation = useMutation({
    mutationFn: async ({
      protocol,
      status,
    }: {
      protocol: ProjectProtocolId
      status: boolean
    }) => {
      return await updateProjectProtocol(protocol, status)
    },
    onSuccess: async (_projectData, variables) => {
      const protocolConfig = PROJECT_PROTOCOLS.find(
        (protocol) => protocol.id === variables.protocol,
      )

      setProtocols((prev) => ({
        ...prev,
        [variables.protocol]: variables.status,
      }))
      if (protocolConfig) {
        patchCachedProtocol(protocolConfig.id, variables.status)
      }

      toast.success(
        `${protocolConfig?.label || t('Protocol')} ${
          variables.status
            ? t('protocol has been enabled')
            : t('protocol has been disabled')
        }`,
      )
    },
    onError: (error: Error, variables) => {
      toast.error(getErrorMessage(error, t('Failed to update protocol')))
      setProtocols((prev) => ({
        ...prev,
        [variables.protocol]: !variables.status,
      }))
    },
    onSettled: (_data, _error, variables) => {
      setUpdatingProtocols((prev) => {
        const next = new Set(prev)
        next.delete(variables.protocol)
        return next
      })
    },
  })

  // Mutation to update all protocols
  const updateAllProtocolsMutation = useMutation({
    mutationFn: async (status: boolean) => {
      for (const protocol of PROJECT_PROTOCOLS) {
        if (protocols[protocol.id] === status) continue
        await updateProjectProtocol(protocol.id, status)
      }

      return status
    },
    onSuccess: async (status) => {
      setProtocols({
        [ProjectProtocolId.Rest]: status,
        [ProjectProtocolId.Graphql]: status,
        [ProjectProtocolId.Websocket]: status,
      })
      for (const protocol of PROJECT_PROTOCOLS) {
        patchCachedProtocol(protocol.id, status)
      }
      toast.success(
        `${t('All protocols for')} ${project?.name || t('project')} ${
          status ? t('have been enabled.') : t('have been disabled.')
        }`,
      )
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, t('Failed to update protocols')))
    },
    onSettled: () => {
      setProtocolDialogOpen(false)
      setProtocolBulkStatus(null)
    },
  })

  // Mutation to transfer project
  const transferProjectMutation = useMutation({
    mutationFn: async (teamId: string) => {
      if (!getActiveProfileFeatures().multiTenancy) {
        throw new Error(
          'This console profile does not support transferring between organizations',
        )
      }
      await sdk.forConsole.projects.updateTeam({ projectId, teamId })
    },
    onSuccess: async (_, teamId) => {
      const oldTeamId = project?.teamId

      // Get organization name from query cache
      const orgsData = queryClient.getQueryData([
        'organizations',
        'console',
      ]) as { teams?: Models.Team[] } | undefined
      const org = orgsData?.teams?.find((t) => t.$id === teamId)
      const orgName = org?.name || t('Organization')

      toast.success(
        `${project?.name || t('Project')} ${t('has been transferred to')} ${orgName}`,
      )

      // Refetch project query to refresh project data (teamId changed)
      await queryClient.refetchQueries({
        queryKey: ['project', projectId],
        exact: true,
        type: 'all',
      })
      await queryClient.refetchQueries({
        queryKey: [Dependencies.PROJECT, projectId],
        exact: true,
        type: 'all',
      })

      // Invalidate projects list for old organization (if it exists)
      if (oldTeamId) {
        await queryClient.refetchQueries({
          queryKey: ['projects', 'team', oldTeamId],
          type: 'all',
        })
      }

      // Refetch projects list for new organization
      await queryClient.refetchQueries({
        queryKey: ['projects', 'team', teamId],
        type: 'all',
      })

      // Refetch all project lists (active/pinned/team) so cards update immediately.
      await refetchProjectLists()

      // Track analytics: Submit.ProjectUpdateTeam
      navigate({
        to: '/organizations/$orgId',
        params: { orgId: teamId },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, t('Failed to transfer project')))
    },
  })

  // Mutation to delete project
  const deleteProjectMutation = useMutation({
    mutationFn: async () => {
      await deleteProject(projectId, project?.region)
    },
    onSuccess: async () => {
      toast.success(`${project?.name || t('Project')} ${t('has been deleted')}`)
      // Track analytics: Submit.ProjectDelete
      const orgId = project?.teamId
      if (orgId) {
        await queryClient.refetchQueries({
          queryKey: [Dependencies.ORGANIZATION, orgId],
          type: 'all',
        })
        await refetchProjectLists()
        navigate({
          to: '/organizations/$orgId',
          params: { orgId },
        })
      } else {
        await refetchProjectLists()
      }
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error, t('Failed to delete project')))
    },
  })

  // Handle service toggle
  const handleServiceToggle = (service: ProjectServiceId, checked: boolean) => {
    const nextChecked =
      services[service] === undefined ? checked : !services[service]
    // Optimistically update UI
    setServices((prev) => ({ ...prev, [service]: nextChecked }))
    setUpdatingServices((prev) => new Set(prev).add(service))
    updateServiceMutation.mutate(
      { service, status: nextChecked },
      {
        onError: () => {
          // Revert on error
          setServices((prev) => ({ ...prev, [service]: !nextChecked }))
        },
      },
    )
  }

  // Handle bulk enable/disable
  const handleBulkServiceUpdate = (status: boolean) => {
    setServices(
      Object.fromEntries(
        PROJECT_SERVICES.map((service) => [service.id, status]),
      ),
    )
    updateAllServicesMutation.mutate(status)
  }

  // Handle protocol toggle
  const handleProtocolToggle = (
    protocol: ProjectProtocolId,
    checked: boolean,
  ) => {
    const nextChecked =
      protocols[protocol] === undefined ? checked : !protocols[protocol]
    setProtocols((prev) => ({ ...prev, [protocol]: nextChecked }))
    setUpdatingProtocols((prev) => new Set(prev).add(protocol))
    updateProtocolMutation.mutate({ protocol, status: nextChecked })
  }

  const openProtocolBulkDialog = (status: boolean) => {
    setProtocolBulkStatus(status)
    setProtocolDialogOpen(true)
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

  const allProtocolsEnabled = useMemo(() => {
    const protocolValues = Object.values(protocols)
    return protocolValues.length > 0 && protocolValues.every(Boolean)
  }, [protocols])

  const allProtocolsDisabled = useMemo(() => {
    const protocolValues = Object.values(protocols)
    return protocolValues.length > 0 && protocolValues.every((v) => !v)
  }, [protocols])

  const anyProtocolUpdating =
    updatingProtocols.size > 0 || updateAllProtocolsMutation.isPending

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

  // Build a VCS provider authorization URL (github, gitlab, ...)
  const getVcsAuthUrl = (
    provider: VcsProviderId = 'github',
    mode: 'create' | 'update' = 'create',
  ) => {
    const alertType =
      mode === 'create' ? 'installation-created' : 'installation-updated'
    const successUrl = `${window.location.origin}/projects/${projectId}/settings?alert=${alertType}`
    const failureUrl = `${window.location.origin}/projects/${projectId}/settings`
    return buildVcsAuthUrl({
      endpoint: projectEndpoint,
      provider,
      projectId,
      successUrl,
      failureUrl,
    })
  }

  // Backwards-compatible GitHub-specific helper.
  const getGitHubAuthUrl = (mode: 'create' | 'update' = 'create') =>
    getVcsAuthUrl('github', mode)

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
            {t('Project not found')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-4 sm:px-6">
      {/* Update Name Section - only if canWriteProjects */}
      {canWriteProjects && (
        <div
          data-card-id="project-name"
          className="rounded-xl border border-border bg-card/50 overflow-hidden"
        >
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">{t('Name')}</h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <Label
              htmlFor="name"
              className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5 block"
            >
              {t('Name')}
            </Label>
            <Input
              id="name"
              value={projectName}
              onChange={(e) => setProjectName(e.target.value)}
              placeholder={t('Enter name')}
              maxLength={PROJECT_NAME_MAX_LENGTH}
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
                projectName.trim().length > PROJECT_NAME_MAX_LENGTH ||
                updateNameMutation.isPending
              }
              onClick={() => {
                const trimmedName = projectName.trim()
                if (
                  trimmedName &&
                  trimmedName !== project.name &&
                  trimmedName.length >= 1 &&
                  trimmedName.length <= PROJECT_NAME_MAX_LENGTH
                ) {
                  updateNameMutation.mutate(trimmedName)
                }
              }}
            >
              {t('Update')}
            </Button>
          </div>
        </div>
      )}

      {/* API Credentials Section - Always visible */}
      <div
        data-card-id="api-credentials"
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('API credentials')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 @container">
          <div className="flex gap-6 @[600px]:flex-row flex-col">
            <div className="@[600px]:w-64 shrink-0">
              <p className="text-[13px] text-muted-foreground">
                {t("Access Appwrite services using this project's API Endpoint and Project ID.")} {/* pragma: allowlist secret */}
              </p>
            </div>
            <div className="flex-1 min-w-0">
              <div className="space-y-4">
                <div>
                  <Label className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5 block">
                    {t('Project ID')}
                  </Label>
                  <CopyableInput value={project.$id} label={t('Project ID')} />
                </div>
                <div>
                  <Label className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5 block">
                    {t('API Endpoint')}
                  </Label>
                  <CopyableInput value={projectEndpoint} label={t('API Endpoint')} />
                </div>
              </div>
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
            {t('View API keys')}
          </Button>
        </div>
      </div>

      {/* Conditional sections - only if canWriteProjects */}
      {canWriteProjects && (
        <>
          {/* Update Protocols Section */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Protocols')}
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 @container">
              <div className="flex gap-6 @[600px]:flex-row flex-col">
                <div className="@[600px]:w-64 shrink-0">
                  <p className="text-[13px] text-muted-foreground">
                    {t('Protocol settings control access through REST, GraphQL, and WebSocket APIs independently from service-level access.')}
                  </p>
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-4">
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-8 text-[12px]"
                      disabled={anyProtocolUpdating || allProtocolsEnabled}
                      onClick={() => openProtocolBulkDialog(true)}
                    >
                      {t('Enable all')}
                    </Button>
                    <Separator orientation="vertical" className="h-4" />
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-8 text-[12px]"
                      disabled={anyProtocolUpdating || allProtocolsDisabled}
                      onClick={() => openProtocolBulkDialog(false)}
                    >
                      {t('Disable all')}
                    </Button>
                  </div>

                  <div className="rounded-lg border border-border bg-background">
                    {PROJECT_PROTOCOLS.map((protocol, index) => {
                      const Icon = protocol.icon
                      const isUpdating = updatingProtocols.has(protocol.id)

                      return (
                        <div key={protocol.id}>
                          <div
                            className={cn(
                              'flex items-center justify-between gap-4 px-4 py-3',
                              isUpdating && 'opacity-75',
                            )}
                          >
                            <div className="flex min-w-0 items-start gap-3">
                              <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                <Icon className="h-4 w-4" />
                              </div>
                              <div className="min-w-0">
                                <Label
                                  htmlFor={`protocol-${protocol.id}`}
                                  className="text-[13px] font-medium text-foreground cursor-pointer"
                                >
                                  {protocol.label}
                                </Label>
                                <p className="mt-1 text-[12px] text-muted-foreground">
                                  {t(protocol.description)}
                                </p>
                              </div>
                            </div>
                            <div className="flex items-center gap-2">
                              {isUpdating && (
                                <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                              )}
                              <Switch
                                id={`protocol-${protocol.id}`}
                                checked={protocols[protocol.id]}
                                onCheckedChange={(checked) =>
                                  handleProtocolToggle(protocol.id, checked)
                                }
                                disabled={isUpdating || anyProtocolUpdating}
                              />
                            </div>
                          </div>
                          {index < PROJECT_PROTOCOLS.length - 1 && (
                            <div className="border-t border-border" />
                          )}
                        </div>
                      )
                    })}
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Update Services Section */}
          <div
            data-card-id="services"
            className="rounded-xl border border-border bg-card/50 overflow-hidden"
          >
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Services')}
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 @container">
              <div className="flex gap-6 @[600px]:flex-row flex-col">
                <div className="@[600px]:w-64 shrink-0">
                  <p className="text-[13px] text-muted-foreground">
                    {t('Choose services you wish to enable or disable for the client API. When disabled, the services are not accessible to client SDKs but remain accessible to server SDKs.')}
                  </p>
                </div>
                <div className="flex-1 min-w-0">
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
                      {t('Enable all')}
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
                      {t('Disable all')}
                    </Button>
                  </div>

                  {/* Service Cards */}
                  <div className={RESOURCE_CARD_GRID_CLASSNAME}>
                    {PROJECT_SERVICES.map((service) => {
                      const Icon = service.icon
                      const enabled = services[service.id] ?? true
                      const isUpdating = updatingServices.has(service.id)
                      return (
                        <div
                          key={service.id}
                          className={cn(
                            'min-w-0 rounded-lg border border-border bg-card/50 p-4 transition-colors',
                            isUpdating && 'opacity-75',
                            !isUpdating && 'hover:bg-card',
                          )}
                        >
                          <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                              <Icon className="h-4 w-4 text-muted-foreground" />
                              <Label
                                htmlFor={service.id}
                                className="text-[13px] font-medium text-foreground cursor-pointer"
                              >
                                {t(service.label)}
                              </Label>
                            </div>
                            <div className="flex items-center gap-2">
                              {isUpdating && (
                                <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                              )}
                              <Switch
                                id={service.id}
                                checked={enabled}
                                onCheckedChange={(checked) =>
                                  handleServiceToggle(service.id, checked)
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
            </div>
          </div>

          {/* Git Configuration Section */}
          <GitConfigurationCard
            projectId={projectId}
            page={installationsPage}
            limit={installationsLimit}
            onPageChange={setInstallationsPage}
            getGitHubAuthUrl={getGitHubAuthUrl}
            getVcsAuthUrl={getVcsAuthUrl}
            isSelfHosted={false} // TODO: Get from organization plan
            isVcsEnabled={true} // TODO: Get from project settings
          />

          <PremiumGeoDBCard projectId={projectId} />

          {/* MCP Server Section */}
          <MCPSection projectId={projectId} projectName={project.name} />

          {/* Change Organization Section (cloud multi-tenancy only) */}
          {supportsMultiTenancy && (
            <ChangeOrganizationSection
              project={project}
              organizations={organizations}
              organizationsLoading={organizationsLoading}
              selectedOrgId={selectedOrgId}
              onOrgChange={setSelectedOrgId}
              onTransfer={transferProjectMutation}
            />
          )}

          {/* Delete Project Section */}
          <DeleteProjectSection
            project={project}
            supportsMultiRegion={supportsMultiRegion}
            deleteConfirmation={deleteConfirmation}
            onDeleteConfirmationChange={setDeleteConfirmation}
            deleteDialogOpen={deleteDialogOpen}
            onDeleteDialogOpenChange={setDeleteDialogOpen}
            onDelete={deleteProjectMutation}
          />

          <Dialog
            open={protocolDialogOpen}
            onOpenChange={setProtocolDialogOpen}
          >
            <DialogContent className="sm:max-w-md p-0">
              <DialogHeader className="px-6 pt-6 pb-4 text-start">
                <DialogTitle>
                  {protocolBulkStatus
                    ? t('Enable all protocols')
                    : t('Disable all protocols')}
                </DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  {protocolBulkStatus
                    ? t('All project protocols will be enabled.')
                    : t('Are you sure you want to disable all protocols? This will disable client access over those protocols until they are re-enabled.')}
                </DialogDescription>
              </DialogHeader>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => setProtocolDialogOpen(false)}
                  disabled={updateAllProtocolsMutation.isPending}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => {
                    if (protocolBulkStatus !== null) {
                      updateAllProtocolsMutation.mutate(protocolBulkStatus)
                    }
                  }}
                  disabled={
                    protocolBulkStatus === null ||
                    updateAllProtocolsMutation.isPending
                  }
                >
                  {protocolBulkStatus ? t('Enable all') : t('Disable all')}
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </>
      )}
    </div>
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
  const t = useT()
  const [transferDialogOpen, setTransferDialogOpen] = useState(false)
  const hasNoTargetOrgs = !organizationsLoading && organizations.length === 0
  const isMoveDisabled =
    hasNoTargetOrgs ||
    !selectedOrgId ||
    selectedOrgId === project.teamId ||
    onTransfer.isPending

  return (
    <>
      <div
        data-card-id="transfer-project"
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Transfer project')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-4">
            {t('To transfer this project, you must be a member of both the current and target organization. Select a destination below.')}
          </p>
          {hasNoTargetOrgs && (
            <Alert className="mb-4">
              <AlertTriangle className="h-4 w-4" />
              <AlertDescription className="text-[13px]">
                {t('You do not have any organizations you can transfer this project to. Create or join another organization to transfer.')}
              </AlertDescription>
            </Alert>
          )}
          <Label
            htmlFor="organization"
            className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5 block"
          >
            {t('Move to')}
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
                    ? t('Loading organizations...')
                    : t('Select destination')
                }
              />
            </SelectTrigger>
            <SelectContent>
              {organizations.length === 0 ? (
                <div className="px-2 py-1.5 text-[13px] text-muted-foreground">
                  {organizationsLoading
                    ? t('Loading...')
                    : t('No other organizations available')}
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
          {isMoveDisabled ? (
            <TooltipProvider>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-block">
                    <Button
                      size="sm"
                      className="h-9 text-[13px]"
                      disabled
                      onClick={() => setTransferDialogOpen(true)}
                    >
                      {t('Transfer project')}
                    </Button>
                  </span>
                </TooltipTrigger>
                <TooltipContent>
                  {hasNoTargetOrgs
                    ? t('You do not have any organizations you can transfer this project to.')
                    : !selectedOrgId || selectedOrgId === project.teamId
                      ? t('Select a different organization to transfer to.')
                      : t('Transfer this project to the selected organization')}
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          ) : (
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setTransferDialogOpen(true)}
            >
              {t('Transfer project')}
            </Button>
          )}
        </div>
      </div>

      {/* Transfer Confirmation Dialog */}
      <Dialog open={transferDialogOpen} onOpenChange={setTransferDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>
              {t('Transfer project')}{' '}
              <span
                title={getProjectNameDisplayTitle(project.name) ?? project.name}
              >
                {formatProjectNameForDisplay(project.name)}
              </span>
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Consider the following before transferring your project:')}
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
                  {t('Permissions')}
                </p>
                <p className="text-[12px] text-muted-foreground mt-0.5">
                  {t('Depending on your role in the target organization, your level of access may change after transfer.')}
                </p>
              </div>
            </div>
            <div className="flex gap-3">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
                <Users className="h-4 w-4 text-muted-foreground" />
              </div>
              <div>
                <p className="text-[13px] font-medium text-foreground">
                  {t('Access')}
                </p>
                <p className="text-[12px] text-muted-foreground mt-0.5">
                  {t('Members who are not part of the destination organization will lose access and must be invited to the new organization to regain access.')}
                </p>
              </div>
            </div>
            <div className="flex gap-3">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
                <CreditCard className="h-4 w-4 text-muted-foreground" />
              </div>
              <div>
                <p className="text-[13px] font-medium text-foreground">
                  {t('Features and usage')}
                </p>
                <p className="text-[12px] text-muted-foreground mt-0.5">
                  {t('The target organization’s pricing plan may limit features or usage (e.g. executions, storage, or team size) for this project.')}
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
              {t('Cancel')}
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
              {t('Transfer project')}
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
  supportsMultiRegion: boolean
  deleteConfirmation: string
  onDeleteConfirmationChange: (value: string) => void
  deleteDialogOpen: boolean
  onDeleteDialogOpenChange: (open: boolean) => void
  onDelete: { isPending: boolean; mutate: (v?: void) => void }
}

function DeleteProjectSection({
  project,
  supportsMultiRegion,
  deleteConfirmation,
  onDeleteConfirmationChange,
  deleteDialogOpen,
  onDeleteDialogOpenChange,
  onDelete,
}: DeleteProjectSectionProps) {
  const t = useT()
  return (
    <>
      <div
        data-card-id="delete-project"
        className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Delete project')}
          </h3>
        </div>
        <div className="border-t border-destructive/20" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            {t('Permanently delete this project and all associated data. This action cannot be undone.')}
          </p>

          {/* Project Info Summary */}
          {project && (
            <div className="flex items-center gap-3 mt-4">
              <InitialsAvatar name={project.name} size="md" />
              <div className="flex-1 min-w-0">
                <p
                  className="text-[14px] font-medium text-foreground truncate"
                  title={getProjectNameDisplayTitle(project.name) ?? project.name}
                >
                  {formatProjectNameForDisplay(project.name)}
                </p>
                {supportsMultiRegion && project.region && (
                  <p className="text-[12px] text-muted-foreground">
                    {t('Region:')} {project.region}
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
                {t('Delete project')}
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md p-0">
              <DialogHeader className="px-6 pt-6 text-start">
                <DialogTitle>{t('Delete Project')}</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  {t('Are you sure you want to delete')}{' '}
                  {project && (
                    <span
                      className="font-medium text-foreground"
                      title={getProjectNameDisplayTitle(project.name) ?? project.name}
                    >
                      {formatProjectNameForDisplay(project.name)}
                    </span>
                  )}{' '}
                  {t('and all its databases, functions, and files? This action cannot be undone.')}
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0">
                <div className="rounded-lg border border-border bg-muted/50 p-3 mb-4 mt-2">
                  {project && (
                    <div className="flex items-center gap-3">
                      <InitialsAvatar name={project.name} size="sm" />
                      <div>
                        <p
                          className="text-[13px] font-medium text-foreground truncate"
                          title={
                            getProjectNameDisplayTitle(project.name) ??
                            project.name
                          }
                        >
                          {formatProjectNameForDisplay(project.name)}
                        </p>
                        {supportsMultiRegion && project.region && (
                          <p className="text-[11px] text-muted-foreground">
                            {t('Region:')} {project.region}
                          </p>
                        )}
                      </div>
                    </div>
                  )}
                </div>
                <label className="text-[13px] text-muted-foreground">
                  {t('Type')}{' '}
                  {project && (
                    <span className="font-mono font-medium text-foreground bg-muted px-1.5 py-0.5 rounded">
                      {project.name}
                    </span>
                  )}{' '}
                  {t('to confirm')}
                </label>
                <Input
                  value={deleteConfirmation}
                  onChange={(e) => onDeleteConfirmationChange(e.target.value)}
                  placeholder={t('Enter project name')}
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
                  {t('Cancel')}
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
                  {t('Delete')}
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>
    </>
  )
}

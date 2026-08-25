/**
 * Repository Configuration View Component
 *
 * Main configuration screen for deploying a site from a Git repository.
 * Handles site details, branch selection, build settings, and domain configuration.
 */

import { useState, useEffect, useMemo, useCallback } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { IdInput } from '@/components/ui/id-input'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { RootDirectoryPicker } from '@/components/global/shared/RootDirectoryPicker'
import { VcsInstallationErrorAlert } from '@/components/global/shared/VcsInstallationError'
import {
  ExternalLink,
  Loader2,
  Lock,
  Globe,
  GitBranch,
  Key,
  FolderOpen,
  Layers,
} from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { VCSDetectionType, ID } from '@appwrite.io/console'
import {
  useRepository,
  useCreateSite,
  useCreateSiteDomain,
  useCreateVcsDeployment,
} from '@/lib/react-query/hooks'
import { useWizard } from './WizardContext'
import { DomainInput } from './DomainInput'
import { BuildSettings } from './BuildSettings'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'
import { getVcsProvider } from '@/lib/vcs/providers'
import { getVcsInstallationErrorKind } from '@/lib/utils/error-formatting'
import { useVcsInstallationReconnect } from '@/lib/vcs/use-installation-reconnect'
import { cn } from '@/lib/utils'
import { validateVariables } from '@/lib/variables'

interface RepositoryConfigViewProps {
  installationId: string
  providerRepositoryId: string
}

export function RepositoryConfigView({
  installationId: installationIdFromUrl,
  providerRepositoryId: providerRepositoryIdFromUrl,
}: RepositoryConfigViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const {
    formData,
    updateFormData,
    frameworks,
    getFrameworkDefaults,
    generateDomain,
  } = useWizard()

  // Use URL params as source of truth so detection works after refresh
  const installationId = installationIdFromUrl
  const providerRepositoryId = providerRepositoryIdFromUrl

  // Sync URL params to formData on mount so rest of flow has them
  useEffect(() => {
    if (installationId && providerRepositoryId) {
      updateFormData({
        installationId,
        providerRepositoryId,
      })
    }
  }, [installationId, providerRepositoryId, updateFormData])

  // Local form state
  const [siteName, setSiteName] = useState(formData.siteName || '')
  const [siteId, setSiteId] = useState<string | undefined>(formData.siteId)
  const [framework, setFramework] = useState(formData.framework || '')
  // Empty so BranchSelector resolves it from the repository.
  const [branch, setBranch] = useState(formData.providerBranch || '')
  const [rootDirectory, setRootDirectory] = useState(
    formData.providerRootDirectory || './',
  )
  const [silentMode, setSilentMode] = useState(
    formData.providerSilentMode || false,
  )
  const [installCommand, setInstallCommand] = useState(
    formData.installCommand || '',
  )
  const [buildCommand, setBuildCommand] = useState(formData.buildCommand || '')
  const [outputDirectory, setOutputDirectory] = useState(
    formData.outputDirectory || '',
  )
  const [startCommand, setStartCommand] = useState(formData.startCommand || '')
  const [fallbackFile, setFallbackFile] = useState(formData.fallbackFile || '')
  const [variables, setVariables] = useState(formData.variables || [])
  const [domain, setDomain] = useState(formData.domain || '')
  const [domainValid, setDomainValid] = useState(formData.domainValid || false)
  const [isDeploying, setIsDeploying] = useState(false)

  // Fetch repository details (use URL params so it works after refresh)
  const {
    data: repository,
    error: repositoryError,
    isFetching: repositoryFetching,
    refetch: refetchRepository,
  } = useRepository(
    projectId,
    installationId || null,
    providerRepositoryId || null,
  )

  // Which installation broke, and the authorize URL that repairs it. The
  // default return URL is right here: this route carries the installation and
  // repository in its path and re-derives the rest on mount, so the deep URL
  // survives the redirect better than the wizard entry route would.
  const {
    provider: installationProvider,
    organization: installationOrganization,
    reconnectUrl,
  } = useVcsInstallationReconnect(projectId, installationId || null)

  // Fall back to what the previous step already told us, so a failed lookup
  // leaves the repository header naming the repo instead of a bare slash.
  const repoName = repository?.name ?? formData.repositoryName ?? ''
  const repoOwner = repository?.organization ?? formData.repositoryOwner ?? ''
  const { Icon: RepositoryProviderIcon, label: repositoryProviderLabel } =
    getVcsProvider(repository?.provider ?? installationProvider)
  useEffect(() => {
    if (repoName && !siteName) setSiteName(repoName)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [repoName])

  // Framework detection via VCS service (createRepositoryDetection type=framework)
  const detectFrameworkMutation = useMutation({
    mutationFn: async (params: {
      installationId: string
      providerRepositoryId: string
      rootDirectory: string
    }) => {
      if (!projectId) throw new Error('Missing project')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.vcs.createRepositoryDetection({
        installationId: params.installationId,
        providerRepositoryId: params.providerRepositoryId,
        type: VCSDetectionType.Framework,
        providerRootDirectory: params.rootDirectory || './',
      })
    },
    onSuccess: (data) => {
      // Use detection API response: framework key + install/build/output from backend
      const detectedFramework = data.framework ?? ''
      if (detectedFramework) {
        setFramework(detectedFramework)
        const defaults = getFrameworkDefaults(detectedFramework)
        setInstallCommand(data.installCommand ?? defaults.installCommand)
        setBuildCommand(data.buildCommand ?? defaults.buildCommand)
        setOutputDirectory(data.outputDirectory ?? defaults.outputDirectory)
        setFallbackFile(defaults.fallbackFile)
        updateFormData({
          framework: detectedFramework,
          buildRuntime: defaults.buildRuntime,
          installCommand: data.installCommand ?? defaults.installCommand,
          buildCommand: data.buildCommand ?? defaults.buildCommand,
          outputDirectory: data.outputDirectory ?? defaults.outputDirectory,
          fallbackFile: defaults.fallbackFile,
        })
      }
    },
    onError: (error) => {
      // A dead token is not a "pick one yourself" problem: the inline alert
      // names the real cause and offers the reconnect. A transient failure
      // still leaves the manual framework picker as a working way forward.
      if (getVcsInstallationErrorKind(error) === 'reconnect') return
      toast.error(t('Could not detect framework. Select one manually.'))
    },
  })

  const runFrameworkDetection = useCallback(() => {
    if (!projectId || !installationId || !providerRepositoryId) {
      toast.error(
        t('Repository not connected. Go back and select a repository.'),
      )
      return
    }
    detectFrameworkMutation.mutate({
      installationId,
      providerRepositoryId,
      rootDirectory: rootDirectory || './',
    })
  }, [
    projectId,
    installationId,
    providerRepositoryId,
    rootDirectory,
    detectFrameworkMutation,
    t,
  ])

  // Both step-level calls hit endpoints that refresh the installation token, so
  // either one can be the thing that failed. The repository lookup is checked
  // first because it is what the whole step is built on.
  const installationErrorKind =
    getVcsInstallationErrorKind(repositoryError) ??
    getVcsInstallationErrorKind(detectFrameworkMutation.error)

  const retryRepositoryLoad = useCallback(() => {
    refetchRepository()
    runFrameworkDetection()
  }, [refetchRepository, runFrameworkDetection])

  // Run VCS framework detection when we have URL params (repo selected) or root directory changes
  useEffect(() => {
    if (!installationId || !providerRepositoryId || !projectId) return
    detectFrameworkMutation.mutate({
      installationId,
      providerRepositoryId,
      rootDirectory: rootDirectory || './',
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId, installationId, providerRepositoryId, rootDirectory])

  // Prefill build settings from SDK framework defaults when framework changes
  useEffect(() => {
    if (framework) {
      const defaults = getFrameworkDefaults(framework)
      if (!installCommand) setInstallCommand(defaults.installCommand)
      if (!buildCommand) setBuildCommand(defaults.buildCommand)
      if (!outputDirectory) setOutputDirectory(defaults.outputDirectory)
      if (!fallbackFile) setFallbackFile(defaults.fallbackFile)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [framework, getFrameworkDefaults])

  // Generate domain when site name changes
  useEffect(() => {
    if (siteName && !domain) {
      setDomain(generateDomain(siteName))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [siteName, generateDomain])

  // Create site mutation
  const createSiteMutation = useCreateSite(projectId)
  const createDomainMutation = useCreateSiteDomain(projectId)
  const createDeploymentMutation = useCreateVcsDeployment(projectId)

  const handleDeploy = async () => {
    if (!projectId || !siteName || !framework) {
      toast.error(t('Please fill in all required fields'))
      return
    }

    if (!domainValid) {
      toast.error(t('Please enter a valid domain'))
      return
    }

    // Reject an unusable key before the resource is created, so a rejected
    // variable cannot leave a half-configured site behind.
    const validationError = validateVariables(variables)
    if (validationError) {
      toast.error(validationError)
      return
    }

    setIsDeploying(true)

    try {
      // Use framework defaults from SDK (buildRuntime, adapter, fallbackFile) for create
      const defaults = getFrameworkDefaults(framework)
      // 1. Create the site
      const site = await createSiteMutation.mutateAsync({
        siteId: siteId || undefined,
        name: siteName,
        framework,
        buildRuntime: defaults.buildRuntime,
        installCommand: installCommand || undefined,
        buildCommand: buildCommand || undefined,
        startCommand: startCommand || undefined,
        outputDirectory: outputDirectory || undefined,
        adapter: defaults.adapter || undefined,
        fallbackFile:
          defaults.adapter === 'static' ? fallbackFile || undefined : undefined,
        installationId,
        providerRepositoryId,
        providerBranch: branch,
        providerSilentMode: silentMode,
        providerRootDirectory: rootDirectory || undefined,
      })

      // 2. Create domain rule
      if (domain) {
        await createDomainMutation.mutateAsync({
          domain,
          siteId: site.$id,
        })
      }

      // 3. Create environment variables
      if (variables.length > 0) {
        const projectSdk = sdk.forProject(projectId)
        await Promise.all(
          variables.map((v) =>
            projectSdk.sites.createVariable({
              siteId: site.$id,
              variableId: ID.unique(),
              key: v.key,
              value: v.value,
              secret: v.secret,
            }),
          ),
        )
      }

      // 4. Create VCS deployment
      const deployment = await createDeploymentMutation.mutateAsync({
        siteId: site.$id,
        type: 'branch',
        reference: branch,
        activate: true,
      })

      // Update form data with created resources
      updateFormData({
        createdSiteId: site.$id,
        createdDeploymentId: deployment.$id,
      })

      // Refetch sites list so cache is updated (list has refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['sites', 'project', projectId],
      })

      // Navigate to deploying screen
      navigate({
        to: '/projects/$projectId/sites/create/deploying',
        params: { projectId },
        search: { siteId: site.$id, deploymentId: deployment.$id },
      })
    } catch (error: unknown) {
      toast.error(error.message || t('Failed to create site'))
      setIsDeploying(false)
    }
  }

  const frameworkInfo = useMemo(() => {
    return frameworks.find((f) => f.key === framework)
  }, [frameworks, framework])

  // Single rule behind the Deploy button and the sidebar status, so a green
  // "Ready to deploy" can never sit next to a Deploy that refuses to run.
  // Only a dead token blocks: a transient failure clears on retry, and
  // framework detection failing on its own still leaves the manual picker,
  // so neither should strand a user who could otherwise deploy.
  const deployBlockedReason =
    getVcsInstallationErrorKind(repositoryError) === 'reconnect'
      ? t(
          'Reconnect the Git installation before you can deploy this repository.',
        )
      : !siteName
        ? t('Enter a site name to continue.')
        : !framework
          ? t('Select a framework to continue.')
          : !domainValid
            ? t('Enter a valid domain to continue.')
            : undefined

  const sidebarContent = (
    <div className="rounded-xl border border-border bg-gradient-to-b from-card/80 to-card/40 backdrop-blur-sm overflow-hidden">
      {/* Header with framework */}
      <div className="px-5 py-4 border-b border-border/50">
        <div className="flex items-center gap-3">
          <div className="relative">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/50 ring-1 ring-border/50">
              {frameworkInfo ? (
                <FrameworkIcon framework={framework} size="md" />
              ) : (
                <RepositoryProviderIcon className="h-5 w-5 text-muted-foreground" />
              )}
            </div>
            {frameworkInfo && (
              <div className="absolute -bottom-1 -end-1 flex h-5 w-5 items-center justify-center rounded-full bg-background ring-2 ring-background">
                <RepositoryProviderIcon className="h-3 w-3 text-muted-foreground" />
              </div>
            )}
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2">
              <h3 className="text-[13px] font-semibold text-foreground truncate">
                {frameworkInfo?.name || t('Repository')}
              </h3>
              {repository?.private ? (
                <Lock className="h-3 w-3 text-muted-foreground shrink-0" />
              ) : (
                <Globe className="h-3 w-3 text-muted-foreground shrink-0" />
              )}
            </div>
            <p className="text-[11px] text-muted-foreground truncate">
              {repository
                ? `${repository.organization}/${repository.name}`
                : repoName}
              {repository?.pushedAt && (
                <>
                  <span className="mx-1.5">·</span>
                  <span>
                    {t('Updated')} <DateTooltip date={repository.pushedAt} />
                  </span>
                </>
              )}
            </p>
          </div>
          {repository?.url && (
            <a
              href={repository.url}
              target="_blank"
              rel="noopener noreferrer"
              className="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors"
            >
              <ExternalLink className="h-4 w-4" />
            </a>
          )}
        </div>
      </div>

      {/* Configuration details */}
      <div className="px-5 py-4 space-y-3">
        <div className="flex items-center justify-between">
          <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
            <GitBranch className="h-3.5 w-3.5" />
            {t('Branch')}
          </span>
          <code className="text-[12px] font-mono text-foreground bg-muted/50 px-2 py-0.5 rounded">
            {branch || repository?.defaultBranch || 'main'}
          </code>
        </div>
        <div className="flex items-center justify-between">
          <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
            <FolderOpen className="h-3.5 w-3.5" />
            {t('Root directory')}
          </span>
          <code className="text-[12px] font-mono text-foreground bg-muted/50 px-2 py-0.5 rounded max-w-[120px] truncate">
            {rootDirectory || './'}
          </code>
        </div>
        {framework && (
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
              <Layers className="h-3.5 w-3.5" />
              {t('Framework')}
            </span>
            <span className="flex items-center gap-1.5 text-[12px] text-foreground">
              <FrameworkIcon framework={framework} size="sm" />
              <span className="capitalize">
                {frameworkInfo?.name || framework}
              </span>
            </span>
          </div>
        )}
        {variables.length > 0 && (
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
              <Key className="h-3.5 w-3.5" />
              {t('Variables')}
            </span>
            <span className="text-[12px] text-foreground">
              {variables.length} {t('configured')}
            </span>
          </div>
        )}
      </div>

      {/* Status indicator */}
      <div className="px-5 py-3 bg-muted/20 border-t border-border/50">
        <div className="flex items-center gap-2">
          <div
            className={cn(
              'h-2 w-2 rounded-full',
              installationErrorKind
                ? 'bg-red-500'
                : deployBlockedReason
                  ? 'bg-muted-foreground/40'
                  : 'bg-emerald-500 animate-pulse',
            )}
          />
          <span className="text-[11px] text-muted-foreground">
            {installationErrorKind
              ? t('Repository unavailable')
              : deployBlockedReason
                ? t('Configuration incomplete')
                : t('Ready to deploy')}
          </span>
        </div>
      </div>
    </div>
  )

  return (
    <WizardLayout
      title={t('Create site')}
      showBackButton
      backButtonLabel={t('Back')}
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
      maxWidth="max-w-[1400px]"
      footerAlign="right"
      sidebar={sidebarContent}
      footer={
        <>
          <Button
            variant="outline"
            onClick={() => window.history.back()}
            disabled={isDeploying}
          >
            {t('Cancel')}
          </Button>
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="inline-flex">
                  <Button
                    onClick={handleDeploy}
                    disabled={
                      isDeploying ||
                      createSiteMutation.isPending ||
                      !!deployBlockedReason
                    }
                  >
                    {t('Deploy')}
                  </Button>
                </span>
              </TooltipTrigger>
              {deployBlockedReason ? (
                <TooltipContent className="max-w-xs text-xs">
                  {deployBlockedReason}
                </TooltipContent>
              ) : null}
            </Tooltip>
          </TooltipProvider>
        </>
      }
    >
      {/* Installation failure: everything below is stale or blank until fixed */}
      {installationErrorKind && (
        <VcsInstallationErrorAlert
          kind={installationErrorKind}
          provider={installationProvider}
          organization={installationOrganization}
          reconnectUrl={reconnectUrl}
          onRetry={retryRepositoryLoad}
          isRetrying={repositoryFetching || detectFrameworkMutation.isPending}
        >
          {t(
            'This repository could not be read, so its branches, directories and framework are unavailable and the site cannot be deployed yet.',
          )}
        </VcsInstallationErrorAlert>
      )}

      {/* Repository card */}
      <div className="rounded-xl border border-border bg-card/50 p-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/50 ring-1 ring-border/50">
              {frameworkInfo ? (
                <FrameworkIcon framework={framework} size="md" />
              ) : (
                <RepositoryProviderIcon className="h-5 w-5 text-muted-foreground" />
              )}
            </div>
            <div>
              <p className="text-[13px] font-medium text-foreground">
                {repoOwner}/{repoName}
              </p>
              <p className="text-[11px] text-muted-foreground">
                {t(`${repositoryProviderLabel} Repository`)}
              </p>
            </div>
          </div>
          <Button
            variant="outline"
            size="sm"
            asChild
            className="h-8 text-[12px]"
          >
            <Link
              to="/projects/$projectId/sites/create/repositories"
              params={{ projectId: projectId! }}
            >
              {t('Change')}
            </Link>
          </Button>
        </div>
      </div>

      {/* Details section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Details')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          {/* Site name */}
          <div className="space-y-2">
            <Label htmlFor="site-name" className="text-[13px]">
              {t('Site name')}
            </Label>
            <Input
              id="site-name"
              value={siteName}
              onChange={(e) => setSiteName(e.target.value)}
              placeholder={t('My awesome site')}
              className="h-9 text-[13px]"
            />
          </div>

          {/* Site ID */}
          <div className="space-y-2">
            <Label className="text-[13px]">{t('Site ID')}</Label>
            <IdInput
              value={siteId}
              onChange={setSiteId}
              placeholder={t('Auto-generated')}
            />
          </div>

          {/* Framework selector */}
          <div className="space-y-2">
            <Label htmlFor="framework" className="text-[13px]">
              {t('Framework')}
            </Label>
            {detectFrameworkMutation.isPending ? (
              <div className="flex items-center gap-2">
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                <span className="text-[13px] text-muted-foreground">
                  {t('Detecting framework...')}
                </span>
              </div>
            ) : (
              <div className="flex items-center gap-2">
                <Select
                  value={framework}
                  onValueChange={(value) => {
                    setFramework(value)
                    const defaults = getFrameworkDefaults(value)
                    setInstallCommand(defaults.installCommand)
                    setBuildCommand(defaults.buildCommand)
                    setOutputDirectory(defaults.outputDirectory)
                    setFallbackFile(defaults.fallbackFile)
                  }}
                >
                  <SelectTrigger className="h-9 text-[13px] flex-1 min-w-0">
                    <SelectValue placeholder={t('Select framework')} />
                  </SelectTrigger>
                  <SelectContent>
                    {frameworks.map((f) => (
                      <SelectItem key={f.key} value={f.key}>
                        <div className="flex items-center gap-2">
                          <FrameworkIcon framework={f.key} size="sm" />
                          {f.name}
                        </div>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {!framework && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-9 shrink-0"
                    onClick={runFrameworkDetection}
                    disabled={detectFrameworkMutation.isPending}
                  >
                    {t('Detect')}
                  </Button>
                )}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Domain section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Domain')}
          </h3>
          <p className="text-[12px] text-muted-foreground mt-1">
            {t('Your site will be accessible at this URL')}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <DomainInput
            value={domain}
            onChange={setDomain}
            onValidChange={setDomainValid}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/20">
          <p className="text-[11px] text-muted-foreground leading-relaxed">
            {t(
              'Want to use your own domain? After deployment, you can connect a custom domain via CNAME record or let Appwrite manage your DNS.', // pragma: allowlist secret
            )}{' '}
            <DocsRouteLink
              className="link-neutral font-medium"
              href="/docs/products/sites/domains"
            >
              {t('Learn more →')}
            </DocsRouteLink>
          </p>
        </div>
      </div>

      {/* Repository section (collapsible like Build) */}
      <Accordion
        type="single"
        collapsible
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <AccordionItem value="repository" className="border-none">
          <AccordionTrigger className="px-6 py-4 hover:no-underline hover:bg-transparent cursor-pointer">
            <span className="text-[15px] font-semibold text-foreground">
              {t('Repository')}
            </span>
          </AccordionTrigger>
          <AccordionContent className="px-6 pb-4 pt-0 border-t border-border">
            <div className="space-y-4 pt-4">
              {/* Branch selector */}
              <BranchSelector
                projectId={projectId}
                installationId={installationId}
                providerRepositoryId={providerRepositoryId}
                value={branch}
                onChange={setBranch}
                label={t('Branch')}
                labelTooltip={t(
                  'Production branch for the repo linked to the site. Successful deployments from this branch get activated automatically.',
                )}
              />

              {/* Root directory */}
              <RootDirectoryPicker
                projectId={projectId}
                installationId={installationId}
                providerRepositoryId={providerRepositoryId}
                branch={branch || 'main'}
                value={rootDirectory}
                onChange={setRootDirectory}
                label={t('Root directory')}
                labelTooltip={t(
                  'Path to site code in the linked repo. Use the repository root (./) or a subdirectory that contains your app (e.g. ./apps/web).',
                )}
                description={t(
                  'Choose the directory containing your site code',
                )}
              />

              {/* Silent mode */}
              <div className="flex items-center justify-between">
                <div>
                  <Label htmlFor="silent-mode" className="text-[13px]">
                    {t('Silent mode')}
                  </Label>
                  <p className="text-[11px] text-muted-foreground">
                    {t('Disable automated comments on repository commits')}
                  </p>
                </div>
                <Switch
                  id="silent-mode"
                  checked={silentMode}
                  onCheckedChange={setSilentMode}
                />
              </div>
            </div>
          </AccordionContent>
        </AccordionItem>
      </Accordion>

      {/* Build settings */}
      <BuildSettings
        installCommand={installCommand}
        buildCommand={buildCommand}
        outputDirectory={outputDirectory}
        startCommand={startCommand}
        fallbackFile={fallbackFile}
        onInstallCommandChange={setInstallCommand}
        onBuildCommandChange={setBuildCommand}
        onOutputDirectoryChange={setOutputDirectory}
        onStartCommandChange={setStartCommand}
        onFallbackFileChange={setFallbackFile}
        frameworkKey={framework}
      />

      {/* Environment variables */}
      <VariablesSettingsCard
        variant="wizard"
        variables={variables}
        onChange={setVariables}
      />
    </WizardLayout>
  )
}

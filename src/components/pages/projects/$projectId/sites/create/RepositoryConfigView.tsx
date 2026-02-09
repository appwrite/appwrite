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
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { RootDirectoryPicker } from '@/components/global/shared/RootDirectoryPicker'
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
import { VCSDetectionType } from '@appwrite.io/console'
import {
  useRepository,
  useCreateSite,
  useCreateSiteDomain,
  useCreateVcsDeployment,
} from '@/lib/react-query/hooks'
import { useWizard } from './WizardContext'
import { DomainInput } from './DomainInput'
import { BuildSettings } from './BuildSettings'
import { EnvironmentVariables } from './EnvironmentVariables'

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

interface RepositoryConfigViewProps {
  installationId: string
  providerRepositoryId: string
}

export function RepositoryConfigView({
  installationId: installationIdFromUrl,
  providerRepositoryId: providerRepositoryIdFromUrl,
}: RepositoryConfigViewProps) {
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
  const [branch, setBranch] = useState(formData.providerBranch || 'main')
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
  const [variables, setVariables] = useState(formData.variables || [])
  const [domain, setDomain] = useState(formData.domain || '')
  const [domainValid, setDomainValid] = useState(formData.domainValid || false)
  const [isDeploying, setIsDeploying] = useState(false)

  // Fetch repository details (use URL params so it works after refresh)
  const { data: repository } = useRepository(
    projectId,
    installationId || null,
    providerRepositoryId || null,
  )

  // Default site name from repo when loaded
  const repoName = repository?.name ?? ''
  const repoOwner = repository?.organization ?? ''
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
        updateFormData({
          framework: detectedFramework,
          buildRuntime: defaults.buildRuntime,
          installCommand: data.installCommand ?? defaults.installCommand,
          buildCommand: data.buildCommand ?? defaults.buildCommand,
          outputDirectory: data.outputDirectory ?? defaults.outputDirectory,
        })
      }
    },
    onError: () => {
      toast.error('Could not detect framework. Select one manually.')
    },
  })

  const runFrameworkDetection = useCallback(() => {
    if (!projectId || !installationId || !providerRepositoryId) {
      toast.error('Repository not connected. Go back and select a repository.')
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
  ])

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
      toast.error('Please fill in all required fields')
      return
    }

    if (!domainValid) {
      toast.error('Please enter a valid domain')
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
        outputDirectory: outputDirectory || undefined,
        adapter: defaults.adapter || undefined,
        fallbackFile: defaults.fallbackFile || undefined,
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
      toast.error(error.message || 'Failed to create site')
      setIsDeploying(false)
    }
  }

  const frameworkInfo = useMemo(() => {
    return frameworks.find((f) => f.key === framework)
  }, [frameworks, framework])

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
                <GitHubIcon className="h-5 w-5 text-muted-foreground" />
              )}
            </div>
            {frameworkInfo && (
              <div className="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-background ring-2 ring-background">
                <GitHubIcon className="h-3 w-3 text-muted-foreground" />
              </div>
            )}
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2">
              <h3 className="text-[13px] font-semibold text-foreground truncate">
                {frameworkInfo?.name || 'Repository'}
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
                    Updated <DateTooltip date={repository.pushedAt} />
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
            Branch
          </span>
          <code className="text-[12px] font-mono text-foreground bg-muted/50 px-2 py-0.5 rounded">
            {branch || repository?.defaultBranch || 'main'}
          </code>
        </div>
        <div className="flex items-center justify-between">
          <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
            <FolderOpen className="h-3.5 w-3.5" />
            Root directory
          </span>
          <code className="text-[12px] font-mono text-foreground bg-muted/50 px-2 py-0.5 rounded max-w-[120px] truncate">
            {rootDirectory || './'}
          </code>
        </div>
        {framework && (
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
              <Layers className="h-3.5 w-3.5" />
              Framework
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
              Variables
            </span>
            <span className="text-[12px] text-foreground">
              {variables.length} configured
            </span>
          </div>
        )}
      </div>

      {/* Status indicator */}
      <div className="px-5 py-3 bg-muted/20 border-t border-border/50">
        <div className="flex items-center gap-2">
          <div className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
          <span className="text-[11px] text-muted-foreground">
            Ready to deploy
          </span>
        </div>
      </div>
    </div>
  )

  return (
    <WizardLayout
      title="Create site"
      showBackButton
      backButtonLabel="Back"
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
            Cancel
          </Button>
          <Button
            onClick={handleDeploy}
            disabled={
              isDeploying ||
              !siteName ||
              !framework ||
              !domainValid ||
              createSiteMutation.isPending
            }
          >
            Deploy
          </Button>
        </>
      }
    >
      {/* Repository card */}
      <div className="rounded-xl border border-border bg-card/50 p-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/50 ring-1 ring-border/50">
              {frameworkInfo ? (
                <FrameworkIcon framework={framework} size="md" />
              ) : (
                <GitHubIcon className="h-5 w-5 text-muted-foreground" />
              )}
            </div>
            <div>
              <p className="text-[13px] font-medium text-foreground">
                {repoOwner}/{repoName}
              </p>
              <p className="text-[11px] text-muted-foreground">
                GitHub Repository
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
              Change
            </Link>
          </Button>
        </div>
      </div>

      {/* Details section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Details</h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          {/* Site name */}
          <div className="space-y-2">
            <Label htmlFor="site-name" className="text-[13px]">
              Site name
            </Label>
            <Input
              id="site-name"
              value={siteName}
              onChange={(e) => setSiteName(e.target.value)}
              placeholder="My awesome site"
              className="h-9 text-[13px]"
            />
          </div>

          {/* Site ID */}
          <div className="space-y-2">
            <Label className="text-[13px]">Site ID</Label>
            <IdInput
              value={siteId}
              onChange={setSiteId}
              placeholder="Auto-generated"
            />
          </div>

          {/* Framework selector */}
          <div className="space-y-2">
            <Label htmlFor="framework" className="text-[13px]">
              Framework
            </Label>
            {detectFrameworkMutation.isPending ? (
              <div className="flex items-center gap-2">
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                <span className="text-[13px] text-muted-foreground">
                  Detecting framework...
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
                  }}
                >
                  <SelectTrigger className="h-9 text-[13px] flex-1 min-w-0">
                    <SelectValue placeholder="Select framework" />
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
                    Detect
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
          <h3 className="text-[15px] font-semibold text-foreground">Domain</h3>
          <p className="text-[12px] text-muted-foreground mt-1">
            Your site will be accessible at this URL
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
            Want to use your own domain? After deployment, you can connect a
            custom domain via CNAME record or let Appwrite manage your DNS.{' '}
            <a
              href="https://appwrite.io/docs/products/sites/domains"
              target="_blank"
              rel="noopener noreferrer"
              className="text-foreground hover:underline font-medium"
            >
              Learn more →
            </a>
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
              Repository
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
                label="Branch"
                labelTooltip="Production branch for the repo linked to the site. Successful deployments from this branch get activated automatically."
              />

              {/* Root directory */}
              <RootDirectoryPicker
                projectId={projectId}
                installationId={installationId}
                providerRepositoryId={providerRepositoryId}
                branch={branch || 'main'}
                value={rootDirectory}
                onChange={setRootDirectory}
                label="Root directory"
                labelTooltip="Path to site code in the linked repo. Use the repository root (./) or a subdirectory that contains your app (e.g. ./apps/web)."
                description="Choose the directory containing your site code"
              />

              {/* Silent mode */}
              <div className="flex items-center justify-between">
                <div>
                  <Label htmlFor="silent-mode" className="text-[13px]">
                    Silent mode
                  </Label>
                  <p className="text-[11px] text-muted-foreground">
                    Disable automated comments on repository commits
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
        onInstallCommandChange={setInstallCommand}
        onBuildCommandChange={setBuildCommand}
        onOutputDirectoryChange={setOutputDirectory}
        frameworkKey={framework}
      />

      {/* Environment variables */}
      <EnvironmentVariables variables={variables} onChange={setVariables} />
    </WizardLayout>
  )
}

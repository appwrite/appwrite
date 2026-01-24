/**
 * Repository Configuration View Component
 *
 * Main configuration screen for deploying a site from a Git repository.
 * Handles site details, branch selection, build settings, and domain configuration.
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
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
import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  GitBranch,
  Folder,
  ExternalLink,
  Loader2,
  ChevronLeft,
} from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { VCSDetectionType } from '@appwrite.io/console'
import {
  useRepository,
  useRepositoryBranches,
  useCreateSite,
  useCreateSiteDomain,
  useCreateVcsDeployment,
  Dependencies,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
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
  repositoryParam: string
}

export function RepositoryConfigView({ repositoryParam }: RepositoryConfigViewProps) {
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

  // Parse repository param
  const [repoOwner, repoName] = useMemo(() => {
    const decoded = decodeURIComponent(repositoryParam)
    const parts = decoded.split('/')
    return [parts[0] || '', parts[1] || '']
  }, [repositoryParam])

  // Local form state
  const [siteName, setSiteName] = useState(formData.siteName || repoName)
  const [siteId, setSiteId] = useState<string | undefined>(formData.siteId)
  const [framework, setFramework] = useState(formData.framework || '')
  const [branch, setBranch] = useState(formData.providerBranch || 'main')
  const [rootDirectory, setRootDirectory] = useState(formData.providerRootDirectory || './')
  const [silentMode, setSilentMode] = useState(formData.providerSilentMode || false)
  const [installCommand, setInstallCommand] = useState(formData.installCommand || '')
  const [buildCommand, setBuildCommand] = useState(formData.buildCommand || '')
  const [outputDirectory, setOutputDirectory] = useState(formData.outputDirectory || '')
  const [variables, setVariables] = useState(formData.variables || [])
  const [domain, setDomain] = useState(formData.domain || '')
  const [domainValid, setDomainValid] = useState(formData.domainValid || false)
  const [isDeploying, setIsDeploying] = useState(false)

  // Fetch repository details
  const { data: repository, isLoading: repositoryLoading } = useRepository(
    projectId,
    formData.installationId || null,
    formData.providerRepositoryId || null,
  )

  // Fetch branches
  const { data: branchesData, isLoading: branchesLoading } = useRepositoryBranches(
    projectId,
    formData.installationId || null,
    formData.providerRepositoryId || null,
  )

  // Framework detection mutation
  const detectFrameworkMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !formData.installationId || !formData.providerRepositoryId) {
        throw new Error('Missing required parameters')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.vcs.createRepositoryDetection({
        installationId: formData.installationId,
        providerRepositoryId: formData.providerRepositoryId,
        type: VCSDetectionType.Framework,
        providerRootDirectory: rootDirectory,
      })
    },
    onSuccess: (data) => {
      if (data.framework) {
        setFramework(data.framework)
        const defaults = getFrameworkDefaults(data.framework)
        setInstallCommand(defaults.installCommand)
        setBuildCommand(defaults.buildCommand)
        setOutputDirectory(defaults.outputDirectory)
      }
    },
  })

  // Sort branches: main/master first
  const sortedBranches = useMemo(() => {
    if (!branchesData?.branches) return []
    const branches = [...branchesData.branches]
    branches.sort((a, b) => {
      if (a.name === 'main' || a.name === 'master') return -1
      if (b.name === 'main' || b.name === 'master') return 1
      return a.name.localeCompare(b.name)
    })
    return branches
  }, [branchesData])

  // Set default branch when loaded
  useEffect(() => {
    if (sortedBranches.length > 0 && !branch) {
      const defaultBranch = sortedBranches.find(
        (b) => b.name === 'main' || b.name === 'master',
      )
      setBranch(defaultBranch?.name || sortedBranches[0].name)
    }
  }, [sortedBranches, branch])

  // Detect framework on mount
  useEffect(() => {
    if (formData.installationId && formData.providerRepositoryId && !framework) {
      detectFrameworkMutation.mutate()
    }
  }, [formData.installationId, formData.providerRepositoryId])

  // Update build commands when framework changes
  useEffect(() => {
    if (framework) {
      const defaults = getFrameworkDefaults(framework)
      if (!installCommand) setInstallCommand(defaults.installCommand)
      if (!buildCommand) setBuildCommand(defaults.buildCommand)
      if (!outputDirectory) setOutputDirectory(defaults.outputDirectory)
    }
  }, [framework, getFrameworkDefaults])

  // Generate domain when site name changes
  useEffect(() => {
    if (siteName && !domain) {
      setDomain(generateDomain(siteName))
    }
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
      // 1. Create the site
      const site = await createSiteMutation.mutateAsync({
        siteId: siteId || undefined,
        name: siteName,
        framework,
        installCommand: installCommand || undefined,
        buildCommand: buildCommand || undefined,
        outputDirectory: outputDirectory || undefined,
        installationId: formData.installationId,
        providerRepositoryId: formData.providerRepositoryId,
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

      // Invalidate queries
      queryClient.invalidateQueries({ queryKey: Dependencies.SITES })

      // Navigate to deploying screen
      navigate({
        to: '/projects/$projectId/sites/create-site/deploying',
        params: { projectId },
        search: { siteId: site.$id, deploymentId: deployment.$id },
      })
    } catch (error: any) {
      toast.error(error.message || 'Failed to create site')
      setIsDeploying(false)
    }
  }

  const frameworkInfo = useMemo(() => {
    return frameworks.find((f) => f.key === framework)
  }, [frameworks, framework])

  const sidebarContent = (
    <div className="space-y-4">
      {/* Framework info */}
      {frameworkInfo && (
        <div className="rounded-xl border border-border bg-card/50 p-4">
          <div className="flex items-center gap-3 mb-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <FrameworkIcon framework={framework} size="md" />
            </div>
            <div>
              <h3 className="text-[13px] font-semibold text-foreground">
                {frameworkInfo.name}
              </h3>
              <p className="text-[11px] text-muted-foreground">
                Detected framework
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Repository info */}
      {repository && (
        <div className="rounded-xl border border-border bg-card/50 p-4">
          <div className="flex items-center gap-2 mb-3">
            <GitHubIcon className="h-4 w-4 text-muted-foreground" />
            <span className="text-[12px] text-muted-foreground">Repository</span>
          </div>
          <p className="text-[13px] font-medium text-foreground truncate">
            {repository.organization}/{repository.name}
          </p>
          {repository.url && (
            <a
              href={repository.url}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-2 flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground"
            >
              <ExternalLink className="h-3 w-3" />
              View on GitHub
            </a>
          )}
        </div>
      )}

      {/* Branch and directory info */}
      <div className="rounded-xl border border-border bg-card/50 p-4">
        <div className="space-y-2 text-[12px]">
          <div className="flex items-center justify-between">
            <span className="text-muted-foreground">Branch</span>
            <span className="font-mono text-foreground">{branch}</span>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-muted-foreground">Root directory</span>
            <span className="font-mono text-foreground">{rootDirectory}</span>
          </div>
        </div>
      </div>
    </div>
  )

  return (
    <WizardLayout
      title="Configure site"
      showBackButton
      backButtonLabel="Back"
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
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
            {isDeploying ? (
              <>
                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                Deploying...
              </>
            ) : (
              'Deploy'
            )}
          </Button>
        </>
      }
    >
      {/* Repository card */}
      <div className="rounded-xl border border-border bg-card/50 p-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <GitHubIcon className="h-5 w-5" />
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
              to="/projects/$projectId/sites/create-site/repositories"
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
        </div>
      </div>

      {/* Production branch section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Production branch
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          {/* Branch selector */}
          <div className="space-y-2">
            <Label htmlFor="branch" className="text-[13px]">
              Branch
            </Label>
            {branchesLoading ? (
              <div className="flex items-center gap-2">
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                <span className="text-[13px] text-muted-foreground">
                  Loading branches...
                </span>
              </div>
            ) : sortedBranches.length > 0 ? (
              <Select value={branch} onValueChange={setBranch}>
                <SelectTrigger className="h-9 text-[13px]">
                  <SelectValue placeholder="Select branch" />
                </SelectTrigger>
                <SelectContent>
                  {sortedBranches.map((b) => (
                    <SelectItem key={b.name} value={b.name}>
                      <div className="flex items-center gap-2">
                        <GitBranch className="h-3.5 w-3.5 text-muted-foreground" />
                        {b.name}
                      </div>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            ) : (
              <Input
                id="branch"
                value={branch}
                onChange={(e) => setBranch(e.target.value)}
                placeholder="main"
                className="h-9 font-mono text-[13px]"
              />
            )}
          </div>

          {/* Root directory */}
          <div className="space-y-2">
            <Label htmlFor="root-directory" className="text-[13px]">
              Root directory
            </Label>
            <Input
              id="root-directory"
              value={rootDirectory}
              onChange={(e) => setRootDirectory(e.target.value)}
              placeholder="./"
              className="h-9 font-mono text-[13px]"
            />
          </div>

          {/* Silent mode */}
          <div className="flex items-center justify-between">
            <div>
              <Label htmlFor="silent-mode" className="text-[13px]">
                Silent mode
              </Label>
              <p className="text-[11px] text-muted-foreground">
                Skip build logs in deployment output
              </p>
            </div>
            <Switch
              id="silent-mode"
              checked={silentMode}
              onCheckedChange={setSilentMode}
            />
          </div>
        </div>
      </div>

      {/* Configuration section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Configuration
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
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
                <SelectTrigger className="h-9 text-[13px]">
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
            )}
          </div>

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
          <EnvironmentVariables
            variables={variables}
            onChange={setVariables}
          />
        </div>
      </div>

      {/* Domain section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Domain</h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <DomainInput
            value={domain}
            onChange={setDomain}
            onValidChange={setDomainValid}
          />
        </div>
      </div>
    </WizardLayout>
  )
}

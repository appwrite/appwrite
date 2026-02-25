/**
 * Repository Configuration View (Function)
 *
 * Configure and create a function from a selected Git repository.
 * Runtime detection, branch/root, domain, variables, then create + VCS deployment.
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
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { RootDirectoryPicker } from '@/components/global/shared/RootDirectoryPicker'
import { DomainInput } from '@/components/global/shared/DomainInput'
import { EnvironmentVariablesCard } from '@/components/global/shared/EnvironmentVariablesCard'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import {
  Loader2,
  GitBranch,
  Key,
  FolderOpen,
  Lock,
  Globe,
  ExternalLink,
} from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ID, VCSDetectionType, VCSReferenceType } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useRepository,
  useProjectRuntimes,
  useFunctionSpecifications,
} from '@/lib/react-query/hooks'
import {
  getFirstEnabledSpecification,
  isSpecificationAllowedInPlan,
  hasUnavailableSpecifications,
} from '@/lib/specifications'
import { useFunctionWizard } from './WizardContext'

function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className}>
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

export interface FunctionWizardVariable {
  key: string
  value: string
  secret: boolean
}

interface RepositoryConfigViewProps {
  repositoryParam: string
  installationIdFromSearch?: string
  providerRepositoryIdFromSearch?: string
}

export function RepositoryConfigView({
  repositoryParam,
  installationIdFromSearch,
  providerRepositoryIdFromSearch,
}: RepositoryConfigViewProps) {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { formData, updateFormData, generateDomain, baseDomain } =
    useFunctionWizard()

  const [repoOwner, repoName] = useMemo(() => {
    const decoded = decodeURIComponent(repositoryParam)
    const parts = decoded.split('/')
    return [parts[0] || '', parts[1] || '']
  }, [repositoryParam])

  const installationId = formData.installationId || installationIdFromSearch
  const providerRepositoryId =
    formData.providerRepositoryId || providerRepositoryIdFromSearch

  useEffect(() => {
    if (installationIdFromSearch && !formData.installationId) {
      updateFormData({ installationId: installationIdFromSearch })
    }
    if (providerRepositoryIdFromSearch && !formData.providerRepositoryId) {
      updateFormData({ providerRepositoryId: providerRepositoryIdFromSearch })
    }
  }, [
    installationIdFromSearch,
    providerRepositoryIdFromSearch,
    formData.installationId,
    formData.providerRepositoryId,
    updateFormData,
  ])

  const [functionName, setFunctionName] = useState(
    formData.functionName || repoName,
  )
  const [functionId, setFunctionId] = useState<string | undefined>()
  const [runtime, setRuntime] = useState(formData.runtime || '')
  const [entrypoint, setEntrypoint] = useState('')
  const [commands, setCommands] = useState('')
  const [branch, setBranch] = useState('main')
  const [rootDirectory, setRootDirectory] = useState('./')
  const [silentMode, setSilentMode] = useState(false)
  const [variables, setVariables] = useState<FunctionWizardVariable[]>([])
  const [domain, setDomain] = useState('')
  const [domainValid, setDomainValid] = useState(false)
  const [isPublic, setIsPublic] = useState(true)
  const [specification, setSpecification] = useState('')
  const [isDeploying, setIsDeploying] = useState(false)

  const { data: repository } = useRepository(
    projectId,
    installationId || null,
    providerRepositoryId || null,
  )
  const { data: runtimesData } = useProjectRuntimes(projectId)
  const { data: specificationsData } = useFunctionSpecifications(projectId)
  const runtimes = runtimesData?.runtimes ?? []
  const specifications = useMemo(
    () => specificationsData?.specifications ?? [],
    [specificationsData],
  )

  useEffect(() => {
    if (specifications.length > 0 && !specification) {
      const first = getFirstEnabledSpecification(specifications)
      if (first?.slug) setSpecification(first.slug)
    }
  }, [specifications, specification])

  const detectRuntimeMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !installationId || !providerRepositoryId) {
        throw new Error('Missing required parameters')
      }
      const projectSdk = sdk.forProject(projectId)
      const result = await projectSdk.vcs.createRepositoryDetection({
        installationId,
        providerRepositoryId,
        type: VCSDetectionType.Runtime,
        providerRootDirectory: rootDirectory,
      })
      return result as Models.DetectionFramework & {
        runtime?: string
        entrypoint?: string
        commands?: string
      }
    },
    onSuccess: (data) => {
      const r = data as {
        runtime?: string
        entrypoint?: string
        commands?: string
      }
      if (r.runtime) setRuntime(r.runtime)
      if (r.entrypoint != null) setEntrypoint(r.entrypoint)
      if (r.commands != null) setCommands(r.commands)
    },
  })

  useEffect(() => {
    if (installationId && providerRepositoryId && !runtime) {
      detectRuntimeMutation.mutate()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [installationId, providerRepositoryId])

  useEffect(() => {
    if (functionName && !domain) {
      setDomain(generateDomain(functionName))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [functionName, generateDomain])

  const handleDeploy = async () => {
    if (!projectId || !functionName || !runtime) {
      toast.error('Please fill in function name and runtime')
      return
    }
    if (!domain.trim()) {
      toast.error('Please enter a domain')
      return
    }
    if (!installationId || !providerRepositoryId) {
      toast.error('Missing repository connection')
      return
    }

    setIsDeploying(true)
    const projectSdk = sdk.forProject(projectId)
    const finalFunctionId = (functionId?.trim() || ID.unique()) as string
    const domainTrimmed = domain.toLowerCase().trim()

    try {
      await projectSdk.functions.create({
        functionId: finalFunctionId,
        name: functionName.trim(),
        runtime: runtime as unknown,
        execute: isPublic ? ['any'] : [],
        enabled: true,
        entrypoint: entrypoint.trim() || undefined,
        commands: commands.trim() || undefined,
        installationId,
        providerRepositoryId,
        providerBranch: branch,
        providerSilentMode: silentMode,
        providerRootDirectory: rootDirectory || undefined,
        specification: specification || undefined,
      })

      await projectSdk.proxy.createFunctionRule({
        domain: domainTrimmed,
        functionId: finalFunctionId,
        branch,
      })

      for (const v of variables) {
        if (!v.key.trim()) continue
        await projectSdk.functions.createVariable({
          functionId: finalFunctionId,
          key: v.key.trim(),
          value: v.value,
          secret: v.secret,
        })
      }

      await projectSdk.functions.createVcsDeployment({
        functionId: finalFunctionId,
        type: VCSReferenceType.Branch,
        reference: branch,
        activate: true,
      })

      await queryClient.refetchQueries({
        queryKey: ['functions', 'project', projectId],
      })

      toast.success('Function created')
      navigate({
        to: '/projects/$projectId/functions/$functionId',
        params: { projectId, functionId: finalFunctionId },
      })
    } catch (err: unknown) {
      toast.error(err?.message || 'Failed to create function')
      setIsDeploying(false)
    }
  }

  const sidebarContent = (
    <div className="rounded-xl border border-border bg-gradient-to-b from-card/80 to-card/40 backdrop-blur-sm overflow-hidden">
      <div className="px-5 py-4 border-b border-border/50">
        <div className="flex items-center gap-3">
          <div className="relative">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/50 ring-1 ring-border/50">
              <RuntimeIcon
                runtime={runtime}
                className="h-5 w-5 text-muted-foreground"
              />
            </div>
            <div className="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-background ring-2 ring-background">
              <GitHubIcon className="h-3 w-3 text-muted-foreground" />
            </div>
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2">
              <h3 className="text-[13px] font-semibold text-foreground truncate">
                {repository?.name || repoName}
              </h3>
              {repository?.private !== undefined &&
                (repository.private ? (
                  <Lock className="h-3 w-3 text-muted-foreground shrink-0" />
                ) : (
                  <Globe className="h-3 w-3 text-muted-foreground shrink-0" />
                ))}
            </div>
            <p className="text-[11px] text-muted-foreground truncate">
              {repository
                ? `${repository.organization}/${repository.name}`
                : `${repoOwner}/${repoName}`}
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
        {runtime && (
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
              Runtime
            </span>
            <span className="flex items-center gap-1.5 text-[12px] text-foreground">
              <RuntimeIcon runtime={runtime} size="sm" />
              <span className="truncate max-w-[100px]">{runtime}</span>
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
      title="Create function"
      showBackButton
      backButtonLabel="Back"
      fallbackPath={`/projects/${projectId}/functions`}
      onClose={() =>
        navigate({
          to: '/projects/$projectId/functions',
          params: { projectId: projectId! },
        })
      }
      onBack={() =>
        navigate({
          to: '/projects/$projectId/functions/create',
          params: { projectId: projectId! },
        })
      }
      fullscreen
      maxWidth="max-w-[1400px]"
      footerAlign="right"
      sidebar={sidebarContent}
      footer={
        <>
          <Button
            variant="outline"
            onClick={() =>
              navigate({
                to: '/projects/$projectId/functions',
                params: { projectId: projectId! },
              })
            }
            disabled={isDeploying}
          >
            Cancel
          </Button>
          <Button
            onClick={handleDeploy}
            disabled={
              isDeploying ||
              !functionName ||
              !runtime ||
              !domain.trim() ||
              !domainValid
            }
          >
            {isDeploying ? (
              <>
                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                Creating...
              </>
            ) : (
              'Create and deploy'
            )}
          </Button>
        </>
      }
    >
      <div className="rounded-xl border border-border bg-card/50 p-4 mb-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
              <GitHubIcon className="h-5 w-5 text-muted-foreground" />
            </div>
            <div>
              <p className="text-[13px] font-medium text-foreground">
                {repoOwner}/{repoName}
              </p>
              <p className="text-[11px] text-muted-foreground">
                Git repository
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
              to="/projects/$projectId/functions/create"
              params={{ projectId: projectId! }}
            >
              Change
            </Link>
          </Button>
        </div>
      </div>

      {/* Details card */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Details</h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <div className="space-y-2">
            <Label htmlFor="function-name" className="text-[13px]">
              Function name
            </Label>
            <Input
              id="function-name"
              value={functionName}
              onChange={(e) => setFunctionName(e.target.value)}
              placeholder="My function"
              className="h-9 text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[13px]">Function ID</Label>
            <IdInput
              value={functionId}
              onChange={setFunctionId}
              placeholder="Auto-generated"
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[13px]">Runtime</Label>
            {detectRuntimeMutation.isPending ? (
              <div className="flex items-center gap-2">
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                <span className="text-[13px] text-muted-foreground">
                  Detecting runtime...
                </span>
              </div>
            ) : (
              <Select value={runtime} onValueChange={setRuntime}>
                <SelectTrigger className="h-9 text-[13px]">
                  <SelectValue placeholder="Select runtime" />
                </SelectTrigger>
                <SelectContent>
                  {runtimes.map((r) => (
                    <SelectItem key={r.$id} value={r.$id || r.key}>
                      <div className="flex items-center gap-2">
                        <RuntimeIcon runtime={r.$id || r.key} size="sm" />
                        {r.name}
                      </div>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </div>
          <div className="flex items-center justify-between">
            <div>
              <Label className="text-[13px]">Public</Label>
              <p className="text-[11px] text-muted-foreground">
                Allow anyone to execute this function (execute role: any)
              </p>
            </div>
            <Switch checked={isPublic} onCheckedChange={setIsPublic} />
          </div>
          {specifications.length > 0 && (
            <div className="space-y-2">
              <Label htmlFor="specification" className="text-[13px]">
                Compute
              </Label>
              <Select
                value={specification || undefined}
                onValueChange={setSpecification}
              >
                <SelectTrigger id="specification" className="h-9 text-[13px]">
                  <SelectValue placeholder="Select specification" />
                </SelectTrigger>
                <SelectContent>
                  {specifications
                    .filter((s) => s.slug?.trim())
                    .map((spec) => (
                      <SelectItem
                        key={spec.slug}
                        value={spec.slug}
                        disabled={!isSpecificationAllowedInPlan(spec)}
                      >
                        {spec.cpus} CPU, {spec.memory}MB RAM
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
              <p className="text-[11px] text-muted-foreground">
                Runtime specification for your function
              </p>
              {hasUnavailableSpecifications(specifications) && (
                <p className="text-[11px] text-muted-foreground">
                  Upgrade your plan to unlock additional specifications.
                </p>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Domain card – same structure and validation as sites */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Domain</h3>
          <p className="text-[12px] text-muted-foreground mt-1">
            Your function will be reachable at this URL
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <DomainInput
            value={domain}
            onChange={setDomain}
            onValidChange={setDomainValid}
            baseDomain={baseDomain}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/20">
          <p className="text-[11px] text-muted-foreground leading-relaxed">
            After deployment you can connect a custom domain via your function
            settings.{' '}
            <a
              href="https://appwrite.io/docs/functions"
              target="_blank"
              rel="noopener noreferrer"
              className="text-foreground hover:underline font-medium"
            >
              Learn more →
            </a>
          </p>
        </div>
      </div>

      {/* Production branch – when Git is used */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Production branch
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <BranchSelector
            projectId={projectId}
            installationId={installationId}
            providerRepositoryId={providerRepositoryId}
            value={branch}
            onChange={setBranch}
            label="Branch"
          />
          <RootDirectoryPicker
            projectId={projectId}
            installationId={installationId}
            providerRepositoryId={providerRepositoryId}
            branch={branch || 'main'}
            value={rootDirectory}
            onChange={setRootDirectory}
            label="Root directory"
            description="Directory containing your function code"
          />
          <div className="flex items-center justify-between">
            <div>
              <Label className="text-[13px]">Silent mode</Label>
              <p className="text-[11px] text-muted-foreground">
                Disable automated comments on repository commits
              </p>
            </div>
            <Switch checked={silentMode} onCheckedChange={setSilentMode} />
          </div>
        </div>
      </div>

      {/* Build settings – accordion like sites */}
      <Accordion
        type="single"
        collapsible
        className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6"
      >
        <AccordionItem value="build-settings" className="border-none">
          <AccordionTrigger className="px-6 py-4 hover:no-underline hover:bg-transparent cursor-pointer">
            <span className="text-[15px] font-semibold text-foreground">
              Build
            </span>
          </AccordionTrigger>
          <AccordionContent className="px-6 pb-4 pt-0 border-t border-border">
            <div className="space-y-4 pt-4">
              <div className="space-y-2">
                <Label htmlFor="entrypoint" className="text-[13px]">
                  Entrypoint
                </Label>
                <Input
                  id="entrypoint"
                  value={entrypoint}
                  onChange={(e) => setEntrypoint(e.target.value)}
                  placeholder="src/main.js"
                  className="h-9 text-[13px] font-mono"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="commands" className="text-[13px]">
                  Build commands
                </Label>
                <Input
                  id="commands"
                  value={commands}
                  onChange={(e) => setCommands(e.target.value)}
                  placeholder="npm install && npm run build"
                  className="h-9 text-[13px] font-mono"
                />
              </div>
            </div>
          </AccordionContent>
        </AccordionItem>
      </Accordion>

      {/* Environment variables – shared card */}
      <EnvironmentVariablesCard variables={variables} onChange={setVariables} />
    </WizardLayout>
  )
}

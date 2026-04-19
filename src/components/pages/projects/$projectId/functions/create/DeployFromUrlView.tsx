/**
 * Deploy from URL View
 *
 * Create a function from a GitHub repo URL (e.g. "Deploy to Appwrite" button).
 * Repo is parsed from query params; no repo selected in the wizard cover.
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
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
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ID, Runtime, TemplateReferenceType } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useProjectRuntimes,
  useFunctionSpecifications,
} from '@/lib/react-query/hooks'
import {
  getFirstEnabledSpecification,
  isSpecificationAllowedInPlan,
  hasUnavailableSpecifications,
} from '@/lib/specifications'
import { useFunctionWizard } from './WizardContext'
import { FunctionDomainCard } from './_components/FunctionDomainCard'
import { EnvironmentVariablesCard } from '@/components/global/shared/EnvironmentVariablesCard'
import type { FunctionWizardVariable } from './RepositoryConfigView'

function parseRepo(repo?: string): { owner: string; name: string } | null {
  if (!repo?.trim()) return null
  const s = repo.trim()
  if (s.includes('/')) {
    const [owner, name] = s
      .split('/')
      .map((x) => x.replace(/\.git$/, '').trim())
    if (owner && name) return { owner, name }
  }
  return null
}

interface DeployFromUrlViewProps {
  repoFromSearch?: string
  runtimeFromSearch?: string
  entrypointFromSearch?: string
  installFromSearch?: string
  rootDirFromSearch?: string
  envFromSearch?: string
}

export function DeployFromUrlView({
  repoFromSearch,
  runtimeFromSearch,
  entrypointFromSearch,
  installFromSearch,
  rootDirFromSearch,
}: DeployFromUrlViewProps) {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { generateDomain, updateFormData } = useFunctionWizard()

  const parsed = parseRepo(repoFromSearch)
  const [functionName, setFunctionName] = useState(parsed?.name || '')
  const [functionId, setFunctionId] = useState<string | undefined>()
  const [runtime, setRuntime] = useState(runtimeFromSearch || '')
  const [entrypoint, setEntrypoint] = useState(entrypointFromSearch || '')
  const [commands, setCommands] = useState(installFromSearch || '')
  const [rootDirectory, setRootDirectory] = useState(rootDirFromSearch || './')
  const [reference, setReference] = useState('main')
  const [domain, setDomain] = useState('')
  const [domainValid, setDomainValid] = useState(false)
  const [variables, setVariables] = useState<FunctionWizardVariable[]>([])
  const [isPublic, setIsPublic] = useState(true)
  const [specification, setSpecification] = useState('')
  const [isDeploying, setIsDeploying] = useState(false)

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

  useEffect(() => {
    if (parsed?.name && !functionName) setFunctionName(parsed.name)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [parsed])

  useEffect(() => {
    if (functionName && !domain) {
      setDomain(generateDomain(functionName))
      setDomainValid(true)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [functionName, generateDomain])

  const handleDeploy = async () => {
    if (!projectId) return
    if (!parsed) {
      toast.error('Repository URL or owner/name is required')
      return
    }
    if (!functionName || !runtime) {
      toast.error('Please fill in function name and runtime')
      return
    }
    if (!domain.trim()) {
      toast.error('Please enter a domain')
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
        runtime: runtime as Runtime,
        execute: isPublic ? ['any'] : [],
        entrypoint: entrypoint.trim() || undefined,
        commands: commands.trim() || undefined,
        providerSilentMode: true,
        buildSpecification: specification || undefined,
      })

      await projectSdk.proxy.createFunctionRule({
        domain: domainTrimmed,
        functionId: finalFunctionId,
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

      const deployment = await projectSdk.functions.createTemplateDeployment({
        functionId: finalFunctionId,
        repository: parsed.name,
        owner: parsed.owner,
        rootDirectory: rootDirectory || './',
        type: TemplateReferenceType.Branch,
        reference: reference || 'main',
        activate: true,
      })

      updateFormData({
        createdFunctionId: finalFunctionId,
        createdDeploymentId: deployment.$id,
      })

      await queryClient.refetchQueries({
        queryKey: ['functions', 'project', projectId],
      })

      navigate({
        to: '/projects/$projectId/functions/create/deploying',
        params: { projectId },
        search: { functionId: finalFunctionId, deploymentId: deployment.$id },
      })
    } catch (err) {
      toast.error(
        err instanceof Error ? err.message : 'Failed to create function',
      )
      setIsDeploying(false)
    }
  }

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
              !parsed ||
              !functionName ||
              !runtime ||
              !domain.trim() ||
              !domainValid
            }
          >
            Create and deploy
          </Button>
        </>
      }
    >
      {!parsed && (
        <div className="rounded-xl border border-border bg-card/50 p-6 mb-6">
          <p className="text-[13px] text-muted-foreground">
            Add a repository URL or owner/name in the address bar, e.g.{' '}
            <code className="bg-muted px-1 rounded">?repo=owner/repo</code> or{' '}
            <code className="bg-muted px-1 rounded">
              ?repository=owner/repo
            </code>
            .
          </p>
        </div>
      )}

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
          </div>
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
              placeholder="npm install"
              className="h-9 text-[13px] font-mono"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="root-dir" className="text-[13px]">
              Root directory
            </Label>
            <Input
              id="root-dir"
              value={rootDirectory}
              onChange={(e) => setRootDirectory(e.target.value)}
              placeholder="./"
              className="h-9 text-[13px] font-mono"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="reference" className="text-[13px]">
              Branch or tag
            </Label>
            <Input
              id="reference"
              value={reference}
              onChange={(e) => setReference(e.target.value)}
              placeholder="main"
              className="h-9 text-[13px] font-mono"
            />
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

      <FunctionDomainCard
        domain={domain}
        setDomain={setDomain}
        domainValid={domainValid}
        setDomainValid={setDomainValid}
      />

      <EnvironmentVariablesCard variables={variables} onChange={setVariables} />
    </WizardLayout>
  )
}

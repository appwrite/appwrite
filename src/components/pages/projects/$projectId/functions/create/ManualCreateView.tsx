/**
 * Manual Create View
 *
 * Create a function and upload a .tar.gz file as the first deployment.
 * No Git connection.
 */

import { useState, useEffect, useRef } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
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
import { Loader2, Upload } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ID } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { useProjectRuntimes } from '@/lib/react-query/hooks'
import { useFunctionWizard } from './WizardContext'
import type { FunctionWizardVariable } from './RepositoryConfigView'

interface ManualCreateViewProps {
  runtimeFromSearch?: string
}

export function ManualCreateView({ runtimeFromSearch }: ManualCreateViewProps) {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const { generateDomain } = useFunctionWizard()

  const [functionName, setFunctionName] = useState('')
  const [functionId, setFunctionId] = useState<string | undefined>()
  const [runtime, setRuntime] = useState(runtimeFromSearch || '')
  const [entrypoint, setEntrypoint] = useState('')
  const [commands, setCommands] = useState('')
  const [domain, setDomain] = useState('')
  const [domainValid, setDomainValid] = useState(false)
  const [variables, setVariables] = useState<FunctionWizardVariable[]>([])
  const [file, setFile] = useState<File | null>(null)
  const [isDeploying, setIsDeploying] = useState(false)

  const { data: runtimesData } = useProjectRuntimes(projectId)
  const runtimes = runtimesData?.runtimes ?? []

  useEffect(() => {
    if (runtimeFromSearch && !runtime) setRuntime(runtimeFromSearch)
  }, [runtimeFromSearch])

  useEffect(() => {
    if (functionName && !domain) {
      setDomain(generateDomain(functionName))
      setDomainValid(true)
    }
  }, [functionName, generateDomain])

  const handleDeploy = async () => {
    if (!projectId) return
    if (!functionName || !runtime) {
      toast.error('Please fill in function name and runtime')
      return
    }
    if (!domain.trim()) {
      toast.error('Please enter a domain')
      return
    }
    if (!file) {
      toast.error('Please upload a .tar.gz file')
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
        runtime: runtime as any,
        execute: [],
        enabled: true,
        entrypoint: entrypoint.trim() || undefined,
        commands: commands.trim() || undefined,
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

      const deployment = await projectSdk.functions.createDeployment({
        functionId: finalFunctionId,
        code: file,
        activate: true,
        entrypoint: entrypoint.trim() || undefined,
        commands: commands.trim() || undefined,
      })

      await queryClient.refetchQueries({
        queryKey: ['functions', 'project', projectId],
      })

      toast.success('Function created')
      navigate({
        to: '/projects/$projectId/functions/$functionId/deployments/$deploymentId',
        params: {
          projectId,
          functionId: finalFunctionId,
          deploymentId: deployment.$id,
        },
      })
    } catch (err: any) {
      toast.error(err?.message || 'Failed to create function')
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
              !functionName ||
              !runtime ||
              !domain.trim() ||
              !domainValid ||
              !file
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
        </div>
      </div>

      <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Domain</h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <Input
            value={domain}
            onChange={(e) => {
              setDomain(e.target.value)
              setDomainValid(e.target.value.trim().length > 0)
            }}
            placeholder="my-function.appwrite.network"
            className="h-9 text-[13px]"
          />
        </div>
      </div>

      <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Upload code
          </h3>
          <p className="text-[12px] text-muted-foreground mt-1">
            Upload a .tar.gz archive containing your function code
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <input
            ref={fileInputRef}
            type="file"
            accept=".tar.gz,.tgz"
            className="hidden"
            onChange={(e) => setFile(e.target.files?.[0] || null)}
          />
          <Button
            type="button"
            variant="outline"
            className="h-9 text-[13px] gap-1.5"
            onClick={() => fileInputRef.current?.click()}
          >
            <Upload className="h-4 w-4" />
            {file ? file.name : 'Choose .tar.gz file'}
          </Button>
        </div>
      </div>

      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Environment variables
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-3">
          {variables.map((v, i) => (
            <div key={i} className="flex gap-2 items-center">
              <Input
                placeholder="Key"
                value={v.key}
                onChange={(e) =>
                  setVariables((prev) =>
                    prev.map((x, j) =>
                      j === i ? { ...x, key: e.target.value } : x,
                    ),
                  )
                }
                className="h-9 text-[13px] flex-1"
              />
              <Input
                type={v.secret ? 'password' : 'text'}
                placeholder="Value"
                value={v.value}
                onChange={(e) =>
                  setVariables((prev) =>
                    prev.map((x, j) =>
                      j === i ? { ...x, value: e.target.value } : x,
                    ),
                  )
                }
                className="h-9 text-[13px] flex-1"
              />
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-9 w-9 p-0"
                onClick={() =>
                  setVariables((prev) => prev.filter((_, j) => j !== i))
                }
              >
                ×
              </Button>
            </div>
          ))}
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 text-[12px]"
            onClick={() =>
              setVariables((prev) => [
                ...prev,
                { key: '', value: '', secret: false },
              ])
            }
          >
            Add variable
          </Button>
        </div>
      </div>
    </WizardLayout>
  )
}

/**
 * Manual Create View
 *
 * Create a function and upload a .tar.gz file as the first deployment.
 * No Git connection.
 */

import { useState, useEffect, useRef, useMemo } from 'react'
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
import { Upload } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ID } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useProject,
  useProjectRuntimes,
  useFunctionSpecifications,
} from '@/lib/react-query/hooks'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import {
  getFirstEnabledSpecification,
  isSpecificationAllowedInPlan,
  hasUnavailableSpecifications,
  SpecificationType,
} from '@/lib/specifications'
import { useFunctionWizard } from './WizardContext'
import { FunctionDomainCard } from './_components/FunctionDomainCard'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import type { FunctionWizardVariable } from './RepositoryConfigView'
import { useT } from '@/lib/i18n/translate'
import { validateVariables } from '@/lib/variables'

interface ManualCreateViewProps {
  runtimeFromSearch?: string
}

export function ManualCreateView({ runtimeFromSearch }: ManualCreateViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const { generateDomain, updateFormData } = useFunctionWizard()

  const [functionName, setFunctionName] = useState('')
  const [functionId, setFunctionId] = useState<string | undefined>()
  const [runtime, setRuntime] = useState(runtimeFromSearch || '')
  const [entrypoint, setEntrypoint] = useState('')
  const [commands, setCommands] = useState('')
  const [domain, setDomain] = useState('')
  const [domainValid, setDomainValid] = useState(false)
  const [variables, setVariables] = useState<FunctionWizardVariable[]>([])
  const [isPublic, setIsPublic] = useState(true)
  const [specification, setSpecification] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [isDeploying, setIsDeploying] = useState(false)

  const { data: runtimesData } = useProjectRuntimes(projectId)
  const { data: specificationsData } = useFunctionSpecifications(
    projectId,
    SpecificationType.Builds,
  )
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
    if (runtimeFromSearch && !runtime) setRuntime(runtimeFromSearch)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [runtimeFromSearch])

  useEffect(() => {
    if (functionName && !domain) {
      setDomain(generateDomain(functionName))
      setDomainValid(true)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [functionName, generateDomain])

  const handleDeploy = async () => {
    if (!projectId) return
    if (!functionName || !runtime) {
      toast.error(t('Please fill in function name and runtime'))
      return
    }
    if (!domain.trim()) {
      toast.error(t('Please enter a domain'))
      return
    }
    if (!file) {
      toast.error(t('Please upload a .tar.gz file'))
      return
    }

    // Reject an unusable key before the resource is created, so a rejected
    // variable cannot leave a half-configured function behind. Only the rows
    // that get written are checked -- the keyless ones are skipped below.
    const validationError = validateVariables(
      variables.filter((v) => v.key.trim()),
    )
    if (validationError) {
      toast.error(validationError)
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
          variableId: ID.unique(),
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
    } catch (err: unknown) {
      toast.error(err?.message || t('Failed to create function'))
      setIsDeploying(false)
    }
  }

  return (
    <WizardLayout
      title={t('Create function')}
      showBackButton
      backButtonLabel={t('Back')}
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
            {t('Cancel')}
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
            {t('Create and deploy')}
          </Button>
        </>
      }
    >
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Details')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <div className="space-y-2">
            <Label htmlFor="function-name" className="text-[13px]">
              {t('Function name')}
            </Label>
            <Input
              id="function-name"
              value={functionName}
              onChange={(e) => setFunctionName(e.target.value)}
              placeholder={t('My function')}
              className="h-9 text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[13px]">{t('Function ID')}</Label>
            <IdInput
              value={functionId}
              onChange={setFunctionId}
              placeholder={t('Auto-generated')}
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[13px]">{t('Runtime')}</Label>
            <Select value={runtime} onValueChange={setRuntime}>
              <SelectTrigger className="h-9 text-[13px]">
                <SelectValue placeholder={t('Select runtime')} />
              </SelectTrigger>
              <SelectContent>
                {runtimes.map((r) => (
                  <SelectItem key={r.$id} value={r.$id || r.key}>
                    <div className="flex items-center gap-2">
                      <RuntimeIcon runtime={r.$id || r.key} size="sm" />
                      <span>
                        {r.name} {r.version}
                      </span>
                    </div>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label htmlFor="entrypoint" className="text-[13px]">
              {t('Entrypoint')}
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
              {t('Build commands')}
            </Label>
            <Input
              id="commands"
              value={commands}
              onChange={(e) => setCommands(e.target.value)}
              placeholder="npm install"
              className="h-9 text-[13px] font-mono"
            />
          </div>
          <div className="flex items-center justify-between">
            <div>
              <Label className="text-[13px]">{t('Public')}</Label>
              <p className="text-[11px] text-muted-foreground">
                {t('Allow anyone to execute this function (execute role: any)')}
              </p>
            </div>
            <Switch checked={isPublic} onCheckedChange={setIsPublic} />
          </div>
          {specifications.length > 0 && (
            <div className="space-y-2">
              <Label htmlFor="specification" className="text-[13px]">
                {t('Compute')}
              </Label>
              <Select
                value={specification || undefined}
                onValueChange={setSpecification}
              >
                <SelectTrigger id="specification" className="h-9 text-[13px]">
                  <SelectValue placeholder={t('Select specification')} />
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
                {t('Runtime specification for your function')}
              </p>
              {hasUnavailableSpecifications(specifications) && (
                <p className="text-[11px] text-muted-foreground">
                  <UpgradePlanLink orgId={project?.teamId} />{' '}
                  {t('to unlock additional specifications.')}
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

      <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Upload code')}
          </h3>
          <p className="text-[12px] text-muted-foreground mt-1">
            {t('Upload a .tar.gz archive containing your function code')}
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
            {file ? file.name : t('Choose .tar.gz file')}
          </Button>
        </div>
      </div>

      <VariablesSettingsCard
        variant="wizard"
        variables={variables}
        onChange={setVariables}
      />
    </WizardLayout>
  )
}

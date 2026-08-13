import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { cn } from '@/lib/utils'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { getAdapterCopy, getAdapterDescriptionSegments } from '@/lib/frameworks'
import { getFrameworkAdapterDefaults } from '@/lib/frameworks'
import {
  buildSiteUpdateParams,
  useSiteFrameworks,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

const codeClassName =
  'rounded bg-muted/80 px-1.5 py-0.5 font-mono text-[12px] text-foreground/90'

function AdapterOptionDescription({
  desc,
  code,
}: {
  desc: string
  code: string[]
}) {
  const segments = getAdapterDescriptionSegments(desc, code)
  return (
    <p className="mt-2 text-[13px] text-muted-foreground leading-relaxed">
      {segments.map((seg, i) =>
        seg.type === 'text' ? (
          <span key={i}>{seg.value}</span>
        ) : (
          <code key={i} className={codeClassName}>
            {seg.value}
          </code>
        ),
      )}
    </p>
  )
}

function AdapterOptionCard({
  id,
  value,
  label,
  desc,
  code,
  url,
  isSelected,
}: {
  id: string
  value: 'ssr' | 'static'
  label: string
  desc: string
  code: string[]
  url?: string
  isSelected: boolean
}) {
  const t = useT()
  return (
    <Label
      htmlFor={id}
      className={cn(
        'relative flex cursor-pointer items-start rounded-xl border transition-colors',
        'px-5 py-4 sm:px-5 sm:py-5',
        isSelected
          ? 'border-primary bg-primary/5 shadow-sm'
          : 'border-border bg-card/50 hover:border-border hover:bg-muted/20',
      )}
    >
      <RadioGroupItem value={value} id={id} className="mt-1 shrink-0" />
      <div className="ms-4 flex-1 min-w-0 pe-2">
        <span className="block text-[15px] font-semibold tracking-tight text-foreground">
          {t(label)}
        </span>
        <AdapterOptionDescription desc={desc} code={code} />
        {url && (
          <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className="mt-3 inline-block text-[13px] link-neutral"
            onClick={(e) => e.stopPropagation()}
          >
            {t('Learn more')}
          </a>
        )}
      </div>
    </Label>
  )
}

function AdapterOptions({
  frameworkKey,
  adapter,
  onAdapterChange,
}: {
  frameworkKey: string
  adapter: string
  onAdapterChange: (value: 'ssr' | 'static') => void
}) {
  const t = useT()
  const ssrCopy = getAdapterCopy(frameworkKey, 'ssr')
  const staticCopy = getAdapterCopy(frameworkKey, 'static')
  return (
    <div>
      <Label className="text-[13px] font-medium text-foreground">
        {t('Adapter')}
      </Label>
      <p className="mt-1 text-[13px] text-muted-foreground">
        {t('Choose how your site is rendered at runtime.')}
      </p>
      <RadioGroup
        value={adapter}
        onValueChange={(v) => onAdapterChange(v as 'ssr' | 'static')}
        className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-5"
      >
        <AdapterOptionCard
          id="adapter-ssr"
          value="ssr"
          label={ssrCopy.label}
          desc={ssrCopy.desc}
          code={ssrCopy.code}
          url={ssrCopy.url}
          isSelected={adapter === 'ssr'}
        />
        <AdapterOptionCard
          id="adapter-static"
          value="static"
          label={staticCopy.label}
          desc={staticCopy.desc}
          code={staticCopy.code}
          url={staticCopy.url}
          isSelected={adapter === 'static'}
        />
      </RadioGroup>
    </div>
  )
}

interface SiteBuildFrameworkCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function SiteBuildFrameworkCard({
  projectId,
  siteId,
  site,
}: SiteBuildFrameworkCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { data: frameworksData } = useSiteFrameworks(projectId)

  const frameworks = useMemo(
    () => frameworksData?.frameworks || [],
    [frameworksData],
  )

  const [framework, setFramework] = useState('')
  const [adapter, setAdapter] = useState('')
  const [outputDirectory, setOutputDirectory] = useState('')
  const [fallbackFile, setFallbackFile] = useState('')

  const currentFramework = useMemo(
    () => frameworks.find((f) => f.key === framework),
    [frameworks, framework],
  )

  const adapterDefaults = useMemo(
    () => getFrameworkAdapterDefaults(currentFramework, adapter),
    [currentFramework, adapter],
  )

  useEffect(() => {
    if (site) {
      setFramework(site.framework || '')
      setAdapter(site.adapter || '')
      setOutputDirectory(site.outputDirectory ?? '')
      setFallbackFile(site.fallbackFile || '')
    }
  }, [site])

  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: (updated) => {
      toast.success(t('Framework settings updated successfully'))
      queryClient.setQueryData(['site', 'project', projectId, siteId], updated)
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(
        getErrorMessage(error, t('Failed to update framework settings')),
      )
    },
  })

  const handleSave = () => {
    if (!framework) {
      toast.error(t('Framework is required'))
      return
    }
    updateSiteMutation.mutate({
      framework,
      adapter: adapter || undefined,
      outputDirectory: outputDirectory || undefined,
      fallbackFile: fallbackFile || undefined,
      ...(framework !== site?.framework || adapter !== site?.adapter
        ? { startCommand: undefined }
        : {}),
    })
  }

  const hasChanges =
    framework !== site?.framework ||
    adapter !== site?.adapter ||
    outputDirectory !== (site?.outputDirectory ?? '') ||
    fallbackFile !== (site?.fallbackFile ?? '')

  const isStaticAdapter = adapter === 'static'

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Framework')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Choose your stack, adapter mode, and where build output is written.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-6">
        <div>
          <Label htmlFor="framework" className="text-[13px]">
            {t('Framework')}
          </Label>
          <Select value={framework} onValueChange={setFramework}>
            <SelectTrigger
              id="framework"
              className="mt-2 h-9 border-border bg-background text-[13px]"
            >
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
        </div>

        {framework ? (
          <AdapterOptions
            frameworkKey={framework}
            adapter={adapter}
            onAdapterChange={setAdapter}
          />
        ) : null}

        <div>
          <Label htmlFor="output-directory" className="text-[13px]">
            {t('Output directory')}
          </Label>
          <Input
            id="output-directory"
            value={outputDirectory}
            onChange={(e) => setOutputDirectory(e.target.value)}
            placeholder={
              adapterDefaults.outputDirectory || t('Enter output directory')
            }
            className="mt-2 h-9 font-mono text-[13px]"
          />
        </div>

        {isStaticAdapter ? (
          <div>
            <Label htmlFor="fallback-file" className="text-[13px]">
              {t('Fallback file')}
            </Label>
            <Input
              id="fallback-file"
              value={fallbackFile}
              onChange={(e) => setFallbackFile(e.target.value)}
              placeholder="index.html"
              className="mt-2 h-9 font-mono text-[13px]"
            />
            <p className="mt-1 text-[12px] text-muted-foreground">
              {t("File to serve for routes that don't match any static files")}
            </p>
          </div>
        ) : null}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || !framework || updateSiteMutation.isPending}
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

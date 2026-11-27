import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import type {
  DistributionArtifact,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import {
  artifactVersion,
  targetTrack,
  type SourceKind,
  type TargetSource,
} from '@/lib/distribution/publish'
import { useT } from '@/lib/i18n/translate'
import {
  PLATFORM_PROVIDERS,
  PlatformIcon,
  platformLabel,
} from '../../_components/platform'
import { TRACKS, trackLabel } from '../../_components/target-options'

export type TargetArtifacts = {
  artifacts: DistributionArtifact[]
  isLoading: boolean
}

type PublishSourcesProps = {
  branch: string
  buildReason: string | null
  targets: DistributionTarget[]
  sources: Record<string, TargetSource>
  artifacts: Record<string, TargetArtifacts>
  onChange: (targetId: string, changes: Partial<TargetSource>) => void
}

export function PublishSources({
  branch,
  buildReason,
  targets,
  sources,
  artifacts,
  onChange,
}: PublishSourcesProps) {
  const t = useT()

  return (
    <div className="space-y-3">
      {targets.map((target) => {
        const source = sources[target.$id]
        const { artifacts: ready, isLoading } = artifacts[target.$id] ?? {
          artifacts: [],
          isLoading: false,
        }
        const id = `publish-source-${target.platform}`
        const provider = PLATFORM_PROVIDERS[target.platform]
        const tracks = TRACKS[provider]
        const manualVersion = target.versionStrategy === 'manual'
        const buildLabel =
          buildReason === null
            ? t('Build from {branch}').replace('{branch}', branch)
            : t('Build from Git')
        const buildOption = (
          <div className="flex w-fit items-center gap-2">
            <RadioGroupItem
              id={`${id}-build`}
              value="build"
              className="peer"
              disabled={buildReason !== null}
            />
            <Label
              htmlFor={`${id}-build`}
              className="cursor-pointer text-[13px] font-normal peer-disabled:cursor-not-allowed peer-disabled:opacity-50"
            >
              {buildLabel}
            </Label>
          </div>
        )
        return (
          <div
            key={target.$id}
            data-testid={id}
            className="overflow-hidden rounded-lg border border-border"
          >
            <div className="flex items-center gap-2 border-b border-border bg-muted/30 px-4 py-2.5">
              <PlatformIcon platform={target.platform} />
              <span className="text-[13px] font-medium">
                {platformLabel(target.platform)}
              </span>
              <span
                dir="ltr"
                className="truncate font-mono text-[12px] text-muted-foreground"
              >
                {target.identifier}
              </span>
            </div>
            <div className="space-y-3 px-4 py-3">
              <RadioGroup
                value={source.kind}
                onValueChange={(kind) =>
                  onChange(target.$id, { kind: kind as SourceKind })
                }
                className="gap-2"
              >
                {buildReason === null ? (
                  <div className="space-y-1">
                    {buildOption}
                    <p className="ps-6 text-[12px] text-muted-foreground">
                      {t(
                        'Appwrite builds the app for this release. Submit the build from the release page when it is ready.',
                      )}
                    </p>
                  </div>
                ) : (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <span className="inline-flex w-fit">{buildOption}</span>
                    </TooltipTrigger>
                    <TooltipContent>{t(buildReason)}</TooltipContent>
                  </Tooltip>
                )}
                <div className="flex items-center gap-2">
                  <RadioGroupItem id={`${id}-artifact`} value="artifact" />
                  <Label
                    htmlFor={`${id}-artifact`}
                    className="cursor-pointer text-[13px] font-normal"
                  >
                    {t('Use an artifact')}
                  </Label>
                </div>
              </RadioGroup>
              {source.kind === 'build' ? (
                manualVersion ? (
                  <div className="space-y-2 ps-6">
                    <Label
                      htmlFor={`${id}-version-code`}
                      className="text-[13px]"
                    >
                      {t('Version code')}
                    </Label>
                    <Input
                      id={`${id}-version-code`}
                      dir="ltr"
                      inputMode="numeric"
                      value={source.versionCode}
                      onChange={(event) =>
                        onChange(target.$id, {
                          versionCode: event.target.value,
                        })
                      }
                      placeholder={String(target.versionCode + 1)}
                      autoComplete="off"
                      className="h-9 w-full font-mono text-[13px] sm:w-48"
                    />
                    <p className="text-[12px] text-muted-foreground">
                      {t(
                        'This target sets version codes manually. Use a number higher than the last upload.',
                      )}
                    </p>
                  </div>
                ) : null
              ) : (
                <div className="space-y-3 ps-6">
                  {!isLoading && ready.length === 0 ? (
                    <p className="text-[12px] text-muted-foreground">
                      {t(
                        'No ready artifacts for this target. Upload one from the Artifacts tab.',
                      )}
                    </p>
                  ) : (
                    <Select
                      value={source.artifactId}
                      onValueChange={(artifactId) =>
                        onChange(target.$id, { artifactId })
                      }
                      disabled={isLoading}
                    >
                      <SelectTrigger
                        id={`${id}-artifact-select`}
                        aria-label={t('Artifact')}
                        className="h-9 w-full text-[13px]"
                      >
                        <SelectValue placeholder={t('Choose an artifact')} />
                      </SelectTrigger>
                      <SelectContent>
                        {ready.map((artifact) => (
                          <SelectItem
                            key={artifact.$id}
                            value={artifact.$id}
                            className="text-[13px]"
                          >
                            <span className="truncate">{artifact.name}</span>
                            <span
                              dir="ltr"
                              className="font-mono text-[12px] text-muted-foreground"
                            >
                              {artifactVersion(artifact)}
                            </span>
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                  <div className="space-y-2">
                    <Label htmlFor={`${id}-track`} className="text-[13px]">
                      {t('Track')}
                    </Label>
                    <Select
                      value={targetTrack(source.track, tracks)}
                      onValueChange={(track) => onChange(target.$id, { track })}
                    >
                      <SelectTrigger
                        id={`${id}-track`}
                        className="h-9 w-full text-[13px] sm:w-60"
                      >
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {tracks.map((option) => (
                          <SelectItem
                            key={option}
                            value={option}
                            className="text-[13px]"
                          >
                            {t(trackLabel(option))}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  {provider === 'apple' ? (
                    <div className="flex items-center justify-between gap-3">
                      <Label
                        htmlFor={`${id}-automatic`}
                        className="text-[13px] font-normal"
                      >
                        {t('Release automatically after approval')}
                      </Label>
                      <Switch
                        id={`${id}-automatic`}
                        checked={source.automatic}
                        onCheckedChange={(automatic) =>
                          onChange(target.$id, { automatic })
                        }
                      />
                    </div>
                  ) : null}
                </div>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}

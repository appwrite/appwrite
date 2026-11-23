import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type {
  DistributionFramework,
  DistributionPlatform,
} from '@/lib/appwrite/distribution'
import { useT } from '@/lib/i18n/translate'
import {
  identifierHint,
  identifierLabel,
  isValidIdentifier,
} from '@/lib/distribution/identifiers'
import { cn } from '@/lib/utils'
import {
  PLATFORMS,
  PLATFORM_PROVIDERS,
  PlatformIcon,
  frameworkPlatforms,
  platformLabel,
  providerLabel,
} from '../_components/platform'
import { StepSection } from './StepSection'

export function PlatformsStep({
  framework,
  platforms,
  identifiers,
  onPlatformToggle,
  onIdentifierChange,
  disabled,
}: {
  framework: DistributionFramework
  platforms: DistributionPlatform[]
  identifiers: Record<DistributionPlatform, string>
  onPlatformToggle: (platform: DistributionPlatform, selected: boolean) => void
  onIdentifierChange: (
    platform: DistributionPlatform,
    identifier: string,
  ) => void
  disabled: boolean
}) {
  const t = useT()
  const supported = frameworkPlatforms(framework)

  return (
    <StepSection
      title={t('Choose platforms')}
      description={t(
        'Each platform becomes a target with its own store identifier. Connections and signing are set on the target later.',
      )}
    >
      <div className="space-y-3">
        {PLATFORMS.map((platform) => {
          const isSupported = supported.includes(platform)
          const selected = isSupported && platforms.includes(platform)
          const identifier = identifiers[platform]
          const trimmed = identifier.trim()
          const invalid =
            trimmed.length > 0 && !isValidIdentifier(platform, trimmed)
          const checkboxId = `distribution-platform-${platform}`
          const inputId = `distribution-identifier-${platform}`

          return (
            <div
              key={platform}
              className={cn(
                'rounded-xl border border-border bg-card/50 transition-all',
                selected && 'border-primary ring-1 ring-primary/20',
                !isSupported && 'opacity-60',
              )}
            >
              <label
                htmlFor={checkboxId}
                className={cn(
                  'flex items-center gap-3 p-3.5',
                  isSupported && !disabled
                    ? 'cursor-pointer'
                    : 'cursor-not-allowed',
                )}
              >
                <Checkbox
                  id={checkboxId}
                  checked={selected}
                  disabled={!isSupported || disabled}
                  onCheckedChange={(checked) =>
                    onPlatformToggle(platform, checked === true)
                  }
                />
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <PlatformIcon platform={platform} />
                </div>
                <div className="min-w-0">
                  <p className="text-[13px] font-medium text-foreground">
                    {platformLabel(platform)}
                  </p>
                  <p className="mt-0.5 text-[12px] text-muted-foreground">
                    {isSupported
                      ? providerLabel(PLATFORM_PROVIDERS[platform])
                      : t('Not available for this framework')}
                  </p>
                </div>
              </label>
              {selected && (
                <div className="space-y-2 border-t border-border px-3.5 py-3.5">
                  <Label htmlFor={inputId}>
                    {t(identifierLabel(platform))}{' '}
                    <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id={inputId}
                    dir="ltr"
                    value={identifier}
                    onChange={(event) =>
                      onIdentifierChange(platform, event.target.value)
                    }
                    placeholder="com.example.app"
                    spellCheck={false}
                    autoCapitalize="off"
                    autoCorrect="off"
                    autoComplete="off"
                    disabled={disabled}
                    aria-invalid={invalid}
                    className={cn('font-mono', invalid && 'border-destructive')}
                  />
                  <p
                    className={cn(
                      'text-[12px]',
                      invalid ? 'text-destructive' : 'text-muted-foreground',
                    )}
                  >
                    {t(identifierHint(platform))}
                  </p>
                </div>
              )}
            </div>
          )
        })}
      </div>
    </StepSection>
  )
}

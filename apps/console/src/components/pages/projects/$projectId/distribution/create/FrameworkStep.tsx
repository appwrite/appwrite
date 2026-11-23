import type { DistributionFramework } from '@/lib/appwrite/distribution'
import { useT } from '@/lib/i18n/translate'
import { AppFrameworkMark } from '../_components/AppFrameworkIcon'
import { FRAMEWORKS, frameworkLabel } from '../_components/platform'
import { ChoiceCard } from './ChoiceCard'
import { frameworkDescription } from './frameworks'
import { StepSection } from './StepSection'

export function FrameworkStep({
  framework,
  onFrameworkChange,
  disabled,
}: {
  framework: DistributionFramework
  onFrameworkChange: (framework: DistributionFramework) => void
  disabled: boolean
}) {
  const t = useT()
  return (
    <StepSection
      title={t('Choose a framework')}
      description={t(
        'Builds use the toolchain of this framework. Install and build commands can be changed per target later.',
      )}
    >
      <div
        role="radiogroup"
        aria-label={t('Framework')}
        className="grid grid-cols-1 gap-3 sm:grid-cols-2"
      >
        {FRAMEWORKS.map((option) => (
          <ChoiceCard
            key={option}
            selected={framework === option}
            icon={<AppFrameworkMark framework={option} />}
            title={frameworkLabel(option)}
            description={t(frameworkDescription(option))}
            disabled={disabled}
            onSelect={() => onFrameworkChange(option)}
          />
        ))}
      </div>
    </StepSection>
  )
}

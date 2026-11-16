import { useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { ReviewStrength } from '@/lib/appwrite/reviews'
import {
  projectQueryOptions,
  useUpdateReviewStrength,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

/**
 * Each strength, with the credits a review costs on Cloud. Keep the credits in
 * step with Cloud's billing; self-hosted has no credits.
 */
const STRENGTHS: {
  value: ReviewStrength
  label: string
  description: string
  credits: number
}[] = [
  {
    value: 'basic',
    label: 'Basic',
    description: 'Cheaper models that read the code without running it',
    credits: 2,
  },
  {
    value: 'normal',
    label: 'Normal',
    description: 'The default models, running commands in a sandbox',
    credits: 10,
  },
  {
    value: 'expert',
    label: 'Expert',
    description: 'Stronger models that split the work across subagents',
    credits: 150,
  },
]

type StrengthSelectProps = {
  projectId: string
  disabled?: boolean
}

/**
 * Picks how hard the pull requests of every repository in the project are
 * reviewed: one setting for all of them. Reviews already queued keep the
 * strength they were queued at.
 */
export function StrengthSelect({ projectId, disabled }: StrengthSelectProps) {
  const t = useT()
  const { isCloud } = useConsoleProfile()
  // The Console SDK's Project model does not carry the strength yet.
  const { data: project } = useQuery(projectQueryOptions(projectId))
  const mutation = useUpdateReviewStrength(projectId)
  const value: ReviewStrength = mutation.isPending
    ? mutation.variables.strength
    : ((project as { reviewStrength?: ReviewStrength } | undefined)
        ?.reviewStrength ?? 'normal')

  // A strength this bundle doesn't know yet shows as its value.
  const current = STRENGTHS.find((strength) => strength.value === value)

  return (
    <Select
      value={value}
      disabled={disabled || mutation.isPending}
      onValueChange={(next) => {
        mutation.mutate(
          { strength: next as ReviewStrength },
          {
            onSuccess: () => {
              toast.success(t('Review strength updated'))
            },
            onError: (error) => {
              toast.error(
                getErrorMessage(error, t('Failed to update review strength')),
              )
            },
          },
        )
      }}
    >
      <SelectTrigger
        size="sm"
        className="h-9 border-border bg-background text-[13px] text-foreground focus:ring-0"
        aria-label={t('Review strength')}
      >
        {/* The items carry a description; the trigger shows the name only. */}
        <SelectValue>
          {t('Strength')}: {current ? t(current.label) : value}
        </SelectValue>
      </SelectTrigger>
      <SelectContent align="end">
        {STRENGTHS.map((strength) => (
          <SelectItem
            key={strength.value}
            value={strength.value}
            className="text-[13px]"
          >
            <div className="flex flex-col items-start">
              <span>{t(strength.label)}</span>
              <span className="text-[12px] text-muted-foreground">
                {t(strength.description)}
                {isCloud &&
                  ` · ${t('{credits} credits per review').replace('{credits}', String(strength.credits))}`}
              </span>
            </div>
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

import { useEffect, useState } from 'react'
import { CheckCircle2 } from 'lucide-react'
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
import { Textarea } from '@/components/ui/textarea'
import {
  analyticsAttrs,
  type AnalyticsActionId,
} from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type ApplicationFormSelectOption = {
  value: string
  label: string
}

export type ApplicationFormField = {
  name: string
  label: string
  type: 'text' | 'email' | 'url' | 'textarea' | 'select'
  placeholder: string
  required?: boolean
  colSpan?: 1 | 2
  options?: ApplicationFormSelectOption[]
}

type MarketingApplicationFormProps = {
  fields: ApplicationFormField[]
  submitLabel: string
  onSubmit: (values: Record<string, string>) => Promise<void>
  className?: string
  successTitle?: string
  successDescription?: string
  defaultValues?: Record<string, string>
  submitAnalyticsAction?: AnalyticsActionId
}

export function MarketingApplicationForm({
  fields,
  submitLabel,
  onSubmit,
  className,
  successTitle = 'Thank you for your submission',
  successDescription = 'Our team will review your application and get back to you soon.',
  defaultValues,
  submitAnalyticsAction,
}: MarketingApplicationFormProps) {
  const t = useT()
  const [values, setValues] = useState<Record<string, string>>(() => defaultValues ?? {})
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [submitted, setSubmitted] = useState(false)

  useEffect(() => {
    if (!defaultValues) return

    setValues((current) => {
      const next = { ...current }
      for (const [key, value] of Object.entries(defaultValues)) {
        if (!next[key]?.trim()) {
          next[key] = value
        }
      }
      return next
    })
  }, [defaultValues])

  const handleChange = (name: string, value: string) => {
    setValues((current) => ({ ...current, [name]: value }))
  }

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await onSubmit(values)
      setSubmitted(true)
    } catch (submitError) {
      setError(
        submitError instanceof Error
          ? submitError.message
          : t('Error submitting form. Please contact support.'),
      )
    } finally {
      setSubmitting(false)
    }
  }

  const resetForm = () => {
    setValues(defaultValues ?? {})
    setSubmitted(false)
    setError(null)
  }

  if (submitted) {
    return (
      <div className={cn('mx-auto max-w-md text-center', className)}>
        <div className="flex items-center justify-center gap-2 text-[14px] font-medium text-foreground">
          <CheckCircle2 className="h-4 w-4 text-green-600" />
          {t(successTitle)}
        </div>
        <p className="mt-3 text-[13px] leading-6 text-muted-foreground">{t(successDescription)}</p>
        <Button variant="outline" className="mt-6" onClick={resetForm}>
          {t('Back to form')}
        </Button>
      </div>
    )
  }

  return (
    <form onSubmit={handleSubmit} className={cn('flex flex-col gap-4', className)}>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {fields.map((field) => (
          <div
            key={field.name}
            className={cn('space-y-2', field.colSpan === 2 && 'sm:col-span-2')}
          >
            <Label htmlFor={field.name} className="text-[13px]">
              {t(field.label)}
            </Label>
            {field.type === 'textarea' ? (
              <Textarea
                id={field.name}
                name={field.name}
                placeholder={t(field.placeholder)}
                required={field.required ?? true}
                value={values[field.name] ?? ''}
                onChange={(event) => handleChange(field.name, event.target.value)}
                className="min-h-28"
              />
            ) : field.type === 'select' ? (
              <Select
                value={values[field.name] ?? ''}
                onValueChange={(value) => handleChange(field.name, value)}
              >
                <SelectTrigger id={field.name} className="w-full">
                  <SelectValue placeholder={t(field.placeholder)} />
                </SelectTrigger>
                <SelectContent>
                  {field.options?.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {t(option.label)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            ) : (
              <Input
                id={field.name}
                name={field.name}
                type={field.type}
                placeholder={t(field.placeholder)}
                required={field.required ?? true}
                value={values[field.name] ?? ''}
                onChange={(event) => handleChange(field.name, event.target.value)}
              />
            )}
          </div>
        ))}
      </div>

      {error ? <p className="text-[13px] text-destructive">{error}</p> : null}

      <div className="flex flex-col-reverse gap-4 sm:flex-row sm:items-end sm:justify-between">
        <p className="text-[12px] leading-5 text-muted-foreground">
          {t('This form is protected by reCAPTCHA, and the Google')}{' '}
          <a
            href="https://policies.google.com/privacy"
            className="text-foreground underline underline-offset-2"
            target="_blank"
            rel="noopener noreferrer"
          >
            {t('Privacy Policy')}
          </a>{' '}
          {t('and')}{' '}
          <a
            href="https://policies.google.com/terms"
            className="text-foreground underline underline-offset-2"
            target="_blank"
            rel="noopener noreferrer"
          >
            {t('Terms of Service')}
          </a>{' '}
          {t('apply.')}
        </p>
        <Button
          type="submit"
          variant="brandCta"
          disabled={submitting}
          className="shrink-0"
          {...(submitAnalyticsAction
            ? analyticsAttrs(submitAnalyticsAction)
            : {})}
        >
          {t(submitLabel)}
        </Button>
      </div>
    </form>
  )
}

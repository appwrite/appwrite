import {
  useId,
  useState,
  type ChangeEvent,
  type FormEvent,
  type ReactNode,
} from 'react'
import { translateText, useT } from '@/lib/i18n/translate'
import type { SupportedLanguage } from '@/lib/i18n/active-language'
import { resolveEffectivePageDirection } from '@/lib/layout/page-direction'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'

export type PromptDialogField = {
  /** Key of this field's value in the submitted record. */
  name: string
  label: string
  placeholder?: string
  defaultValue?: string
  /** Render a textarea instead of a single-line input. */
  multiline?: boolean
  /** Block submit while the trimmed value is empty. Defaults to true. */
  required?: boolean
}

type PromptDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description?: ReactNode
  fields: PromptDialogField[]
  confirmLabel?: string
  onSubmit: (values: Record<string, string>) => void
  isSubmitting?: boolean
  /** Extra classes for the dialog panel (e.g. a higher z-index inside popovers). */
  contentClassName?: string
  /** Extra classes for the backdrop. */
  overlayClassName?: string
  language?: SupportedLanguage
  onCloseAutoFocus?: (event: Event) => void
}

/**
 * Replacement for `window.prompt()`: a dialog with one or more text fields.
 * Field values start from their defaults every time the dialog opens.
 */
export function PromptDialog({
  open,
  onOpenChange,
  title,
  description,
  fields,
  confirmLabel = 'Save',
  onSubmit,
  isSubmitting = false,
  contentClassName,
  overlayClassName,
  language,
  onCloseAutoFocus,
}: PromptDialogProps) {
  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        if (!isSubmitting) onOpenChange(nextOpen)
      }}
    >
      <DialogContent
        className={cn('sm:max-w-md p-0', contentClassName)}
        overlayClassName={overlayClassName}
        {...(language
          ? { lang: language, dir: resolveEffectivePageDirection(language) }
          : {})}
        onCloseAutoFocus={onCloseAutoFocus}
        {...(description ? {} : { 'aria-describedby': undefined })}
      >
        {/* Mounted per open, so field state resets without effects. */}
        <PromptDialogForm
          title={title}
          description={description}
          fields={fields}
          confirmLabel={confirmLabel}
          onSubmit={onSubmit}
          isSubmitting={isSubmitting}
          language={language}
          onCancel={() => onOpenChange(false)}
        />
      </DialogContent>
    </Dialog>
  )
}

type PromptDialogFormProps = Required<
  Pick<PromptDialogProps, 'title' | 'fields' | 'confirmLabel' | 'onSubmit'>
> &
  Pick<PromptDialogProps, 'description' | 'isSubmitting' | 'language'> & {
    onCancel: () => void
  }

function PromptDialogForm({
  title,
  description,
  fields,
  confirmLabel,
  onSubmit,
  isSubmitting,
  language,
  onCancel,
}: PromptDialogFormProps) {
  const defaultT = useT()
  const t = language
    ? (text: string) => translateText(text, language)
    : defaultT
  const idPrefix = useId()
  const [values, setValues] = useState<Record<string, string>>(() =>
    Object.fromEntries(
      fields.map((field) => [field.name, field.defaultValue ?? '']),
    ),
  )

  const canSubmit =
    !isSubmitting &&
    fields.every(
      (field) =>
        field.required === false || (values[field.name] ?? '').trim() !== '',
    )

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!canSubmit) return
    onSubmit(values)
  }

  return (
    <form onSubmit={handleSubmit}>
      <DialogHeader className="px-6 pt-6 pb-4 text-start">
        <DialogTitle>{t(title)}</DialogTitle>
        {description ? (
          <DialogDescription className="text-[13px] mt-2">
            {description}
          </DialogDescription>
        ) : null}
      </DialogHeader>
      <div className="border-t border-border" />
      <div className="space-y-4 px-6 py-4">
        {fields.map((field, index) => {
          const id = `${idPrefix}-${field.name}`
          const fieldProps = {
            id,
            value: values[field.name] ?? '',
            placeholder: field.placeholder ? t(field.placeholder) : undefined,
            autoFocus: index === 0,
            disabled: isSubmitting,
            onChange: (
              event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>,
            ) =>
              setValues((prev) => ({
                ...prev,
                [field.name]: event.target.value,
              })),
          }
          return (
            <div key={field.name} className="space-y-2">
              <Label htmlFor={id} className="text-[13px]">
                {t(field.label)}
              </Label>
              {field.multiline ? (
                <Textarea
                  {...fieldProps}
                  rows={4}
                  className="font-mono text-[13px]"
                />
              ) : (
                <Input {...fieldProps} className="h-9 text-[13px]" />
              )}
            </div>
          )
        })}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button
          type="button"
          variant="outline"
          className="h-9 text-[13px]"
          disabled={isSubmitting}
          onClick={onCancel}
        >
          {t('Cancel')}
        </Button>
        <Button type="submit" className="h-9 text-[13px]" disabled={!canSubmit}>
          {t(confirmLabel)}
        </Button>
      </div>
    </form>
  )
}

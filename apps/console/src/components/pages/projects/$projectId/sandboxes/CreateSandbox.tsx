import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { IdInput } from '@/components/ui/id-input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import type { SandboxImage } from '@/lib/appwrite/sandboxes'
import { useT } from '@/lib/i18n/translate'

/** Images Cloud offers (`app/config/sandboxes.php`); names are product names, not copy. */
export const SANDBOX_IMAGES: { id: SandboxImage; name: string }[] = [
  { id: 'node-22', name: 'Node.js 22' },
  { id: 'python-3.12', name: 'Python 3.12' },
  { id: 'ubuntu-24.04', name: 'Ubuntu 24.04' },
]

/** Sizes Cloud offers, as the API names them. */
const SANDBOX_SPECIFICATIONS = [
  { id: 's-0.5vcpu-512mb', label: '0.5 vCPU, 512 MB' },
  { id: 's-1vcpu-1gb', label: '1 vCPU, 1 GB' },
]

/** Cloud refuses more, and names it reserves (`Create.php`). */
const VARIABLES_MAX = 100

export type CreateSandboxData = {
  sandboxId?: string
  image: SandboxImage
  specification: string
  variables: Record<string, string>
}

/**
 * Reads `KEY=value` lines. Returns the variables, or the 1-based line that is
 * not one.
 */
function parseVariables(
  text: string,
): { variables: Record<string, string> } | { line: number } {
  const variables: Record<string, string> = {}
  const lines = text.split('\n')
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i].trim()
    if (line === '' || line.startsWith('#')) continue
    const separator = line.indexOf('=')
    const name = separator > 0 ? line.slice(0, separator).trim() : ''
    const valid =
      name !== '' &&
      [...name].every((c) => c === '_' || /[A-Za-z0-9]/.test(c)) &&
      !/[0-9]/.test(name[0]) &&
      !name.toUpperCase().startsWith('SANDBOX_')
    if (!valid) return { line: i + 1 }
    variables[name] = line.slice(separator + 1)
  }
  return { variables }
}

interface CreateSandboxProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (data: CreateSandboxData) => void
  isLoading?: boolean
}

export function CreateSandbox({
  open,
  onOpenChange,
  onCreate,
  isLoading = false,
}: CreateSandboxProps) {
  const t = useT()
  const [sandboxId, setSandboxId] = useState<string | undefined>(undefined)
  const [image, setImage] = useState<SandboxImage>('node-22')
  const [specification, setSpecification] = useState(
    SANDBOX_SPECIFICATIONS[0].id,
  )
  const [variablesText, setVariablesText] = useState('')
  const [error, setError] = useState('')

  const resetForm = () => {
    setSandboxId(undefined)
    setImage('node-22')
    setSpecification(SANDBOX_SPECIFICATIONS[0].id)
    setVariablesText('')
    setError('')
  }

  useEffect(() => {
    if (!open) resetForm()
  }, [open])

  const handleOpenChange = (next: boolean) => {
    if (isLoading) return
    onOpenChange(next)
  }

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    const parsed = parseVariables(variablesText)
    if ('line' in parsed) {
      setError(
        `${t('Line')} ${parsed.line}: ${t('Use NAME=value. Names use letters, digits and underscores, cannot start with a digit or SANDBOX_.')}`,
      )
      return
    }
    if (Object.keys(parsed.variables).length > VARIABLES_MAX) {
      setError(t('A sandbox takes at most 100 variables.'))
      return
    }
    onCreate({ sandboxId, image, specification, variables: parsed.variables })
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Create sandbox')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'A live workspace you can open a terminal in, run commands in and manage files in. It is deleted after it has been idle for 15 minutes.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 py-4 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="sandbox-image">{t('Image')}</Label>
              <Select
                value={image}
                onValueChange={(value) => setImage(value as SandboxImage)}
                disabled={isLoading}
              >
                <SelectTrigger id="sandbox-image" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {SANDBOX_IMAGES.map((option) => (
                    <SelectItem key={option.id} value={option.id}>
                      {option.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="sandbox-specification">{t('Size')}</Label>
              <Select
                value={specification}
                onValueChange={setSpecification}
                disabled={isLoading}
              >
                <SelectTrigger id="sandbox-specification" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {SANDBOX_SPECIFICATIONS.map((option) => (
                    <SelectItem key={option.id} value={option.id}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="sandbox-variables">
                {t('Environment variables')}
              </Label>
              <Textarea
                id="sandbox-variables"
                value={variablesText}
                onChange={(event) => {
                  setVariablesText(event.target.value)
                  if (error) setError('')
                }}
                placeholder={'API_URL=https://example.com\nDEBUG=true'}
                rows={4}
                className="font-mono text-[12px]"
                disabled={isLoading}
              />
              <p className="text-[12px] text-muted-foreground">
                {t(
                  'One NAME=value per line. Every command, process and terminal in the sandbox inherits them. They are never stored or shown again.',
                )}
              </p>
              {error && <p className="text-[12px] text-destructive">{error}</p>}
            </div>

            <div className="space-y-2">
              <Label htmlFor="sandbox-id">{t('Sandbox ID')}</Label>
              <IdInput
                id="sandbox-id"
                value={sandboxId}
                onChange={setSandboxId}
                maxLength={36}
                disabled={isLoading}
                placeholder={t('Leave blank to auto-generate')}
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isLoading}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={isLoading}>
              {t('Create')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}

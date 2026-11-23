import { useRef, useState } from 'react'
import type { ChangeEvent, DragEvent } from 'react'
import { FileKey, FileUp, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type CredentialFileValue = {
  name: string
  content: string
}

type Encoding = 'text' | 'base64'

type Source = 'upload' | 'paste'

interface CredentialFileProps {
  id: string
  label: string
  extensions: string[]
  encoding: Encoding
  files: CredentialFileValue[]
  onChange: (files: CredentialFileValue[]) => void
  hint?: string
  error?: string
  multiple?: boolean
  pastePlaceholder?: string
  disabled?: boolean
}

const BYTES_PER_CHUNK = 0x8000

async function encodeFile(file: File, encoding: Encoding): Promise<string> {
  if (encoding === 'text') {
    return (await file.text()).trim()
  }
  const bytes = new Uint8Array(await file.arrayBuffer())
  let binary = ''
  for (let offset = 0; offset < bytes.length; offset += BYTES_PER_CHUNK) {
    binary += String.fromCharCode(
      ...bytes.subarray(offset, offset + BYTES_PER_CHUNK),
    )
  }
  return btoa(binary)
}

function hasExtension(name: string, extensions: string[]): boolean {
  const lower = name.toLowerCase()
  return extensions.some((extension) => lower.endsWith(extension))
}

export function CredentialFile({
  id,
  label,
  extensions,
  encoding,
  files,
  onChange,
  hint,
  error,
  multiple = false,
  pastePlaceholder,
  disabled = false,
}: CredentialFileProps) {
  const t = useT()
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragging, setDragging] = useState(false)
  const [readError, setReadError] = useState('')
  const [source, setSource] = useState<Source>('upload')

  const uploaded = files.filter((file) => file.name !== '')
  const pasted = files.find((file) => file.name === '')?.content ?? ''
  const extensionList = extensions.join(', ')
  const shownError = readError || error

  const addFiles = async (selected: File[]) => {
    setReadError('')
    if (selected.some((file) => !hasExtension(file.name, extensions))) {
      setReadError(
        t('Choose a file of type {extensions}.').replace(
          '{extensions}',
          extensionList,
        ),
      )
      return
    }
    try {
      const encoded = await Promise.all(
        selected.map(async (file) => ({
          name: file.name,
          content: await encodeFile(file, encoding),
        })),
      )
      const others = uploaded.filter(
        (file) => !encoded.some((added) => added.name === file.name),
      )
      onChange(multiple ? [...others, ...encoded] : encoded.slice(0, 1))
    } catch {
      setReadError(t('The file could not be read. Try again.'))
    } finally {
      if (inputRef.current) inputRef.current.value = ''
    }
  }

  const handleInput = (event: ChangeEvent<HTMLInputElement>) => {
    const selected = Array.from(event.target.files ?? [])
    if (selected.length > 0) void addFiles(selected)
  }

  const handleDrop = (event: DragEvent<HTMLDivElement>) => {
    event.preventDefault()
    setDragging(false)
    if (disabled) return
    const dropped = Array.from(event.dataTransfer.files ?? [])
    if (dropped.length > 0) {
      void addFiles(multiple ? dropped : dropped.slice(0, 1))
    }
  }

  const removeFile = (name: string) => {
    setReadError('')
    onChange(uploaded.filter((file) => file.name !== name))
  }

  const upload = (
    <div className="space-y-2">
      <input
        ref={inputRef}
        id={`${id}-file`}
        type="file"
        accept={extensions.join(',')}
        multiple={multiple}
        className="sr-only"
        onChange={handleInput}
        disabled={disabled}
      />
      {uploaded.map((file) => (
        <div
          key={file.name}
          className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 px-3 py-2.5"
        >
          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border bg-background">
            <FileKey className="h-4 w-4 text-muted-foreground" />
          </div>
          <p className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
            {file.name}
          </p>
          {!disabled ? (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 w-8 shrink-0 p-0 text-muted-foreground hover:text-foreground"
              onClick={() => removeFile(file.name)}
              aria-label={`${t('Remove')} ${file.name}`}
            >
              <X className="h-4 w-4" />
            </Button>
          ) : null}
        </div>
      ))}
      {multiple || uploaded.length === 0 ? (
        <div
          onDragOver={(event) => {
            event.preventDefault()
            if (!disabled) setDragging(true)
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={handleDrop}
          className={cn(
            'rounded-lg border-2 border-dashed px-4 py-5 text-center transition-colors',
            dragging
              ? 'border-primary bg-primary/5'
              : 'border-border bg-muted/30',
            shownError && 'border-destructive',
            disabled && 'pointer-events-none opacity-60',
          )}
        >
          <FileUp className="mx-auto h-6 w-6 text-muted-foreground" />
          <p className="mt-2 text-[13px] font-medium text-foreground">
            {multiple ? t('Drop files here') : t('Drop a file here')}
          </p>
          <p className="mt-0.5 text-[12px] text-muted-foreground">
            {extensionList}
          </p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="mt-3 h-8 text-[13px]"
            onClick={() => inputRef.current?.click()}
            disabled={disabled}
          >
            {multiple && uploaded.length > 0
              ? t('Add another file')
              : t('Choose file')}
          </Button>
        </div>
      ) : null}
    </div>
  )

  return (
    <div className="space-y-2">
      <Label htmlFor={`${id}-file`} className="text-[12px] font-medium">
        {label}
      </Label>
      {pastePlaceholder ? (
        <Tabs
          value={source}
          onValueChange={(value) => {
            setSource(value as Source)
            setReadError('')
            onChange([])
          }}
          className="gap-3"
        >
          <TabsList className="grid h-9 w-full grid-cols-2">
            <TabsTrigger
              value="upload"
              className="text-[13px]"
              disabled={disabled}
            >
              {t('Upload file')}
            </TabsTrigger>
            <TabsTrigger
              value="paste"
              className="text-[13px]"
              disabled={disabled}
            >
              {t('Paste contents')}
            </TabsTrigger>
          </TabsList>
          <TabsContent value="upload" className="mt-0">
            {upload}
          </TabsContent>
          <TabsContent value="paste" className="mt-0">
            <Textarea
              id={id}
              dir="ltr"
              aria-label={label}
              value={pasted}
              onChange={(event) =>
                onChange(
                  event.target.value
                    ? [{ name: '', content: event.target.value }]
                    : [],
                )
              }
              placeholder={pastePlaceholder}
              className={cn(
                'min-h-[120px] font-mono text-[12px]',
                shownError && 'border-destructive',
              )}
              aria-invalid={shownError ? true : undefined}
              autoComplete="off"
              spellCheck={false}
              disabled={disabled}
            />
          </TabsContent>
        </Tabs>
      ) : (
        upload
      )}
      {shownError ? (
        <p className="text-[12px] text-destructive">{shownError}</p>
      ) : hint ? (
        <p className="text-[12px] text-muted-foreground">{hint}</p>
      ) : null}
    </div>
  )
}

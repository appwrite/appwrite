'use client'

import { useMemo, useState } from 'react'
import { Download } from 'lucide-react'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  generateFiles,
  type ComposeDatabase,
  type ComposeTopology,
} from '@/lib/docs/compose-generator/generate'
import { cn } from '@/lib/utils'

type Option<T extends string> = {
  value: T
  label: string
  description: string
}

const DATABASE_OPTIONS: Option<ComposeDatabase>[] = [
  {
    value: 'postgresql',
    label: 'PostgreSQL',
    description: 'Relational SQL database (default)',
  },
  {
    value: 'mariadb',
    label: 'MariaDB',
    description: 'Relational SQL database',
  },
  { value: 'mongodb', label: 'MongoDB', description: 'Document database' },
]

const TOPOLOGY_OPTIONS: Option<ComposeTopology>[] = [
  {
    value: 'combined',
    label: 'Combined',
    description: 'One worker and one scheduler (recommended)',
  },
  {
    value: 'separate',
    label: 'Separate',
    description: 'One container per queue for scale-out',
  },
]

const ASSISTANT_OPTIONS: Option<'enabled' | 'disabled'>[] = [
  {
    value: 'enabled',
    label: 'Enabled',
    description: 'AI assistant powered by OpenAI',
  },
  { value: 'disabled', label: 'Disabled', description: 'No AI assistant' },
]

function OptionGroup<T extends string>({
  groupId,
  label,
  options,
  value,
  onChange,
}: {
  groupId: string
  label: string
  options: Option<T>[]
  value: T
  onChange: (value: T) => void
}) {
  return (
    <div className="flex flex-col gap-2">
      <span className="text-sm font-medium text-foreground">{label}</span>
      <RadioGroup
        value={value}
        onValueChange={(next) => onChange(next as T)}
        aria-label={label}
        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
      >
        {options.map((option) => {
          const id = `${groupId}-${option.value}`
          const isSelected = option.value === value
          return (
            <div
              key={option.value}
              className={cn(
                'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors',
                isSelected
                  ? 'border-primary bg-card'
                  : 'border-border bg-card/50 hover:border-primary/30',
              )}
              onClick={() => onChange(option.value)}
            >
              <RadioGroupItem
                value={option.value}
                id={id}
                className="mt-0.5 shrink-0 pointer-events-none"
              />
              <Label
                htmlFor={id}
                className="pointer-events-none flex min-w-0 flex-1 cursor-pointer flex-col items-start gap-0.5 text-left"
              >
                <span className="text-sm font-medium text-foreground">
                  {option.label}
                </span>
                <span className="text-xs text-muted-foreground">
                  {option.description}
                </span>
              </Label>
            </div>
          )
        })}
      </RadioGroup>
    </div>
  )
}

function downloadFile(filename: string, content: string) {
  const blob = new Blob([content], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  document.body.removeChild(anchor)
  URL.revokeObjectURL(url)
}

export function ComposeGenerator() {
  const [database, setDatabase] = useState<ComposeDatabase>('postgresql')
  const [topology, setTopology] = useState<ComposeTopology>('combined')
  const [assistant, setAssistant] = useState(true)
  const [activeFileId, setActiveFileId] = useState('docker-compose.yml')

  const files = useMemo(
    () => generateFiles({ database, topology, assistant }),
    [database, topology, assistant],
  )
  const activeFile =
    files.find((file) => file.filename === activeFileId) ?? files[0]

  return (
    <section className="not-prose my-6 flex flex-col gap-6">
      <OptionGroup
        groupId="compose-database"
        label="Database"
        options={DATABASE_OPTIONS}
        value={database}
        onChange={setDatabase}
      />
      <OptionGroup
        groupId="compose-topology"
        label="Workers and schedulers"
        options={TOPOLOGY_OPTIONS}
        value={topology}
        onChange={setTopology}
      />
      <OptionGroup
        groupId="compose-assistant"
        label="Appwrite Assistant"
        options={ASSISTANT_OPTIONS}
        value={assistant ? 'enabled' : 'disabled'}
        onChange={(value) => setAssistant(value === 'enabled')}
      />
      <ConnectCodeExample
        code={activeFile.content}
        language={activeFile.language}
        tabs={files.map((file) => ({
          id: file.filename,
          label: file.filename,
        }))}
        activeTabId={activeFile.filename}
        onTabChange={setActiveFileId}
        selectorAriaLabel="Configuration file"
        fixedHeight="24rem"
        actions={
          <Button
            variant="ghost"
            size="icon"
            aria-label={`Download ${activeFile.filename}`}
            onClick={() =>
              downloadFile(activeFile.filename, activeFile.content)
            }
          >
            <Download aria-hidden="true" />
          </Button>
        }
      />
    </section>
  )
}

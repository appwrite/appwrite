/**
 * Fullscreen create database wizard: single screen with progressive disclosure.
 * DB type → specifications (table) → name & create.
 */

import { useState, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Table as TableIcon, Braces, Layers } from 'lucide-react'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { createProjectDatabase } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useConsoleProfile } from '@/hooks/use-console-profile'

export type DatabaseTypeOption = 'TablesDB' | 'DocumentsDB' | 'VectorsDB'

type DbTypeChoice = {
  id: DatabaseTypeOption
  label: string
  description: string
  tags: string[]
  icon: 'table' | 'braces' | 'layers'
}

const DB_TYPE_OPTIONS: DbTypeChoice[] = [
  {
    id: 'TablesDB',
    label: 'Tables DB',
    description:
      'Relational-style database with tables, columns, and indexes. Ideal for structured data and complex queries.',
    tags: [
      'Relational data',
      'CRUD apps',
      'Structured schemas',
      'SQL-like queries',
    ],
    icon: 'table',
  },
  {
    id: 'DocumentsDB',
    label: 'Documents DB',
    description:
      'Document-based storage with flexible schemas. Store JSON documents and query with filters and full-text search.',
    tags: [
      'JSON documents',
      'Flexible schema',
      'Content apps',
      'Catalogs',
      'Logs',
    ],
    icon: 'braces',
  },
  {
    id: 'VectorsDB',
    label: 'Vectors DB',
    description:
      'Vector database for embeddings and similarity search. Power AI features like semantic search and recommendations.',
    tags: ['Embeddings', 'Semantic search', 'AI/ML', 'Recommendations'],
    icon: 'layers',
  },
]

import { TABLE_DB_SPEC_OPTIONS as SPEC_OPTIONS } from '@/lib/database-specs'

function validateDatabaseId(id: string): boolean {
  if (!id || id.length === 0) return true
  if (id.length > 36) return false
  if (!/^[a-zA-Z0-9_]/.test(id)) return false
  return /^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(id)
}

export function CreateDatabaseWizardView() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const pid = projectId as string
  const { features } = useConsoleProfile()

  const [dbType, setDbType] = useState<DatabaseTypeOption | null>(null)
  const [specId, setSpecId] = useState<string | null>(null)
  const [name, setName] = useState('')
  const [databaseId, setDatabaseId] = useState<string | undefined>(undefined)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const isTablesDB = dbType === 'TablesDB'

  /** Show specs section only when the selected DB type has dedicated support enabled. */
  const showSpecsForType =
    (dbType === 'TablesDB' && features.dedicatedDbsTablesDB) ||
    (dbType === 'DocumentsDB' && features.dedicatedDbsDocumentsDB) ||
    (dbType === 'VectorsDB' && features.dedicatedDbsVectorsDB)

  const selectableSpecs = useMemo(() => {
    if (isTablesDB) {
      return SPEC_OPTIONS.map((s) =>
        s.id === 'shared'
          ? { ...s, comingSoon: false }
          : { ...s, comingSoon: true },
      )
    }
    // DocumentsDB / VectorsDB: only dedicated tiers, no Shared DB
    return SPEC_OPTIONS.filter((s) => s.id !== 'shared').map((s) => ({
      ...s,
      comingSoon: false,
    }))
  }, [isTablesDB])

  const selectedSpec = useMemo(
    () => (specId ? selectableSpecs.find((s) => s.id === specId) : null),
    [specId, selectableSpecs],
  )

  const createMutation = useMutation({
    mutationFn: (data: { databaseId?: string; name: string }) =>
      createProjectDatabase(pid, data),
    onSuccess: () => {
      toast.success('Database created')
      navigate({
        to: '/projects/$projectId/databases',
        params: { projectId: pid },
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error) || 'Failed to create database')
    },
  })

  const handleCreate = () => {
    const newErrors: Record<string, string> = {}
    if (!name.trim()) newErrors.name = 'Name is required'
    if (databaseId?.trim() && !validateDatabaseId(databaseId)) {
      newErrors.databaseId =
        'Database ID must be 1–36 characters, alphanumeric, underscore, hyphen, or period. Cannot start with a special character.'
    }
    setErrors(newErrors)
    if (Object.keys(newErrors).length > 0) return
    createMutation.mutate({
      databaseId: databaseId?.trim() || undefined,
      name: name.trim(),
    })
  }

  const isCreatePending = createMutation.isPending
  const showNameForm =
    selectedSpec && (!isTablesDB || selectedSpec.id === 'shared')

  const canCreate =
    showNameForm && dbType === 'TablesDB' && name.trim().length > 0
  const footer = (
    <div className="flex w-full justify-end">
      <Button
        type="button"
        disabled={!canCreate || isCreatePending}
        onClick={handleCreate}
      >
        Create database
      </Button>
    </div>
  )

  return (
    <WizardLayout
      title="Create database"
      fullscreen
      useSidebar={false}
      maxWidth="max-w-4xl"
      fallbackPath={`/projects/${pid}/databases`}
      footer={footer}
      footerAlign="right"
    >
      <div className="space-y-10">
        {/* 1. Name & ID */}
        <section>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="db-name">
                Name <span className="text-destructive">*</span>
              </Label>
              <Input
                id="db-name"
                type="text"
                placeholder="My database"
                value={name}
                onChange={(e) => {
                  setName(e.target.value)
                  if (errors.name) setErrors((prev) => ({ ...prev, name: '' }))
                }}
                className={errors.name ? 'border-destructive' : ''}
              />
              {errors.name && (
                <p className="text-[12px] text-destructive">{errors.name}</p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="db-id">Database ID</Label>
              <IdInput
                id="db-id"
                value={databaseId}
                onChange={setDatabaseId}
                maxLength={36}
                placeholder="Leave blank to auto-generate"
              />
              {errors.databaseId && (
                <p className="text-[12px] text-destructive">
                  {errors.databaseId}
                </p>
              )}
            </div>
          </div>
        </section>

        {/* 2. Database type */}
        <section>
          <h2 className="text-[15px] font-semibold text-foreground mb-1">
            Choose database type
          </h2>
          <p className="text-[13px] text-muted-foreground mb-4">
            Choose the database type that best fits your use case. You can add
            more databases later.
          </p>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            {DB_TYPE_OPTIONS.map((opt) => (
              <button
                key={opt.id}
                type="button"
                onClick={() => {
                  setDbType(opt.id)
                  setSpecId(opt.id === 'TablesDB' ? 'shared' : null)
                }}
                className={cn(
                  'flex w-full cursor-pointer flex-col items-stretch gap-3 rounded-xl border border-border bg-card/50 p-5 text-left transition-all hover:border-border/80 hover:bg-card/60',
                  dbType === opt.id &&
                    'border-primary ring-1 ring-primary/20 hover:border-primary',
                )}
              >
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  {opt.icon === 'table' && <TableIcon className="h-5 w-5" />}
                  {opt.icon === 'braces' && <Braces className="h-5 w-5" />}
                  {opt.icon === 'layers' && <Layers className="h-5 w-5" />}
                </div>
                <span className="flex items-center gap-2">
                  <span className="text-[14px] font-medium text-foreground">
                    {opt.label}
                  </span>
                  {(opt.id === 'DocumentsDB' || opt.id === 'VectorsDB') && (
                    <Badge variant="info" className="text-[10px] shrink-0">
                      Beta
                    </Badge>
                  )}
                </span>
                <p className="text-[12px] text-muted-foreground">
                  {opt.description}
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {opt.tags.map((tag) => (
                    <Badge
                      key={tag}
                      variant="info"
                      className="text-[10px] shrink-0"
                    >
                      {tag}
                    </Badge>
                  ))}
                </div>
              </button>
            ))}
          </div>
        </section>

        {/* 3. Specifications (table) – revealed when type selected and that type has dedicated support */}
        {dbType && showSpecsForType && (
          <section>
            <h2 className="text-[15px] font-semibold text-foreground mb-1">
              Specifications
            </h2>
            <p className="text-[13px] text-muted-foreground mb-4">
              Select the compute and storage tier for your database.
            </p>
            <div className="rounded-lg border border-border bg-muted/30 px-4 py-3 mb-4">
              <p className="text-[13px] font-medium text-foreground">
                Each organization includes{' '}
                <span className="font-semibold">$10 of compute credits</span>{' '}
                for database usage every month.
              </p>
            </div>
            <div className="rounded-xl border border-border bg-card overflow-hidden">
              <RadioGroup
                value={specId ?? ''}
                onValueChange={(value) => setSpecId(value || null)}
                className="w-full"
              >
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border bg-muted/40">
                      <TableHead className="w-[48px] px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider" />
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        Tier
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        CPU
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        Memory
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[140px]">
                        Price
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {selectableSpecs.map((spec) => {
                      const locked = isTablesDB
                        ? spec.id !== 'shared'
                        : !!spec.comingSoon
                      const isSelected = selectedSpec?.id === spec.id
                      return (
                        <TableRow
                          key={spec.id}
                          className={cn(
                            'border-b border-border last:border-b-0 transition-colors',
                            locked && 'opacity-60',
                            !locked && 'cursor-pointer',
                            !locked && !isSelected && 'hover:bg-muted/40',
                            isSelected &&
                              !locked &&
                              'bg-primary/5 hover:bg-primary/5',
                          )}
                          onClick={() => !locked && setSpecId(spec.id)}
                        >
                          <TableCell className="w-[48px] px-4 py-3.5">
                            <RadioGroupItem
                              value={spec.id}
                              disabled={locked}
                              className="cursor-pointer"
                            />
                          </TableCell>
                          <TableCell className="px-4 py-3.5">
                            <span className="text-[13px] font-medium text-foreground">
                              {spec.label}
                            </span>
                          </TableCell>
                          <TableCell className="px-4 py-3.5 text-[13px] text-muted-foreground">
                            {spec.cpu}
                          </TableCell>
                          <TableCell className="px-4 py-3.5 text-[13px] text-muted-foreground">
                            {spec.memory}
                          </TableCell>
                          <TableCell className="px-4 py-3.5 text-right">
                            {locked ? (
                              <Badge
                                variant="inactive"
                                className="text-[10px] shrink-0"
                              >
                                Coming soon
                              </Badge>
                            ) : (
                              <span className="inline-block text-right text-[13px] font-semibold tabular-nums tracking-tight text-foreground">
                                {spec.price}
                              </span>
                            )}
                          </TableCell>
                        </TableRow>
                      )
                    })}
                  </TableBody>
                </Table>
              </RadioGroup>
            </div>
          </section>
        )}
      </div>
    </WizardLayout>
  )
}

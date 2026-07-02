'use client'

import { useState } from 'react'
import { Copy, Search } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const SCROLL_FADE_MASK_CLASS =
  '[mask-image:linear-gradient(to_bottom,black_calc(100%-5rem),transparent)] [-webkit-mask-image:linear-gradient(to_bottom,black_calc(100%-5rem),transparent)]'

const EXECUTIONS = [
  {
    id: '68a4e2f91b0c',
    status: 'completed' as const,
    trigger: 'event' as const,
    method: 'POST',
    path: '/',
    duration: '342ms',
    created: '2m ago',
    selected: true,
  },
  {
    id: '68a4d8c03f21',
    status: 'completed' as const,
    trigger: 'schedule' as const,
    method: 'POST',
    path: '/',
    duration: '1.2s',
    created: '18m ago',
    selected: false,
  },
  {
    id: '68a4c1aa7e55',
    status: 'completed' as const,
    trigger: 'http' as const,
    method: 'POST',
    path: '/webhook',
    duration: '89ms',
    created: '1h ago',
    selected: false,
  },
  {
    id: '68a4b9024d18',
    status: 'completed' as const,
    trigger: 'event' as const,
    method: 'POST',
    path: '/',
    duration: '410ms',
    created: '3h ago',
    selected: false,
  },
  {
    id: '68a4a11fc873',
    status: 'completed' as const,
    trigger: 'http' as const,
    method: 'GET',
    path: '/health',
    duration: '12ms',
    created: '5h ago',
    selected: false,
  },
  {
    id: '68a48e6a2b90',
    status: 'completed' as const,
    trigger: 'schedule' as const,
    method: 'POST',
    path: '/',
    duration: '2.4s',
    created: 'Yesterday',
    selected: false,
  },
] as const

const EXECUTION_LOGS = `Event: databases.*.tables.*.rows.*.create
Processing order ord_8f2a91c
Updated inventory for 3 SKUs
Sent receipt via Messaging topic "receipts"`

function MockId({ id }: { id: string }) {
  return (
    <span className="inline-flex max-w-[6.5rem] items-center gap-1 truncate font-mono text-[10px] text-foreground sm:text-[11px]">
      {id}
      <Copy className="size-3 shrink-0 text-muted-foreground/60" aria-hidden />
    </span>
  )
}

function triggerLabel(trigger: (typeof EXECUTIONS)[number]['trigger']) {
  if (trigger === 'event') return 'Event'
  if (trigger === 'schedule') return 'Schedule'
  return 'HTTP'
}

export function FunctionsExecutionsVisual() {
  const t = useT()
  const [logsSearch, setLogsSearch] = useState('')
  const selected = EXECUTIONS.find((execution) => execution.selected) ?? EXECUTIONS[0]

  const displayedLogs = logsSearch.trim()
    ? EXECUTION_LOGS.split('\n')
        .filter((line) => line.toLowerCase().includes(logsSearch.trim().toLowerCase()))
        .join('\n')
    : EXECUTION_LOGS

  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'overview', label: t('Overview') },
        { id: 'executions', label: t('Executions'), active: true },
        { id: 'settings', label: t('Settings') },
      ]}
      contentClassName="p-0"
    >
      <div className="grid min-h-[18rem] lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
        <div className="relative min-w-0 border-b border-border lg:border-b-0 lg:border-e">
          <div className={cn('relative max-h-[14rem] overflow-hidden', SCROLL_FADE_MASK_CLASS)}>
            <div className="overflow-x-auto">
              <Table className="min-w-[36rem]">
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="sticky top-0 z-10 bg-card/45 px-3 py-2.5 ps-4 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground border-b border-border sm:px-4 sm:text-[11px]">
                      {t('Execution ID')}
                    </TableHead>
                    <TableHead className="sticky top-0 z-10 bg-card/45 px-3 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground border-b border-border sm:text-[11px]">
                      {t('Status')}
                    </TableHead>
                    <TableHead className="sticky top-0 z-10 bg-card/45 px-3 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground border-b border-border sm:text-[11px]">
                      {t('Trigger')}
                    </TableHead>
                    <TableHead className="sticky top-0 z-10 bg-card/45 px-3 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground border-b border-border sm:text-[11px]">
                      {t('Method')}
                    </TableHead>
                    <TableHead className="sticky top-0 z-10 bg-card/45 px-3 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground border-b border-border sm:text-[11px]">
                      {t('Duration')}
                    </TableHead>
                    <TableHead className="sticky top-0 z-10 bg-card/45 px-3 py-2.5 pe-4 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground border-b border-border sm:px-4 sm:text-[11px]">
                      {t('Created')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {EXECUTIONS.map((execution) => (
                    <TableRow
                      key={execution.id}
                      className={cn(
                        'border-b border-border',
                        execution.selected
                          ? 'bg-muted/60 hover:bg-muted/60'
                          : 'hover:bg-muted/40',
                      )}
                    >
                      <TableCell className="px-3 py-2.5 ps-4 sm:px-4">
                        <MockId id={execution.id} />
                      </TableCell>
                      <TableCell className="px-3 py-2.5">
                        <Badge variant="completed" className="text-[10px]">
                          {t('Completed')}
                        </Badge>
                      </TableCell>
                      <TableCell className="px-3 py-2.5">
                        <Badge variant="outline" className="text-[10px]">
                          {t(triggerLabel(execution.trigger))}
                        </Badge>
                      </TableCell>
                      <TableCell className="px-3 py-2.5 font-mono text-[10px] text-foreground sm:text-[11px]">
                        {execution.method}
                      </TableCell>
                      <TableCell className="px-3 py-2.5 text-[10px] text-foreground sm:text-[11px]">
                        {execution.duration}
                      </TableCell>
                      <TableCell className="px-3 py-2.5 pe-4 text-[10px] text-muted-foreground sm:px-4 sm:text-[11px]">
                        {t(execution.created)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </div>
        </div>

        <div className="flex min-w-0 flex-col bg-muted/10 p-4 sm:p-5">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div className="min-w-0">
              <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Execution details')}
              </p>
              <p className="mt-0.5 truncate font-mono text-[12px] font-medium text-foreground">
                {selected.id}
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-1.5">
              <Badge variant="completed" className="text-[10px]">
                {t('Completed')}
              </Badge>
              <Badge variant="success" className="text-[10px]">
                200
              </Badge>
            </div>
          </div>

          <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-[11px]">
            <div>
              <dt className="text-muted-foreground">{t('Trigger')}</dt>
              <dd className="mt-0.5 font-medium text-foreground">
                {t(triggerLabel(selected.trigger))}
              </dd>
            </div>
            <div>
              <dt className="text-muted-foreground">{t('Duration')}</dt>
              <dd className="mt-0.5 font-medium text-foreground">{selected.duration}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground">{t('Method')}</dt>
              <dd className="mt-0.5 font-mono text-foreground">{selected.method}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground">{t('Path')}</dt>
              <dd className="mt-0.5 truncate font-mono text-foreground">{selected.path}</dd>
            </div>
          </dl>

          <Tabs defaultValue="logs" className="mt-4 min-h-0 flex-1 gap-3">
            <TabsList className="grid h-8 w-full grid-cols-4">
              <TabsTrigger value="logs" className="text-[11px]">
                {t('Logs')}
              </TabsTrigger>
              <TabsTrigger value="errors" className="text-[11px]">
                {t('Errors')}
              </TabsTrigger>
              <TabsTrigger value="headers" className="text-[11px]">
                {t('Headers')}
              </TabsTrigger>
              <TabsTrigger value="body" className="text-[11px]">
                {t('Body')}
              </TabsTrigger>
            </TabsList>

            <TabsContent value="logs" className="mt-0 min-h-0">
              <div className="rounded-lg border border-border bg-card p-3">
                <div className="relative mb-2">
                  <Search className="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    placeholder={t('Search logs...')}
                    value={logsSearch}
                    onChange={(event) => setLogsSearch(event.target.value)}
                    className="h-8 ps-8 text-[12px]"
                  />
                </div>
                <div className="rounded-md border border-border bg-muted/15 p-3">
                  <pre className="whitespace-pre-wrap break-all font-mono text-[10px] leading-relaxed text-foreground sm:text-[11px]">
                    {displayedLogs || t('No matching log lines.')}
                  </pre>
                </div>
              </div>
            </TabsContent>

            <TabsContent value="errors" className="mt-0">
              <div className="rounded-lg border border-border bg-card p-3">
                <code className="text-[11px] text-muted-foreground">{t('No errors found.')}</code>
              </div>
            </TabsContent>

            <TabsContent value="headers" className="mt-0">
              <div className="rounded-lg border border-border bg-card p-3">
                <code className="text-[11px] text-muted-foreground">{t('No headers found.')}</code>
              </div>
            </TabsContent>

            <TabsContent value="body" className="mt-0">
              <div className="rounded-lg border border-border bg-card p-3">
                <code className="text-[11px] text-muted-foreground">
                  {t('Response bodies are not stored by default.')}
                </code>
              </div>
            </TabsContent>
          </Tabs>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}

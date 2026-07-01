import { useState, useEffect, useMemo } from 'react'
import {
  ShieldX,
  ShieldCheck,
  ShieldAlert,
  Search,
  Download,
  RefreshCw,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Skeleton } from '@/components/ui/skeleton'
import { mockFirewallLogs, type FirewallLog } from '@/lib/utils/mock-data'

interface LogsTabProps {
  projectId: string
  searchValue: string
}

export function LogsTab({ searchValue }: LogsTabProps) {
  const [logs, setLogs] = useState<FirewallLog[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [filterAction, setFilterAction] = useState<string>('all')
  const [filterRule, setFilterRule] = useState<string>('all')

  useEffect(() => {
    // Simulate loading
    setIsLoading(true)
    setTimeout(() => {
      setLogs(mockFirewallLogs)
      setIsLoading(false)
    }, 500)
  }, [])

  const filteredLogs = useMemo(() => {
    let filtered = logs

    // Search filter
    if (searchValue.trim()) {
      const searchLower = searchValue.toLowerCase()
      filtered = filtered.filter(
        (log) =>
          log.ipAddress.toLowerCase().includes(searchLower) ||
          log.path?.toLowerCase().includes(searchLower) ||
          log.ruleName?.toLowerCase().includes(searchLower) ||
          log.userAgent?.toLowerCase().includes(searchLower),
      )
    }

    // Action filter
    if (filterAction !== 'all') {
      filtered = filtered.filter((log) => log.action === filterAction)
    }

    // Rule filter
    if (filterRule !== 'all') {
      filtered = filtered.filter((log) => log.ruleId === filterRule)
    }

    return filtered
  }, [logs, searchValue, filterAction, filterRule])

  const uniqueRules = useMemo(() => {
    const ruleMap = new Map<string, string>()
    logs.forEach((log) => {
      if (log.ruleId && log.ruleName) {
        ruleMap.set(log.ruleId, log.ruleName)
      }
    })
    return Array.from(ruleMap.entries()).map(([id, name]) => ({ id, name }))
  }, [logs])

  const getActionIcon = (action: string) => {
    switch (action) {
      case 'block':
        return <ShieldX className="h-4 w-4 text-red-500" />
      case 'allow':
        return <ShieldCheck className="h-4 w-4 text-emerald-500" />
      case 'challenge':
        return <ShieldAlert className="h-4 w-4 text-amber-500" />
      default:
        return null
    }
  }

  const getStatusCodeColor = (statusCode: number) => {
    if (statusCode >= 200 && statusCode < 300) return 'text-emerald-500'
    if (statusCode >= 300 && statusCode < 400) return 'text-amber-500'
    if (statusCode >= 400 && statusCode < 500) return 'text-red-500'
    if (statusCode >= 500) return 'text-red-600'
    return 'text-muted-foreground'
  }

  if (isLoading) {
    return (
      <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
        <div className="rounded-xl border border-border bg-card/50">
          <div className="divide-y divide-border">
            {Array.from({ length: 10 }).map((_, i) => (
              <div key={i} className="p-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <Skeleton className="h-5 w-5 rounded" />
                    <div className="space-y-2">
                      <Skeleton className="h-4 w-48" />
                      <Skeleton className="h-3 w-64" />
                    </div>
                  </div>
                  <Skeleton className="h-6 w-20 rounded" />
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
      {/* Filters */}
      <div className="mb-4 flex items-center gap-3">
        <Select value={filterAction} onValueChange={setFilterAction}>
          <SelectTrigger className="w-[140px] h-9">
            <SelectValue placeholder="All actions" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All actions</SelectItem>
            <SelectItem value="block">Blocked</SelectItem>
            <SelectItem value="allow">Allowed</SelectItem>
            <SelectItem value="challenge">Challenged</SelectItem>
          </SelectContent>
        </Select>

        <Select value={filterRule} onValueChange={setFilterRule}>
          <SelectTrigger className="w-[200px] h-9">
            <SelectValue placeholder="All rules" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All rules</SelectItem>
            {uniqueRules.map((rule) => (
              <SelectItem key={rule.id} value={rule.id}>
                {rule.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <div className="ms-auto flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className="h-9 gap-1.5"
            onClick={() => {
              setIsLoading(true)
              setTimeout(() => {
                setLogs(mockFirewallLogs)
                setIsLoading(false)
              }, 500)
            }}
          >
            <RefreshCw className="h-3.5 w-3.5" />
            Refresh
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="h-9 gap-1.5"
            onClick={() => {
              // Export logs
              const csv = [
                [
                  'Timestamp',
                  'Action',
                  'IP Address',
                  'Path',
                  'Method',
                  'Status Code',
                  'Rule',
                ].join(','),
                ...filteredLogs.map((log) =>
                  [
                    log.timestamp,
                    log.action,
                    log.ipAddress,
                    log.path || '',
                    log.method || '',
                    log.statusCode || '',
                    log.ruleName || '',
                  ].join(','),
                ),
              ].join('\n')
              const blob = new Blob([csv], { type: 'text/csv' })
              const url = URL.createObjectURL(blob)
              const a = document.createElement('a')
              a.href = url
              a.download = `firewall-logs-${new Date().toISOString()}.csv`
              a.click()
              URL.revokeObjectURL(url)
            }}
          >
            <Download className="h-3.5 w-3.5" />
            Export
          </Button>
        </div>
      </div>

      {filteredLogs.length === 0 ? (
        <EmptyState
          icon={Search}
          title="No logs found"
          description={
            searchValue || filterAction !== 'all' || filterRule !== 'all'
              ? undefined
              : 'Firewall logs will appear here once rules start processing requests'
          }
          isEmpty={
            !searchValue && filterAction === 'all' && filterRule === 'all'
          }
          hasFilters={
            !!searchValue || filterAction !== 'all' || filterRule !== 'all'
          }
          variant="card"
        />
      ) : (
        <div className="rounded-xl border border-border bg-card/50">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]">
                  Action
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Timestamp
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  IP Address
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Path
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Method
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Status
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Rule
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Country
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredLogs.map((log) => (
                <TableRow key={log.$id}>
                  <TableCell className="px-4 py-3">
                    <div className="flex items-center">
                      {getActionIcon(log.action)}
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DateTooltip
                      date={log.timestamp}
                      className="text-[12px] text-muted-foreground"
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <span className="text-[12px] font-mono text-foreground">
                      {log.ipAddress}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <span className="text-[12px] text-foreground">
                      {log.path || '-'}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Badge variant="outline" className="text-[10px] font-mono">
                      {log.method || '-'}
                    </Badge>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {log.statusCode ? (
                      <span
                        className={cn(
                          'text-[12px] font-medium',
                          getStatusCodeColor(log.statusCode),
                        )}
                      >
                        {log.statusCode}
                      </span>
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        -
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {log.ruleName ? (
                      <span className="text-[12px] text-foreground">
                        {log.ruleName}
                      </span>
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        -
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {log.country ? (
                      <span className="text-[12px] text-muted-foreground">
                        {log.country}
                      </span>
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        -
                      </span>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  )
}

import { useState } from 'react'
import { cn } from '@/lib/utils'
import {
  TrendingUp,
  TrendingDown,
  ChevronLeft,
  ChevronDown,
  Globe,
  Chrome,
} from 'lucide-react'
import {
  Area,
  AreaChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
  RadialBarChart,
  RadialBar,
  BarChart,
  Bar,
  Cell,
} from 'recharts'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from '@/components/ui/chart'

// Types
interface VisitorMetric {
  id: string
  value: number
  label: string
  change: number
}

interface LocationData {
  country: string
  flag: string
  code: string
  visitors: number
  uniqueVisitors: number
}

interface PageData {
  path: string
  visitors: number
}

interface SourceData {
  name: string
  icon: React.ReactNode
  visitors: number
}

interface BrowserData {
  name: string
  icon: React.ReactNode
  visitors: number
}

interface OSData {
  name: string
  visitors: number
  color: string
  fill: string
}

// Mock data
const visitorMetrics: VisitorMetric[] = [
  { id: 'unique', value: 10402, label: 'Unique visitors', change: 218 },
  { id: 'total', value: 14402, label: 'Total visitors', change: 225 },
  { id: 'pageviews', value: 24891, label: 'Page views', change: 312 },
  { id: 'pagesPerVisit', value: 2.4, label: 'Pages per visit', change: 8 },
  { id: 'bounceRate', value: 42.3, label: 'Bounce rate', change: -5 },
  { id: 'visitDuration', value: 185, label: 'Visit duration', change: 12 },
]

const generateChartData = () => {
  const data = []
  const startDate = new Date('2024-09-26')

  for (let i = 0; i < 30; i++) {
    const date = new Date(startDate)
    date.setDate(startDate.getDate() + i)
    const dateStr = date.toLocaleDateString('en-US', {
      day: 'numeric',
      month: 'short',
    })

    const baseVisitors = 6000 + Math.random() * 4000
    data.push({
      date: dateStr,
      visitors: Math.floor(baseVisitors),
    })
  }
  return data
}

const chartData = generateChartData()

const locationData: LocationData[] = [
  {
    country: 'India',
    flag: '🇮🇳',
    code: 'IN',
    visitors: 46800,
    uniqueVisitors: 6400,
  },
  {
    country: 'United States',
    flag: '🇺🇸',
    code: 'US',
    visitors: 39200,
    uniqueVisitors: 6200,
  },
  {
    country: 'Germany',
    flag: '🇩🇪',
    code: 'DE',
    visitors: 37100,
    uniqueVisitors: 5100,
  },
  {
    country: 'United Kingdom',
    flag: '🇬🇧',
    code: 'GB',
    visitors: 35500,
    uniqueVisitors: 4900,
  },
  {
    country: 'Netherlands',
    flag: '🇳🇱',
    code: 'NL',
    visitors: 34400,
    uniqueVisitors: 3800,
  },
  {
    country: 'Australia',
    flag: '🇦🇺',
    code: 'AU',
    visitors: 29000,
    uniqueVisitors: 3200,
  },
  {
    country: 'Canada',
    flag: '🇨🇦',
    code: 'CA',
    visitors: 13800,
    uniqueVisitors: 3000,
  },
  {
    country: 'Singapore',
    flag: '🇸🇬',
    code: 'SG',
    visitors: 7600,
    uniqueVisitors: 2600,
  },
]

const topPages: PageData[] = [
  { path: '/', visitors: 2300 },
  { path: '/page', visitors: 1900 },
  { path: '/page/subpage', visitors: 1200 },
  { path: '/docs', visitors: 948 },
  { path: '/pricing', visitors: 876 },
  { path: '/blog', visitors: 654 },
  { path: '/about', visitors: 566 },
  { path: '/contact', visitors: 211 },
]

const topSources: SourceData[] = [
  {
    name: 'Google',
    icon: <span className="text-[14px] font-medium">G</span>,
    visitors: 145600,
  },
  { name: 'GitHub', icon: <Globe className="h-4 w-4" />, visitors: 94100 },
  {
    name: 'Twitter',
    icon: <span className="text-[14px] font-bold">𝕏</span>,
    visitors: 47800,
  },
  {
    name: 'Youtube',
    icon: <span className="text-[14px]">▶</span>,
    visitors: 26600,
  },
  { name: 'Direct', icon: <Globe className="h-4 w-4" />, visitors: 2800 },
]

const browsers: BrowserData[] = [
  { name: 'Chrome', icon: <Chrome className="h-4 w-4" />, visitors: 24700 },
  { name: 'Firefox', icon: <Globe className="h-4 w-4" />, visitors: 11900 },
  { name: 'Safari', icon: <Globe className="h-4 w-4" />, visitors: 6400 },
  {
    name: 'Microsoft Edge',
    icon: <Globe className="h-4 w-4" />,
    visitors: 3700,
  },
  { name: 'Opera', icon: <Globe className="h-4 w-4" />, visitors: 2100 },
]

const operatingSystems: OSData[] = [
  { name: 'Mac', visitors: 94200, color: '#dc2626', fill: '#dc2626' },
  { name: 'Windows', visitors: 67300, color: '#ea580c', fill: '#ea580c' },
  { name: 'Linux', visitors: 20900, color: '#f59e0b', fill: '#f59e0b' },
  { name: 'iOS', visitors: 19200, color: '#ef4444', fill: '#ef4444' },
  { name: 'Android', visitors: 18900, color: '#f97316', fill: '#f97316' },
]

// Helper functions
function formatNumber(num: number): string {
  if (num >= 1000000) {
    return (num / 1000000).toFixed(1) + 'M'
  }
  if (num >= 1000) {
    return (num / 1000).toFixed(1) + 'K'
  }
  return num.toString()
}

function formatMetricValue(metric: VisitorMetric): string {
  switch (metric.id) {
    case 'pagesPerVisit':
      return metric.value.toFixed(1)
    case 'bounceRate':
      return metric.value.toFixed(1) + '%'
    case 'visitDuration': {
      const minutes = Math.floor(metric.value / 60)
      const seconds = metric.value % 60
      return `${minutes}m ${seconds}s`
    }
    default:
      return formatNumber(metric.value)
  }
}

// Custom tooltip for the chart
interface CustomTooltipProps {
  active?: boolean
  payload?: Array<{
    value: number
    payload: {
      date: string
      visitors: number
    }
  }>
}

const CustomTooltip = ({ active, payload }: CustomTooltipProps) => {
  if (active && payload && payload.length) {
    const data = payload[0].payload
    return (
      <div className="rounded-lg border border-border bg-popover px-3 py-2 shadow-xl">
        <p className="text-[13px] font-medium text-foreground">
          {data.date} {formatNumber(data.visitors)}
        </p>
      </div>
    )
  }
  return null
}

// Metric Tab Component
function MetricTab({
  metric,
  isActive,
  onClick,
}: {
  metric: VisitorMetric
  isActive: boolean
  onClick: () => void
}) {
  // For bounce rate, negative change is good (lower bounce = better)
  const isPositive =
    metric.id === 'bounceRate' ? metric.change < 0 : metric.change > 0
  const TrendIcon = isPositive ? TrendingUp : TrendingDown

  return (
    <button
      onClick={onClick}
      className={cn(
        'relative flex min-w-[150px] flex-col gap-0.5 px-4 py-3 text-left transition-colors',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        isActive
          ? 'text-foreground'
          : 'text-muted-foreground hover:text-foreground/80',
      )}
    >
      <div className="flex items-baseline gap-2">
        <span
          className={cn(
            'text-[20px] font-semibold tracking-tight',
            isActive ? 'text-foreground' : 'text-muted-foreground',
          )}
        >
          {formatMetricValue(metric)}
        </span>
        <span
          className={cn(
            'flex items-center gap-0.5 text-[11px] font-medium',
            isPositive
              ? isActive
                ? 'text-emerald-500'
                : 'text-emerald-500/60'
              : isActive
                ? 'text-red-500'
                : 'text-red-500/60',
          )}
        >
          <TrendIcon className="h-3 w-3" />
          {Math.abs(metric.change)}%
        </span>
      </div>
      <span
        className={cn(
          'text-[12px]',
          isActive ? 'text-muted-foreground' : 'text-muted-foreground/70',
        )}
      >
        {metric.label}
      </span>
      {isActive && (
        <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-foreground" />
      )}
    </button>
  )
}

// List Item Component - matches TopRequests pattern
function ListItem({
  label,
  value,
  maxValue,
  icon,
  prefix,
}: {
  label: string
  value: number
  maxValue: number
  icon?: React.ReactNode
  prefix?: React.ReactNode
}) {
  const percentage = (value / maxValue) * 100

  return (
    <div className="group relative flex items-center gap-3 rounded-md px-2 py-2 transition-colors hover:bg-accent/50">
      {/* Progress bar background */}
      <div
        className="absolute inset-y-0 left-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
        style={{ width: `${percentage}%` }}
      />

      {/* Content */}
      <div className="relative flex flex-1 items-center gap-2">
        {prefix && <span className="text-[14px]">{prefix}</span>}
        {icon && (
          <span className="flex h-5 w-5 items-center justify-center text-muted-foreground">
            {icon}
          </span>
        )}
        <span className="flex-1 truncate text-[13px] text-foreground/80">
          {label}
        </span>
        <span className="text-[13px] font-medium text-muted-foreground">
          {formatNumber(value)}
        </span>
      </div>
    </div>
  )
}

// World Map Chart Component using built-in ChartContainer
function WorldMapChart({ data }: { data: LocationData[] }) {
  // Transform data for the bar chart
  const chartData = data.map((item) => ({
    country: item.country,
    flag: item.flag,
    visitors: item.visitors,
    fill: '#f02e65',
  }))

  const chartConfig = {
    visitors: {
      label: 'Visitors',
      color: '#f02e65',
    },
  } satisfies ChartConfig

  return (
    <ChartContainer config={chartConfig} className="h-[240px] w-full">
      <BarChart
        data={chartData}
        layout="vertical"
        margin={{ top: 0, right: 16, left: 0, bottom: 0 }}
      >
        <XAxis type="number" hide />
        <YAxis
          type="category"
          dataKey="country"
          axisLine={false}
          tickLine={false}
          width={100}
          tick={({ x, y, payload }) => {
            const item = data.find((d) => d.country === payload.value)
            return (
              <g transform={`translate(${x},${y})`}>
                <text
                  x={-8}
                  y={0}
                  dy={4}
                  textAnchor="end"
                  className="fill-foreground text-[12px]"
                >
                  {item?.flag} {payload.value}
                </text>
              </g>
            )
          }}
        />
        <ChartTooltip
          cursor={{ fill: 'hsl(var(--accent))', opacity: 0.3 }}
          content={
            <ChartTooltipContent
              hideLabel
              formatter={(value, _name, item) => (
                <div className="flex items-center gap-2">
                  <span>{item.payload.flag}</span>
                  <span className="font-medium">{item.payload.country}</span>
                  <span className="ml-auto tabular-nums">
                    {formatNumber(value as number)}
                  </span>
                </div>
              )}
            />
          }
        />
        <Bar dataKey="visitors" radius={[0, 4, 4, 0]} barSize={20}>
          {chartData.map((_entry, index) => (
            <Cell
              key={`cell-${index}`}
              fill="#f02e65"
              fillOpacity={0.2 + (0.8 * (data.length - index)) / data.length}
            />
          ))}
        </Bar>
      </BarChart>
    </ChartContainer>
  )
}

// OS Chart using RadialBarChart
function OSRadialChart({ data }: { data: OSData[] }) {
  const total = data.reduce((sum, item) => sum + item.visitors, 0)

  // Transform data for RadialBarChart
  const chartData = data
    .map((item) => ({
      name: item.name,
      visitors: item.visitors,
      fill: item.fill,
      // Calculate percentage for display
      percentage: Math.round((item.visitors / total) * 100),
    }))
    .reverse() // Reverse so largest is on outside

  const chartConfig: ChartConfig = {}

  return (
    <div className="flex items-center gap-6">
      {/* Legend */}
      <div className="flex flex-1 flex-col gap-1">
        {data.map((os) => (
          <div key={os.name} className="flex items-center gap-3 py-1">
            <div
              className="h-2.5 w-2.5 rounded-full"
              style={{ backgroundColor: os.color }}
            />
            <span className="flex-1 text-[13px] text-foreground">
              {os.name}
            </span>
            <span className="text-[13px] font-medium text-muted-foreground">
              {formatNumber(os.visitors)}
            </span>
          </div>
        ))}
      </div>

      {/* Radial Chart */}
      <ChartContainer config={chartConfig} className="h-[140px] w-[140px]">
        <RadialBarChart
          cx="50%"
          cy="50%"
          innerRadius="30%"
          outerRadius="100%"
          barSize={10}
          data={chartData}
          startAngle={90}
          endAngle={-270}
        >
          <RadialBar
            dataKey="visitors"
            cornerRadius={5}
          />
        </RadialBarChart>
      </ChartContainer>
    </div>
  )
}

// Main Component
interface WebsiteAnalyticsDetailProps {
  websiteId?: string
  websiteName?: string
  onBack?: () => void
}

export function WebsiteAnalyticsDetail({
  websiteName = 'Main Marketing Site',
  onBack,
}: WebsiteAnalyticsDetailProps) {
  const [activeTab, setActiveTab] = useState('analytics')
  const [activeMetric, setActiveMetric] = useState('unique')
  const [dateRange, setDateRange] = useState('Last 30 days')
  const [locationView, setLocationView] = useState<'map' | 'countries'>(
    'countries',
  )

  const tabs = [
    { id: 'analytics', label: 'Analytics' },
    { id: 'settings', label: 'Settings' },
  ]

  const maxLocationVisitors = Math.max(...locationData.map((l) => l.visitors))
  const maxPageVisitors = Math.max(...topPages.map((p) => p.visitors))
  const maxSourceVisitors = Math.max(...topSources.map((s) => s.visitors))
  const maxBrowserVisitors = Math.max(...browsers.map((b) => b.visitors))

  return (
    <div className="flex h-full flex-col">
      {/* Header with back button and tabs */}
      <div className="border-b border-border">
        <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
          <div className="flex items-center gap-3 py-3">
            {onBack && (
              <Button
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={onBack}
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
            )}
            <h1 className="text-[15px] font-medium text-foreground">
              {websiteName}
            </h1>
          </div>
          <div className="flex gap-1">
            {tabs.map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={cn(
                  'relative px-3 py-2 text-[13px] font-medium transition-colors',
                  activeTab === tab.id
                    ? 'text-foreground'
                    : 'text-muted-foreground hover:text-foreground',
                )}
              >
                {tab.label}
                {activeTab === tab.id && (
                  <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-foreground" />
                )}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Content */}
      <div className="flex-1 overflow-y-auto">
        <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
          {activeTab === 'analytics' && (
            <>
              {/* Active visitors and date range */}
              <div className="mb-4 flex items-center justify-between">
            <div className="flex items-center gap-2">
              <span className="h-2 w-2 rounded-full bg-emerald-500" />
              <span className="text-[13px] text-muted-foreground">
                30 active visitors
              </span>
            </div>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 gap-2 text-[13px]"
                >
                  {dateRange}
                  <ChevronDown className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => setDateRange('Last 7 days')}>
                  Last 7 days
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => setDateRange('Last 30 days')}>
                  Last 30 days
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => setDateRange('Last 90 days')}>
                  Last 90 days
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() => setDateRange('Last 12 months')}
                >
                  Last 12 months
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>

          {/* Metrics and Chart Card */}
          <div className="rounded-xl border border-border bg-card">
            {/* Metric Tabs */}
            <div className="border-b border-border px-2">
              <div className="flex overflow-x-auto overflow-y-hidden">
                {visitorMetrics.map((metric, index) => (
                  <div key={metric.id} className="flex shrink-0">
                    {index > 0 && <div className="my-2.5 w-px bg-border" />}
                    <MetricTab
                      metric={metric}
                      isActive={activeMetric === metric.id}
                      onClick={() => setActiveMetric(metric.id)}
                    />
                  </div>
                ))}
              </div>
            </div>

            {/* Chart */}
            <div className="p-5">
              <div className="h-[280px] text-muted-foreground">
                <ResponsiveContainer width="100%" height="100%">
                  <AreaChart
                    data={chartData}
                    margin={{ top: 10, right: 10, left: 0, bottom: 0 }}
                  >
                    <defs>
                      <linearGradient
                        id="visitorGradient"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                      >
                        <stop
                          offset="0%"
                          stopColor="#f02e65"
                          stopOpacity={0.15}
                        />
                        <stop
                          offset="100%"
                          stopColor="#f02e65"
                          stopOpacity={0}
                        />
                      </linearGradient>
                    </defs>
                    <XAxis
                      dataKey="date"
                      axisLine={false}
                      tickLine={false}
                      tick={{
                        fill: 'currentColor',
                        fontSize: 12,
                      }}
                      dy={10}
                      interval="preserveStartEnd"
                      tickFormatter={(value, index) => {
                        if (index % 5 === 0) return value
                        return ''
                      }}
                    />
                    <YAxis
                      axisLine={false}
                      tickLine={false}
                      tick={{
                        fill: 'currentColor',
                        fontSize: 12,
                      }}
                      tickFormatter={(value) => {
                        if (value >= 1000)
                          return `${(value / 1000).toFixed(0)}k`
                        return value.toString()
                      }}
                      dx={-5}
                      width={40}
                    />
                    <Tooltip content={<CustomTooltip />} cursor={false} />
                    <Area
                      type="monotone"
                      dataKey="visitors"
                      stroke="#f02e65"
                      strokeWidth={2}
                      fill="url(#visitorGradient)"
                      dot={false}
                      activeDot={{
                        r: 5,
                        fill: '#f02e65',
                        stroke: '#fff',
                        strokeWidth: 2,
                      }}
                    />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            </div>
          </div>

          {/* Two Column Layout: Locations + Top Pages */}
          <div className="mt-6 grid gap-6 lg:grid-cols-2">
            {/* Top Locations */}
            <div className="rounded-xl border border-border bg-card p-5">
              <div className="mb-4 flex items-center justify-between">
                <h3 className="text-[14px] font-medium text-foreground">
                  Top locations
                </h3>
                <Tabs
                  value={locationView}
                  onValueChange={(v) =>
                    setLocationView(v as 'map' | 'countries')
                  }
                >
                  <TabsList className="h-8">
                    <TabsTrigger value="map" className="h-6 px-3 text-[13px]">
                      Map
                    </TabsTrigger>
                    <TabsTrigger
                      value="countries"
                      className="h-6 px-3 text-[13px]"
                    >
                      Countries
                    </TabsTrigger>
                  </TabsList>
                </Tabs>
              </div>

              {locationView === 'map' ? (
                <WorldMapChart data={locationData} />
              ) : (
                <div className="space-y-1">
                  {locationData.map((location) => (
                    <ListItem
                      key={location.country}
                      label={location.country}
                      value={location.visitors}
                      maxValue={maxLocationVisitors}
                      prefix={location.flag}
                    />
                  ))}
                </div>
              )}
            </div>

            {/* Top Pages */}
            <div className="rounded-xl border border-border bg-card p-5">
              <div className="mb-4 flex items-center justify-between">
                <h3 className="text-[14px] font-medium text-foreground">
                  Top pages
                </h3>
                <button className="text-[13px] text-muted-foreground transition-colors hover:text-foreground">
                  View all
                </button>
              </div>
              <div className="space-y-1">
                {topPages.map((page) => (
                  <ListItem
                    key={page.path}
                    label={page.path}
                    value={page.visitors}
                    maxValue={maxPageVisitors}
                  />
                ))}
              </div>
            </div>
          </div>

          {/* Three Column Layout: Sources, Browsers, OS */}
          <div className="mt-6 grid gap-6 lg:grid-cols-3">
            {/* Top Sources */}
            <div className="rounded-xl border border-border bg-card p-5">
              <div className="mb-4 flex items-center justify-between">
                <h3 className="text-[14px] font-medium text-foreground">
                  Top sources
                </h3>
                <button className="text-[13px] text-muted-foreground transition-colors hover:text-foreground">
                  View all
                </button>
              </div>
              <div className="space-y-1">
                {topSources.map((source) => (
                  <ListItem
                    key={source.name}
                    label={source.name}
                    value={source.visitors}
                    maxValue={maxSourceVisitors}
                    icon={source.icon}
                  />
                ))}
              </div>
            </div>

            {/* Browsers */}
            <div className="rounded-xl border border-border bg-card p-5">
              <div className="mb-4 flex items-center justify-between">
                <h3 className="text-[14px] font-medium text-foreground">
                  Browsers
                </h3>
                <button className="text-[13px] text-muted-foreground transition-colors hover:text-foreground">
                  View all
                </button>
              </div>
              <div className="space-y-1">
                {browsers.map((browser) => (
                  <ListItem
                    key={browser.name}
                    label={browser.name}
                    value={browser.visitors}
                    maxValue={maxBrowserVisitors}
                    icon={browser.icon}
                  />
                ))}
              </div>
            </div>

            {/* Operating Systems */}
            <div className="rounded-xl border border-border bg-card p-5">
              <div className="mb-4">
                <h3 className="text-[14px] font-medium text-foreground">
                  Operating systems
                </h3>
              </div>
              <OSRadialChart data={operatingSystems} />
            </div>
          </div>
            </>
          )}

          {activeTab === 'settings' && (
            <div className="rounded-xl border border-border bg-card p-5">
              <h3 className="mb-4 text-[14px] font-medium text-foreground">
                Settings
              </h3>
              <p className="text-[13px] text-muted-foreground">
                Settings content will be displayed here.
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

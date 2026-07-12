import { useState, useRef, useEffect } from 'react'
import * as React from 'react'
import { cn } from '@/lib/utils'
import { DateRange } from 'react-day-picker'
import { startOfDay, endOfDay, subDays } from 'date-fns'
import {
  TrendingUp,
  TrendingDown,
  ArrowLeft,
  ChevronDown,
  Globe,
  Chrome,
  Monitor,
  Smartphone,
  Tablet,
  Users,
  UserPlus,
} from 'lucide-react'
import {
  ServiceHeader,
  type Tab,
} from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  createCompactCountAxisTickFormatter,
  getChartSeriesMax,
} from '@/lib/usage/format-metric'
import { USAGE_CHART_Y_AXIS_WIDTH } from '../overview/chart-panel'
import { USAGE_CHART_MARGIN } from '@/lib/usage/chart-layout'
import { SeriesChartXAxis } from '@/components/global/shared/ChartXAxis'
import { Button } from '@/components/ui/button'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { ComparisonSelector, type ComparisonType } from './ComparisonSelector'
import {
  Area,
  AreaChart,
  ResponsiveContainer,
  Tooltip,
  YAxis,
  BarChart,
  Bar,
} from 'recharts'
import {
  Map,
  MapMarker,
  MapControls,
  MarkerContent,
  MarkerPopup,
  COUNTRY_COORDINATES,
  useMap,
} from '@/components/ui/map'
import { sdk } from '@/lib/appwrite/sdk'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs'
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
} from '@/components/ui/chart'
import { useT } from '@/lib/i18n/translate'

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

interface ChannelData {
  name: string
  visitors: number
  color: string
}

interface CampaignData {
  name: string
  visitors: number
}

interface RegionData {
  region: string
  country: string
  flag: string
  code: string
  visitors: number
}

interface CityData {
  city: string
  country: string
  flag: string
  code: string
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

interface DeviceData {
  type: string
  icon: React.ReactNode
  visitors: number
  color: string
}

interface EntryExitData {
  path: string
  visitors: number
  type: 'entry' | 'exit'
}

interface HourData {
  hour: number
  visitors: number
  label: string
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
const visitorChartAxisMax = getChartSeriesMax(
  chartData.map((point) => ({ value: point.visitors })),
)
const visitorYAxisTickFormatter =
  createCompactCountAxisTickFormatter(visitorChartAxisMax)

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

const channels: ChannelData[] = [
  { name: 'Organic Search', visitors: 145600, color: 'var(--chart-1)' },
  { name: 'Social', visitors: 94100, color: 'var(--chart-2)' },
  { name: 'Direct', visitors: 47800, color: 'var(--chart-3)' },
  { name: 'Referral', visitors: 26600, color: 'var(--chart-4)' },
  { name: 'Email', visitors: 11200, color: 'var(--chart-5)' },
]

const campaigns: CampaignData[] = [
  { name: 'Summer Launch 2024', visitors: 34200 },
  { name: 'Product Update', visitors: 28900 },
  { name: 'Blog Series', visitors: 15600 },
  { name: 'Newsletter', visitors: 11200 },
  { name: 'Social Campaign', visitors: 8900 },
]

const regions: RegionData[] = [
  {
    region: 'South Asia',
    country: 'India',
    flag: '🇮🇳',
    code: 'IN',
    visitors: 46800,
  },
  {
    region: 'North America',
    country: 'United States',
    flag: '🇺🇸',
    code: 'US',
    visitors: 39200,
  },
  {
    region: 'Western Europe',
    country: 'Germany',
    flag: '🇩🇪',
    code: 'DE',
    visitors: 37100,
  },
  {
    region: 'Western Europe',
    country: 'United Kingdom',
    flag: '🇬🇧',
    code: 'GB',
    visitors: 35500,
  },
  {
    region: 'Western Europe',
    country: 'Netherlands',
    flag: '🇳🇱',
    code: 'NL',
    visitors: 34400,
  },
]

const cities: CityData[] = [
  { city: 'Mumbai', country: 'India', flag: '🇮🇳', code: 'IN', visitors: 12400 },
  {
    city: 'New York',
    country: 'United States',
    flag: '🇺🇸',
    code: 'US',
    visitors: 11200,
  },
  {
    city: 'Berlin',
    country: 'Germany',
    flag: '🇩🇪',
    code: 'DE',
    visitors: 9800,
  },
  {
    city: 'London',
    country: 'United Kingdom',
    flag: '🇬🇧',
    code: 'GB',
    visitors: 8900,
  },
  {
    city: 'Amsterdam',
    country: 'Netherlands',
    flag: '🇳🇱',
    code: 'NL',
    visitors: 7600,
  },
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
  { name: 'Mac', visitors: 94200, color: 'var(--chart-1)', fill: 'var(--chart-1)' },
  {
    name: 'Windows',
    visitors: 67300,
    color: 'var(--chart-2)',
    fill: 'var(--chart-2)',
  },
  {
    name: 'Linux',
    visitors: 20900,
    color: 'var(--chart-3)',
    fill: 'var(--chart-3)',
  },
  { name: 'iOS', visitors: 19200, color: 'var(--chart-4)', fill: 'var(--chart-4)' },
  {
    name: 'Android',
    visitors: 18900,
    color: 'var(--chart-5)',
    fill: 'var(--chart-5)',
  },
]

const devices: DeviceData[] = [
  {
    type: 'Desktop',
    icon: <Monitor className="h-4 w-4" />,
    visitors: 124800,
    color: 'var(--chart-1)',
  },
  {
    type: 'Mobile',
    icon: <Smartphone className="h-4 w-4" />,
    visitors: 89200,
    color: 'var(--chart-2)',
  },
  {
    type: 'Tablet',
    icon: <Tablet className="h-4 w-4" />,
    visitors: 12400,
    color: 'var(--chart-3)',
  },
]

const entryPages: EntryExitData[] = [
  { path: '/', visitors: 12400, type: 'entry' },
  { path: '/page', visitors: 8900, type: 'entry' },
  { path: '/docs', visitors: 6700, type: 'entry' },
  { path: '/pricing', visitors: 5400, type: 'entry' },
  { path: '/blog', visitors: 3200, type: 'entry' },
]

const exitPages: EntryExitData[] = [
  { path: '/page/subpage', visitors: 9800, type: 'exit' },
  { path: '/contact', visitors: 7200, type: 'exit' },
  { path: '/blog', visitors: 5600, type: 'exit' },
  { path: '/docs', visitors: 4100, type: 'exit' },
  { path: '/about', visitors: 3400, type: 'exit' },
]

const peakHours: HourData[] = [
  { hour: 0, visitors: 1200, label: '12 AM' },
  { hour: 1, visitors: 800, label: '1 AM' },
  { hour: 2, visitors: 600, label: '2 AM' },
  { hour: 3, visitors: 500, label: '3 AM' },
  { hour: 4, visitors: 400, label: '4 AM' },
  { hour: 5, visitors: 500, label: '5 AM' },
  { hour: 6, visitors: 800, label: '6 AM' },
  { hour: 7, visitors: 1200, label: '7 AM' },
  { hour: 8, visitors: 2100, label: '8 AM' },
  { hour: 9, visitors: 3400, label: '9 AM' },
  { hour: 10, visitors: 4200, label: '10 AM' },
  { hour: 11, visitors: 4800, label: '11 AM' },
  { hour: 12, visitors: 5200, label: '12 PM' },
  { hour: 13, visitors: 5100, label: '1 PM' },
  { hour: 14, visitors: 4900, label: '2 PM' },
  { hour: 15, visitors: 4600, label: '3 PM' },
  { hour: 16, visitors: 4400, label: '4 PM' },
  { hour: 17, visitors: 3800, label: '5 PM' },
  { hour: 18, visitors: 3200, label: '6 PM' },
  { hour: 19, visitors: 2800, label: '7 PM' },
  { hour: 20, visitors: 2400, label: '8 PM' },
  { hour: 21, visitors: 2000, label: '9 PM' },
  { hour: 22, visitors: 1600, label: '10 PM' },
  { hour: 23, visitors: 1400, label: '11 PM' },
]
const peakHoursChartAxisMax = getChartSeriesMax(
  peakHours.map((point) => ({ value: point.visitors })),
)
const peakHoursYAxisTickFormatter = createCompactCountAxisTickFormatter(
  peakHoursChartAxisMax,
)

const visitorTypes = {
  new: 89200,
  returning: 54800,
}

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
      <div className="rounded-lg border border-border bg-popover px-3 py-2">
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
  const t = useT()
  // For bounce rate, negative change is good (lower bounce = better)
  const isPositive =
    metric.id === 'bounceRate' ? metric.change < 0 : metric.change > 0
  const TrendIcon = isPositive ? TrendingUp : TrendingDown

  return (
    <button
      onClick={onClick}
      className={cn(
        'relative flex min-w-[140px] flex-col gap-0.5 px-3 py-2.5 text-start transition-colors',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        isActive
          ? 'text-foreground'
          : 'text-muted-foreground hover:text-foreground/80',
      )}
    >
      <div className="flex items-baseline gap-2">
        <span
          className={cn(
            'text-[18px] font-semibold tracking-tight tabular-nums',
            isActive ? 'text-foreground' : 'text-muted-foreground',
          )}
        >
          {formatMetricValue(metric)}
        </span>
        <span
          className={cn(
            'flex items-center gap-0.5 text-[10px] font-semibold tabular-nums',
            isPositive
              ? isActive
                ? 'text-emerald-500'
                : 'text-emerald-500/60'
              : isActive
                ? 'text-red-500'
                : 'text-red-500/60',
          )}
        >
          <TrendIcon className="h-2.5 w-2.5" />
          {Math.abs(metric.change)}%
        </span>
      </div>
      <span
        className={cn(
          'text-[11px] font-medium',
          isActive ? 'text-muted-foreground' : 'text-muted-foreground/70',
        )}
      >
        {t(metric.label)}
      </span>
      {isActive && (
        <div className="absolute bottom-0 start-0 end-0 h-[2px] bg-foreground" />
      )}
    </button>
  )
}

// World Map Component using mapcn
// Internal component that uses map context
function MapContent({ data }: { data: LocationData[] }) {
  const t = useT()
  const { map, isLoaded } = useMap()

  // Resize map when it becomes visible or when map loads
  React.useEffect(() => {
    if (!map || !isLoaded) return

    const resizeMap = () => {
      // Get the map container from the map instance
      const container = map.getContainer()
      if (container) {
        const rect = container.getBoundingClientRect()
        const isVisible = container.offsetParent !== null
        if (isVisible && rect.width > 0 && rect.height > 0) {
          requestAnimationFrame(() => {
            map.resize()
          })
        }
      }
    }

    // Resize when map loads
    const handleLoad = () => {
      setTimeout(resizeMap, 100)
    }
    map.on('load', handleLoad)

    // Also check when tab becomes visible (using IntersectionObserver)
    const container = map.getContainer()
    if (container) {
      const observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              setTimeout(() => {
                resizeMap()
              }, 100)
            }
          })
        },
        { threshold: 0.1 },
      )

      observer.observe(container)

      // Initial resize after a delay to ensure tab is visible
      const initialTimeout = setTimeout(() => {
        resizeMap()
      }, 200)

      return () => {
        clearTimeout(initialTimeout)
        map.off('load', handleLoad)
        observer.disconnect()
      }
    }

    return () => {
      map.off('load', handleLoad)
    }
  }, [map, isLoaded])

  // Find max visitors for marker sizing
  const maxVisitors = Math.max(...data.map((d) => d.visitors))

  // Calculate size ranges for legend
  const minSize = 8
  const maxSize = 20
  const minVisitors = Math.min(...data.map((d) => d.visitors))
  const maxVisitorsForLegend = maxVisitors

  return (
    <>
      <MapControls position="top-right" showZoom={true} />
      {data.map((location) => {
        const coords = COUNTRY_COORDINATES[location.code]
        if (!coords) return null

        const [longitude, latitude] = coords
        const size = Math.max(
          minSize,
          Math.min(maxSize, (location.visitors / maxVisitors) * maxSize),
        )

        return (
          <MapMarker
            key={location.code}
            longitude={longitude}
            latitude={latitude}
          >
            <MarkerContent>
              <div
                className="relative flex cursor-pointer items-center justify-center rounded-full border-2 border-white transition-transform hover:scale-110"
                style={{
                  width: `${size}px`,
                  height: `${size}px`,
                  backgroundColor: 'var(--chart-brand)',
                }}
              />
            </MarkerContent>
            <MarkerPopup closeButton={false}>
              <div className="rounded-lg border border-border bg-popover px-3 py-2">
                <div className="flex items-center gap-2">
                  <div className="flex h-5 w-5 shrink-0 items-center justify-center overflow-hidden rounded border border-border/50 bg-background">
                    <img
                      src={`${sdk.forConsole.client.config.endpoint}/avatars/flags/${location.code.toLowerCase()}?width=40&height=40&quality=100&project=console`}
                      alt={`${location.country} flag`}
                      className="h-full w-full object-cover"
                      role="img"
                      aria-label={`${location.country} flag`}
                    />
                  </div>
                  <span className="text-[12px] font-medium">
                    {location.country}
                  </span>
                </div>
                <div className="mt-1 text-[11px] text-muted-foreground">
                  {formatNumber(location.visitors)} {t('visitors')}
                </div>
              </div>
            </MarkerPopup>
          </MapMarker>
        )
      })}

      {/* Legend */}
      <div className="absolute bottom-8 start-4 rounded-lg border border-border bg-background/95 px-3 py-2 backdrop-blur-sm">
        <div className="mb-2 text-[11px] font-semibold text-foreground">
          {t('Visitors')}
        </div>
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-1.5">
            <div
              className="rounded-full border-2 border-white"
              style={{
                width: `${minSize}px`,
                height: `${minSize}px`,
                backgroundColor: 'var(--chart-brand)',
              }}
            />
            <span className="text-[10px] font-medium text-muted-foreground tabular-nums">
              {formatNumber(minVisitors)}
            </span>
          </div>
          <div className="h-px w-4 bg-border" />
          <div className="flex items-center gap-1.5">
            <div
              className="rounded-full border-2 border-white"
              style={{
                width: `${maxSize}px`,
                height: `${maxSize}px`,
                backgroundColor: 'var(--chart-brand)',
              }}
            />
            <span className="text-[10px] font-medium text-muted-foreground tabular-nums">
              {formatNumber(maxVisitorsForLegend)}
            </span>
          </div>
        </div>
      </div>
    </>
  )
}

function WorldMapChart({ data }: { data: LocationData[] }) {
  // Calculate center point (average of all locations)
  const center: [number, number] = React.useMemo(() => {
    const coords = data
      .map((item) => COUNTRY_COORDINATES[item.code])
      .filter(Boolean) as [number, number][]

    if (coords.length === 0) return [0, 20]

    const avgLng = coords.reduce((sum, [lng]) => sum + lng, 0) / coords.length
    const avgLat = coords.reduce((sum, [, lat]) => sum + lat, 0) / coords.length

    return [avgLng, avgLat]
  }, [data])

  return (
    <div className="relative h-full w-full p-2 flex flex-col">
      <div className="flex-1 w-full overflow-hidden rounded-lg min-h-[400px]">
        <Map center={center} zoom={2}>
          <MapContent data={data} />
        </Map>
      </div>
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
  websiteId,
  websiteName = 'Main Marketing Site',
  onBack,
}: WebsiteAnalyticsDetailProps) {
  const t = useT()
  const [activeTab, setActiveTab] = useState('analytics')
  const [activeMetric, setActiveMetric] = useState('unique')
  const [dateRange, setDateRange] = useState<DateRange | undefined>({
    from: startOfDay(subDays(new Date(), 29)),
    to: endOfDay(new Date()),
  })
  const [comparisonType, setComparisonType] = useState<ComparisonType>('none')
  const [, setComparisonRange] = useState<DateRange | undefined>()
  const [locationView, setLocationView] = useState<
    'map' | 'countries' | 'regions' | 'cities'
  >('countries')
  const [sourcesView, setSourcesView] = useState<
    'channels' | 'sources' | 'campaigns'
  >('sources')
  const [pagesView, setPagesView] = useState<'top' | 'entry' | 'exit'>('top')
  const [techView, setTechView] = useState<'browsers' | 'os' | 'devices'>(
    'browsers',
  )

  // Chart dimension checks
  const chartContainerRef = useRef<HTMLDivElement>(null)
  const [chartHasDimensions, setChartHasDimensions] = useState(false)
  const peakHoursChartRef = useRef<HTMLDivElement>(null)
  const [peakHoursChartHasDimensions, setPeakHoursChartHasDimensions] =
    useState(false)

  // Check chart container dimensions
  useEffect(() => {
    if (!chartContainerRef.current) return

    const checkDimensions = () => {
      if (chartContainerRef.current) {
        const rect = chartContainerRef.current.getBoundingClientRect()
        const computedStyle = window.getComputedStyle(chartContainerRef.current)
        const hasSize =
          computedStyle.display !== 'none' &&
          computedStyle.visibility !== 'hidden' &&
          chartContainerRef.current.offsetParent !== null &&
          rect.width > 0 &&
          rect.height > 0
        setChartHasDimensions(hasSize)
      }
    }

    // Small delay to ensure container is in DOM
    const timeout = setTimeout(() => {
      checkDimensions()
    }, 50)

    // Use ResizeObserver to watch for dimension changes
    const observer = new ResizeObserver(() => {
      checkDimensions()
    })

    if (chartContainerRef.current) {
      observer.observe(chartContainerRef.current)
    }

    return () => {
      clearTimeout(timeout)
      observer.disconnect()
    }
  }, [])

  // Check peak hours chart container dimensions
  useEffect(() => {
    if (!peakHoursChartRef.current) return

    const checkDimensions = () => {
      if (peakHoursChartRef.current) {
        const rect = peakHoursChartRef.current.getBoundingClientRect()
        const computedStyle = window.getComputedStyle(peakHoursChartRef.current)
        const hasSize =
          computedStyle.display !== 'none' &&
          computedStyle.visibility !== 'hidden' &&
          peakHoursChartRef.current.offsetParent !== null &&
          rect.width > 0 &&
          rect.height > 0
        setPeakHoursChartHasDimensions(hasSize)
      }
    }

    // Small delay to ensure container is in DOM
    const timeout = setTimeout(() => {
      checkDimensions()
    }, 50)

    // Use ResizeObserver to watch for dimension changes
    const observer = new ResizeObserver(() => {
      checkDimensions()
    })

    if (peakHoursChartRef.current) {
      observer.observe(peakHoursChartRef.current)
    }

    return () => {
      clearTimeout(timeout)
      observer.disconnect()
    }
  }, [])

  // Show more states for lists with many items
  const [showAllCountries, setShowAllCountries] = useState(false)
  const [showAllRegions, setShowAllRegions] = useState(false)
  const [showAllCities, setShowAllCities] = useState(false)
  const [showAllTopPages, setShowAllTopPages] = useState(false)
  const [showAllEntryPages, setShowAllEntryPages] = useState(false)
  const [showAllExitPages, setShowAllExitPages] = useState(false)
  const [showAllBrowsers, setShowAllBrowsers] = useState(false)
  const [showAllOS, setShowAllOS] = useState(false)
  const [showAllDevices, setShowAllDevices] = useState(false)

  // Reset show more states when switching tabs
  React.useEffect(() => {
    setShowAllCountries(false)
    setShowAllRegions(false)
    setShowAllCities(false)
  }, [locationView])

  React.useEffect(() => {
    setShowAllTopPages(false)
    setShowAllEntryPages(false)
    setShowAllExitPages(false)
  }, [pagesView])

  React.useEffect(() => {
    setShowAllBrowsers(false)
    setShowAllOS(false)
    setShowAllDevices(false)
  }, [techView])

  const tabs: Tab[] = [
    { id: 'analytics', label: t('Analytics') },
    { id: 'settings', label: t('Settings') },
  ]

  const maxLocationVisitors = Math.max(...locationData.map((l) => l.visitors))
  const maxPageVisitors = Math.max(...topPages.map((p) => p.visitors))
  const maxSourceVisitors = Math.max(...topSources.map((s) => s.visitors))
  const maxBrowserVisitors = Math.max(...browsers.map((b) => b.visitors))

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex min-w-0 items-center gap-2">
            {onBack && (
              <Button
                variant="ghost"
                size="sm"
                className="h-7 w-7 p-0"
                onClick={onBack}
              >
                <ArrowLeft className="h-4 w-4" />
              </Button>
            )}
            <span className="truncate">{websiteName}</span>
            {websiteId ? (
              <CopyableId id={websiteId} size="xs" className="shrink-0" />
            ) : null}
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        onTabChange={setActiveTab}
        showFilters={false}
        fullWidthBorder
      />

      <div className="flex-1 flex flex-col">
        <div className={cn('mx-auto w-full max-w-7xl flex-1')}>
          {activeTab === 'analytics' && (
            <div className="px-4 py-4 sm:px-6">
              {/* Active visitors and date range */}
              <div className="mb-3 flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                  <span className="text-[12px] font-medium text-muted-foreground">
                    30 {t('active visitors')}
                  </span>
                </div>
                <div className="flex items-center gap-2">
                  <ComparisonSelector
                    dateRange={dateRange}
                    comparisonType={comparisonType}
                    onComparisonTypeChange={setComparisonType}
                    onComparisonRangeChange={setComparisonRange}
                  />
                  <DateRangePicker
                    dateRange={dateRange}
                    onDateRangeChange={setDateRange}
                  />
                </div>
              </div>

              {/* Metrics and Chart Card */}
              <div className="rounded-lg border border-border bg-card">
                {/* Metric Tabs */}
                <div className="border-b border-border px-2">
                  <div className="flex overflow-x-auto overflow-y-hidden">
                    {visitorMetrics.map((metric, index) => (
                      <div key={metric.id} className="flex shrink-0">
                        {index > 0 && <div className="my-2 w-px bg-border" />}
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
                <div className="p-4">
                  <div
                    ref={chartContainerRef}
                    className="h-[280px] w-full text-muted-foreground"
                  >
                    {chartHasDimensions && typeof window !== 'undefined' ? (
                      <ResponsiveContainer
                        width="100%"
                        height="100%"
                        minWidth={0}
                        minHeight={0}
                      >
                        <AreaChart
                          data={chartData}
                          margin={USAGE_CHART_MARGIN}
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
                                stopColor="var(--chart-brand)"
                                stopOpacity={0.15}
                              />
                              <stop
                                offset="100%"
                                stopColor="var(--chart-brand)"
                                stopOpacity={0}
                              />
                            </linearGradient>
                          </defs>
                          <SeriesChartXAxis
                            pointCount={chartData.length}
                            tick={{
                              fill: 'currentColor',
                              fontSize: 12,
                            }}
                          />
                          <YAxis
                            axisLine={false}
                            tickLine={false}
                            tick={{
                              fill: 'currentColor',
                              fontSize: 12,
                            }}
                            tickFormatter={visitorYAxisTickFormatter}
                            dx={-5}
                            width={USAGE_CHART_Y_AXIS_WIDTH}
                          />
                          <Tooltip content={<CustomTooltip />} cursor={false} />
                          <Area
                            type="monotone"
                            dataKey="visitors"
                            stroke="var(--chart-brand)"
                            strokeWidth={2}
                            fill="url(#visitorGradient)"
                            dot={false}
                            activeDot={{
                              r: 5,
                              fill: 'var(--chart-brand)',
                              stroke: '#fff',
                              strokeWidth: 2,
                            }}
                          />
                        </AreaChart>
                      </ResponsiveContainer>
                    ) : null}
                  </div>
                </div>
              </div>

              {/* Two Column Layout - Plausible Style */}
              <div className="mt-6 grid gap-4 lg:grid-cols-2">
                {/* Sources Card with Tabs */}
                <div className="rounded-lg border border-border bg-card">
                  <div className="border-b border-border px-4 py-2.5">
                    <div className="flex items-center justify-between">
                      <h3 className="text-[13px] font-semibold text-foreground">
                        {t('Traffic sources')}
                      </h3>
                      <Tabs
                        value={sourcesView}
                        onValueChange={(v) =>
                          setSourcesView(
                            v as 'channels' | 'sources' | 'campaigns',
                          )
                        }
                      >
                        <TabsList className="h-7">
                          <TabsTrigger
                            value="channels"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Channels')}
                          </TabsTrigger>
                          <TabsTrigger
                            value="sources"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Sources')}
                          </TabsTrigger>
                          <TabsTrigger
                            value="campaigns"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Campaigns')}
                          </TabsTrigger>
                        </TabsList>
                      </Tabs>
                    </div>
                  </div>
                  <div className="p-4">
                    <Tabs
                      value={sourcesView}
                      onValueChange={(v) =>
                        setSourcesView(
                          v as 'channels' | 'sources' | 'campaigns',
                        )
                      }
                    >
                      <TabsContent value="channels" className="mt-0">
                        <div className="space-y-0.5">
                          {channels.map((channel) => {
                            const maxChannelVisitors = Math.max(
                              ...channels.map((c) => c.visitors),
                            )
                            const percentage = Math.round(
                              (channel.visitors / maxChannelVisitors) * 100,
                            )
                            const share = Math.round(
                              (channel.visitors /
                                channels.reduce(
                                  (sum, c) => sum + c.visitors,
                                  0,
                                )) *
                                100,
                            )
                            return (
                              <div
                                key={channel.name}
                                className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                              >
                                <div
                                  className="absolute inset-y-0 start-0 rounded-md transition-all group-hover:opacity-80"
                                  style={{
                                    width: `${percentage}%`,
                                    backgroundColor: channel.color,
                                    opacity: 0.15,
                                  }}
                                />
                                <div className="relative flex flex-1 items-center gap-2">
                                  <div
                                    className="h-2.5 w-2.5 rounded-full"
                                    style={{ backgroundColor: channel.color }}
                                  />
                                  <span className="flex-1 truncate text-[12px] font-medium text-foreground">
                                    {t(channel.name)}
                                  </span>
                                  <div className="flex items-center gap-3">
                                    <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                                      {share}%
                                    </span>
                                    <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                      {formatNumber(channel.visitors)}
                                    </span>
                                  </div>
                                </div>
                              </div>
                            )
                          })}
                        </div>
                      </TabsContent>
                      <TabsContent value="sources" className="mt-0">
                        <div className="space-y-0.5">
                          {topSources.map((source) => {
                            const percentage = Math.round(
                              (source.visitors / maxSourceVisitors) * 100,
                            )
                            const share = Math.round(
                              (source.visitors /
                                topSources.reduce(
                                  (sum, s) => sum + s.visitors,
                                  0,
                                )) *
                                100,
                            )
                            return (
                              <div
                                key={source.name}
                                className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                              >
                                <div
                                  className="absolute inset-y-0 start-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
                                  style={{ width: `${percentage}%` }}
                                />
                                <div className="relative flex flex-1 items-center gap-2">
                                  <span className="flex h-4 w-4 items-center justify-center text-[11px] text-muted-foreground">
                                    {source.icon}
                                  </span>
                                  <span className="flex-1 truncate text-[12px] font-medium text-foreground">
                                    {source.name}
                                  </span>
                                  <div className="flex items-center gap-3">
                                    <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                                      {share}%
                                    </span>
                                    <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                      {formatNumber(source.visitors)}
                                    </span>
                                  </div>
                                </div>
                              </div>
                            )
                          })}
                        </div>
                      </TabsContent>
                      <TabsContent value="campaigns" className="mt-0">
                        <div className="space-y-0.5">
                          {campaigns.map((campaign, index) => {
                            const maxCampaignVisitors = Math.max(
                              ...campaigns.map((c) => c.visitors),
                            )
                            const percentage = Math.round(
                              (campaign.visitors / maxCampaignVisitors) * 100,
                            )
                            const share = Math.round(
                              (campaign.visitors /
                                campaigns.reduce(
                                  (sum, c) => sum + c.visitors,
                                  0,
                                )) *
                                100,
                            )
                            return (
                              <div
                                key={campaign.name}
                                className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                              >
                                <div
                                  className="absolute inset-y-0 start-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
                                  style={{ width: `${percentage}%` }}
                                />
                                <div className="relative flex flex-1 items-center gap-2">
                                  <span className="font-mono text-[11px] text-muted-foreground">
                                    {index + 1}
                                  </span>
                                  <span className="flex-1 truncate text-[12px] font-medium text-foreground">
                                    {campaign.name}
                                  </span>
                                  <div className="flex items-center gap-3">
                                    <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                                      {share}%
                                    </span>
                                    <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                      {formatNumber(campaign.visitors)}
                                    </span>
                                  </div>
                                </div>
                              </div>
                            )
                          })}
                        </div>
                      </TabsContent>
                    </Tabs>
                  </div>
                </div>

                {/* Top Pages Card with Tabs */}
                <div className="rounded-lg border border-border bg-card">
                  <div className="border-b border-border px-4 py-2.5">
                    <div className="flex items-center justify-between">
                      <h3 className="text-[13px] font-semibold text-foreground">
                        {t('Pages')}
                      </h3>
                      <Tabs
                        value={pagesView}
                        onValueChange={(v) =>
                          setPagesView(v as 'top' | 'entry' | 'exit')
                        }
                      >
                        <TabsList className="h-7">
                          <TabsTrigger
                            value="top"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Top Pages')}
                          </TabsTrigger>
                          <TabsTrigger
                            value="entry"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Entry Pages')}
                          </TabsTrigger>
                          <TabsTrigger
                            value="exit"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Exit Pages')}
                          </TabsTrigger>
                        </TabsList>
                      </Tabs>
                    </div>
                  </div>
                  <div className="p-4">
                    <Tabs
                      value={pagesView}
                      onValueChange={(v) =>
                        setPagesView(v as 'top' | 'entry' | 'exit')
                      }
                    >
                      <TabsContent value="top" className="mt-0">
                        <div className="space-y-0.5">
                          {(showAllTopPages
                            ? topPages
                            : topPages.slice(0, 15)
                          ).map((page, index) => {
                            const percentage = Math.round(
                              (page.visitors / maxPageVisitors) * 100,
                            )
                            const share = Math.round(
                              (page.visitors /
                                topPages.reduce(
                                  (sum, p) => sum + p.visitors,
                                  0,
                                )) *
                                100,
                            )
                            return (
                              <div
                                key={page.path}
                                className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                              >
                                <div
                                  className="absolute inset-y-0 start-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
                                  style={{ width: `${percentage}%` }}
                                />
                                <div className="relative flex flex-1 items-center gap-2">
                                  <span className="font-mono text-[11px] text-muted-foreground">
                                    {index + 1}
                                  </span>
                                  <span className="flex-1 truncate font-mono text-[12px] font-medium text-foreground">
                                    {page.path}
                                  </span>
                                  <div className="flex items-center gap-3">
                                    <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                                      {share}%
                                    </span>
                                    <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                      {formatNumber(page.visitors)}
                                    </span>
                                  </div>
                                </div>
                              </div>
                            )
                          })}
                        </div>
                        {topPages.length > 15 && (
                          <div className="mt-3 flex justify-center">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() =>
                                setShowAllTopPages(!showAllTopPages)
                              }
                              className="h-7 text-[11px]"
                            >
                              {showAllTopPages ? (
                                <>
                                  {t('Show less')}
                                  <ChevronDown className="ms-1 h-3 w-3 rotate-180" />
                                </>
                              ) : (
                                <>
                                  {t('Show more')} ({topPages.length - 15} {t('more')})
                                  <ChevronDown className="ms-1 h-3 w-3" />
                                </>
                              )}
                            </Button>
                          </div>
                        )}
                      </TabsContent>
                      <TabsContent value="entry" className="mt-0">
                        <div className="space-y-0.5">
                          {(showAllEntryPages
                            ? entryPages
                            : entryPages.slice(0, 15)
                          ).map((page, index) => {
                            const maxEntryVisitors = Math.max(
                              ...entryPages.map((p) => p.visitors),
                            )
                            const percentage = Math.round(
                              (page.visitors / maxEntryVisitors) * 100,
                            )
                            const share = Math.round(
                              (page.visitors /
                                entryPages.reduce(
                                  (sum, p) => sum + p.visitors,
                                  0,
                                )) *
                                100,
                            )
                            return (
                              <div
                                key={page.path}
                                className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                              >
                                <div
                                  className="absolute inset-y-0 start-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
                                  style={{ width: `${percentage}%` }}
                                />
                                <div className="relative flex flex-1 items-center gap-2">
                                  <span className="font-mono text-[11px] text-muted-foreground">
                                    {index + 1}
                                  </span>
                                  <span className="flex-1 truncate font-mono text-[12px] font-medium text-foreground">
                                    {page.path}
                                  </span>
                                  <div className="flex items-center gap-3">
                                    <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                                      {share}%
                                    </span>
                                    <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                      {formatNumber(page.visitors)}
                                    </span>
                                  </div>
                                </div>
                              </div>
                            )
                          })}
                        </div>
                        {entryPages.length > 15 && (
                          <div className="mt-3 flex justify-center">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() =>
                                setShowAllEntryPages(!showAllEntryPages)
                              }
                              className="h-7 text-[11px]"
                            >
                              {showAllEntryPages ? (
                                <>
                                  {t('Show less')}
                                  <ChevronDown className="ms-1 h-3 w-3 rotate-180" />
                                </>
                              ) : (
                                <>
                                  {t('Show more')} ({entryPages.length - 15} {t('more')})
                                  <ChevronDown className="ms-1 h-3 w-3" />
                                </>
                              )}
                            </Button>
                          </div>
                        )}
                      </TabsContent>
                      <TabsContent value="exit" className="mt-0">
                        <div className="space-y-0.5">
                          {(showAllExitPages
                            ? exitPages
                            : exitPages.slice(0, 15)
                          ).map((page, index) => {
                            const maxExitVisitors = Math.max(
                              ...exitPages.map((p) => p.visitors),
                            )
                            const percentage = Math.round(
                              (page.visitors / maxExitVisitors) * 100,
                            )
                            const share = Math.round(
                              (page.visitors /
                                exitPages.reduce(
                                  (sum, p) => sum + p.visitors,
                                  0,
                                )) *
                                100,
                            )
                            return (
                              <div
                                key={page.path}
                                className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                              >
                                <div
                                  className="absolute inset-y-0 start-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
                                  style={{ width: `${percentage}%` }}
                                />
                                <div className="relative flex flex-1 items-center gap-2">
                                  <span className="font-mono text-[11px] text-muted-foreground">
                                    {index + 1}
                                  </span>
                                  <span className="flex-1 truncate font-mono text-[12px] font-medium text-foreground">
                                    {page.path}
                                  </span>
                                  <div className="flex items-center gap-3">
                                    <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                                      {share}%
                                    </span>
                                    <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                      {formatNumber(page.visitors)}
                                    </span>
                                  </div>
                                </div>
                              </div>
                            )
                          })}
                        </div>
                        {exitPages.length > 15 && (
                          <div className="mt-3 flex justify-center">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() =>
                                setShowAllExitPages(!showAllExitPages)
                              }
                              className="h-7 text-[11px]"
                            >
                              {showAllExitPages ? (
                                <>
                                  {t('Show less')}
                                  <ChevronDown className="ms-1 h-3 w-3 rotate-180" />
                                </>
                              ) : (
                                <>
                                  {t('Show more')} ({exitPages.length - 15} {t('more')})
                                  <ChevronDown className="ms-1 h-3 w-3" />
                                </>
                              )}
                            </Button>
                          </div>
                        )}
                      </TabsContent>
                    </Tabs>
                  </div>
                </div>

                {/* Countries Card with Tabs */}
                <div className="flex flex-col rounded-lg border border-border bg-card">
                  <div className="border-b border-border px-4 py-2.5">
                    <div className="flex items-center justify-between">
                      <h3 className="text-[13px] font-semibold text-foreground">
                        {t('Locations')}
                      </h3>
                      <Tabs
                        value={locationView}
                        onValueChange={(v) =>
                          setLocationView(
                            v as 'map' | 'countries' | 'regions' | 'cities',
                          )
                        }
                      >
                        <TabsList className="h-7">
                          <TabsTrigger
                            value="map"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Map')}
                          </TabsTrigger>
                          <TabsTrigger
                            value="countries"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Countries')}
                          </TabsTrigger>
                          <TabsTrigger
                            value="regions"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Regions')}
                          </TabsTrigger>
                          <TabsTrigger
                            value="cities"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Cities')}
                          </TabsTrigger>
                        </TabsList>
                      </Tabs>
                    </div>
                  </div>
                  <div className="flex-1 min-h-[400px] w-full overflow-hidden flex flex-col">
                    <Tabs
                      value={locationView}
                      onValueChange={(v) =>
                        setLocationView(
                          v as 'map' | 'countries' | 'regions' | 'cities',
                        )
                      }
                      className="flex flex-col flex-1"
                    >
                      <TabsContent
                        value="map"
                        className="mt-0 flex-1 min-h-[400px]"
                        forceMount={false}
                      >
                        {locationView === 'map' ? (
                          <WorldMapChart data={locationData} />
                        ) : null}
                      </TabsContent>
                      <TabsContent
                        value="countries"
                        className="mt-0 flex-1 min-h-[400px]"
                      >
                        <div className="h-full overflow-y-auto p-4">
                          <div className="space-y-0.5">
                            {(showAllCountries
                              ? locationData
                              : locationData.slice(0, 15)
                            ).map((location) => {
                              const percentage = Math.round(
                                (location.visitors / maxLocationVisitors) * 100,
                              )
                              return (
                                <div
                                  key={location.country}
                                  className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                                >
                                  <div
                                    className="absolute inset-y-0 start-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
                                    style={{ width: `${percentage}%` }}
                                  />
                                  <div className="relative flex flex-1 items-center gap-2">
                                    <div className="flex h-5 w-5 shrink-0 items-center justify-center overflow-hidden rounded border border-border/50 bg-background">
                                      <img
                                        src={`${sdk.forConsole.client.config.endpoint}/avatars/flags/${location.code.toLowerCase()}?width=40&height=40&quality=100&project=console`}
                                        alt={`${location.country} flag`}
                                        className="h-full w-full object-cover"
                                        role="img"
                                        aria-label={`${location.country} flag`}
                                      />
                                    </div>
                                    <span className="flex-1 truncate text-[12px] font-medium text-foreground">
                                      {location.country}
                                    </span>
                                    <div className="flex items-center gap-3">
                                      <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                                        {formatNumber(location.uniqueVisitors)}{' '}
                                        {t('unique')}
                                      </span>
                                      <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                        {formatNumber(location.visitors)}
                                      </span>
                                    </div>
                                  </div>
                                </div>
                              )
                            })}
                          </div>
                          {locationData.length > 15 && (
                            <div className="mt-3 flex justify-center">
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                  setShowAllCountries(!showAllCountries)
                                }
                                className="h-7 text-[11px]"
                              >
                                {showAllCountries ? (
                                  <>
                                    {t('Show less')}
                                    <ChevronDown className="ms-1 h-3 w-3 rotate-180" />
                                  </>
                                ) : (
                                  <>
                                    {t('Show more')} ({locationData.length - 15} {t('more')})
                                    <ChevronDown className="ms-1 h-3 w-3" />
                                  </>
                                )}
                              </Button>
                            </div>
                          )}
                        </div>
                      </TabsContent>
                      <TabsContent
                        value="regions"
                        className="mt-0 flex-1 min-h-[400px]"
                      >
                        <div className="h-full overflow-y-auto p-4">
                          <div className="space-y-0.5">
                            {(showAllRegions
                              ? regions
                              : regions.slice(0, 15)
                            ).map((region) => {
                              const maxRegionVisitors = Math.max(
                                ...regions.map((r) => r.visitors),
                              )
                              const percentage = Math.round(
                                (region.visitors / maxRegionVisitors) * 100,
                              )
                              return (
                                <div
                                  key={`${region.region}-${region.country}`}
                                  className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                                >
                                  <div
                                    className="absolute inset-y-0 start-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
                                    style={{ width: `${percentage}%` }}
                                  />
                                  <div className="relative flex flex-1 items-center gap-2">
                                    <div className="flex h-5 w-5 shrink-0 items-center justify-center overflow-hidden rounded border border-border/50 bg-background">
                                      <img
                                        src={`${sdk.forConsole.client.config.endpoint}/avatars/flags/${region.code.toLowerCase()}?width=40&height=40&quality=100&project=console`}
                                        alt={`${region.country} flag`}
                                        className="h-full w-full object-cover"
                                        role="img"
                                        aria-label={`${region.country} flag`}
                                      />
                                    </div>
                                    <div className="flex flex-1 flex-col">
                                      <span className="text-[12px] font-medium text-foreground">
                                        {region.region}
                                      </span>
                                      <span className="text-[11px] text-muted-foreground">
                                        {region.country}
                                      </span>
                                    </div>
                                    <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                      {formatNumber(region.visitors)}
                                    </span>
                                  </div>
                                </div>
                              )
                            })}
                          </div>
                          {regions.length > 15 && (
                            <div className="mt-3 flex justify-center">
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                  setShowAllRegions(!showAllRegions)
                                }
                                className="h-7 text-[11px]"
                              >
                                {showAllRegions ? (
                                  <>
                                    {t('Show less')}
                                    <ChevronDown className="ms-1 h-3 w-3 rotate-180" />
                                  </>
                                ) : (
                                  <>
                                    {t('Show more')} ({regions.length - 15} {t('more')})
                                    <ChevronDown className="ms-1 h-3 w-3" />
                                  </>
                                )}
                              </Button>
                            </div>
                          )}
                        </div>
                      </TabsContent>
                      <TabsContent
                        value="cities"
                        className="mt-0 flex-1 min-h-[400px]"
                      >
                        <div className="h-full overflow-y-auto p-4">
                          <div className="space-y-0.5">
                            {(showAllCities ? cities : cities.slice(0, 15)).map(
                              (city) => {
                                const maxCityVisitors = Math.max(
                                  ...cities.map((c) => c.visitors),
                                )
                                const percentage = Math.round(
                                  (city.visitors / maxCityVisitors) * 100,
                                )
                                return (
                                  <div
                                    key={`${city.city}-${city.country}`}
                                    className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                                  >
                                    <div
                                      className="absolute inset-y-0 start-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
                                      style={{ width: `${percentage}%` }}
                                    />
                                    <div className="relative flex flex-1 items-center gap-2">
                                      <div className="flex h-5 w-5 shrink-0 items-center justify-center overflow-hidden rounded border border-border/50 bg-background">
                                        <img
                                          src={`${sdk.forConsole.client.config.endpoint}/avatars/flags/${city.code.toLowerCase()}?width=40&height=40&quality=100&project=console`}
                                          alt={`${city.country} flag`}
                                          className="h-full w-full object-cover"
                                          role="img"
                                          aria-label={`${city.country} flag`}
                                        />
                                      </div>
                                      <div className="flex flex-1 flex-col">
                                        <span className="text-[12px] font-medium text-foreground">
                                          {city.city}
                                        </span>
                                        <span className="text-[11px] text-muted-foreground">
                                          {city.country}
                                        </span>
                                      </div>
                                      <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                        {formatNumber(city.visitors)}
                                      </span>
                                    </div>
                                  </div>
                                )
                              },
                            )}
                          </div>
                          {cities.length > 15 && (
                            <div className="mt-3 flex justify-center">
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setShowAllCities(!showAllCities)}
                                className="h-7 text-[11px]"
                              >
                                {showAllCities ? (
                                  <>
                                    {t('Show less')}
                                    <ChevronDown className="ms-1 h-3 w-3 rotate-180" />
                                  </>
                                ) : (
                                  <>
                                    {t('Show more')} ({cities.length - 15} {t('more')})
                                    <ChevronDown className="ms-1 h-3 w-3" />
                                  </>
                                )}
                              </Button>
                            </div>
                          )}
                        </div>
                      </TabsContent>
                    </Tabs>
                  </div>
                </div>

                {/* Browsers Card with Tabs */}
                <div className="rounded-lg border border-border bg-card">
                  <div className="border-b border-border px-4 py-2.5">
                    <div className="flex items-center justify-between">
                      <h3 className="text-[13px] font-semibold text-foreground">
                        {t('Technology')}
                      </h3>
                      <Tabs
                        value={techView}
                        onValueChange={(v) =>
                          setTechView(v as 'browsers' | 'os' | 'devices')
                        }
                      >
                        <TabsList className="h-7">
                          <TabsTrigger
                            value="browsers"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Browsers')}
                          </TabsTrigger>
                          <TabsTrigger
                            value="os"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Operating Systems')}
                          </TabsTrigger>
                          <TabsTrigger
                            value="devices"
                            className="h-5 px-2.5 text-[11px]"
                          >
                            {t('Devices')}
                          </TabsTrigger>
                        </TabsList>
                      </Tabs>
                    </div>
                  </div>
                  <div className="p-4">
                    <Tabs
                      value={techView}
                      onValueChange={(v) =>
                        setTechView(v as 'browsers' | 'os' | 'devices')
                      }
                    >
                      <TabsContent value="browsers" className="mt-0">
                        <div className="space-y-0.5">
                          {(showAllBrowsers
                            ? browsers
                            : browsers.slice(0, 15)
                          ).map((browser) => {
                            const percentage = Math.round(
                              (browser.visitors / maxBrowserVisitors) * 100,
                            )
                            const share = Math.round(
                              (browser.visitors /
                                browsers.reduce(
                                  (sum, b) => sum + b.visitors,
                                  0,
                                )) *
                                100,
                            )
                            return (
                              <div
                                key={browser.name}
                                className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                              >
                                <div
                                  className="absolute inset-y-0 start-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
                                  style={{ width: `${percentage}%` }}
                                />
                                <div className="relative flex flex-1 items-center gap-2">
                                  <span className="flex h-4 w-4 items-center justify-center text-muted-foreground">
                                    {browser.icon}
                                  </span>
                                  <span className="flex-1 truncate text-[12px] font-medium text-foreground">
                                    {browser.name}
                                  </span>
                                  <div className="flex items-center gap-3">
                                    <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                                      {share}%
                                    </span>
                                    <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                      {formatNumber(browser.visitors)}
                                    </span>
                                  </div>
                                </div>
                              </div>
                            )
                          })}
                        </div>
                        {browsers.length > 15 && (
                          <div className="mt-3 flex justify-center">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() =>
                                setShowAllBrowsers(!showAllBrowsers)
                              }
                              className="h-7 text-[11px]"
                            >
                              {showAllBrowsers ? (
                                <>
                                  {t('Show less')}
                                  <ChevronDown className="ms-1 h-3 w-3 rotate-180" />
                                </>
                              ) : (
                                <>
                                  {t('Show more')} ({browsers.length - 15} {t('more')})
                                  <ChevronDown className="ms-1 h-3 w-3" />
                                </>
                              )}
                            </Button>
                          </div>
                        )}
                      </TabsContent>
                      <TabsContent value="os" className="mt-0">
                        <div className="space-y-0.5">
                          {(showAllOS
                            ? operatingSystems
                            : operatingSystems.slice(0, 15)
                          ).map((os) => {
                            const total = operatingSystems.reduce(
                              (sum, o) => sum + o.visitors,
                              0,
                            )
                            const percentage = Math.round(
                              (os.visitors / total) * 100,
                            )
                            const maxOSVisitors = Math.max(
                              ...operatingSystems.map((o) => o.visitors),
                            )
                            const barPercentage = Math.round(
                              (os.visitors / maxOSVisitors) * 100,
                            )
                            return (
                              <div
                                key={os.name}
                                className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                              >
                                <div
                                  className="absolute inset-y-0 start-0 rounded-md transition-all group-hover:opacity-80"
                                  style={{
                                    width: `${barPercentage}%`,
                                    backgroundColor: os.color,
                                    opacity: 0.15,
                                  }}
                                />
                                <div className="relative flex flex-1 items-center gap-2">
                                  <div
                                    className="h-2.5 w-2.5 rounded-full"
                                    style={{ backgroundColor: os.color }}
                                  />
                                  <span className="flex-1 truncate text-[12px] font-medium text-foreground">
                                    {os.name}
                                  </span>
                                  <div className="flex items-center gap-3">
                                    <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                                      {percentage}%
                                    </span>
                                    <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                      {formatNumber(os.visitors)}
                                    </span>
                                  </div>
                                </div>
                              </div>
                            )
                          })}
                        </div>
                        {operatingSystems.length > 15 && (
                          <div className="mt-3 flex justify-center">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => setShowAllOS(!showAllOS)}
                              className="h-7 text-[11px]"
                            >
                              {showAllOS ? (
                                <>
                                  {t('Show less')}
                                  <ChevronDown className="ms-1 h-3 w-3 rotate-180" />
                                </>
                              ) : (
                                <>
                                  {t('Show more')} ({operatingSystems.length - 15}{' '}
                                  {t('more')})
                                  <ChevronDown className="ms-1 h-3 w-3" />
                                </>
                              )}
                            </Button>
                          </div>
                        )}
                      </TabsContent>
                      <TabsContent value="devices" className="mt-0">
                        <div className="space-y-0.5">
                          {(showAllDevices
                            ? devices
                            : devices.slice(0, 15)
                          ).map((device) => {
                            const total = devices.reduce(
                              (sum, d) => sum + d.visitors,
                              0,
                            )
                            const percentage = Math.round(
                              (device.visitors / total) * 100,
                            )
                            const maxDeviceVisitors = Math.max(
                              ...devices.map((d) => d.visitors),
                            )
                            const barPercentage = Math.round(
                              (device.visitors / maxDeviceVisitors) * 100,
                            )
                            return (
                              <div
                                key={device.type}
                                className="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/50"
                              >
                                <div
                                  className="absolute inset-y-0 start-0 rounded-md transition-all group-hover:opacity-80"
                                  style={{
                                    width: `${barPercentage}%`,
                                    backgroundColor: device.color,
                                    opacity: 0.15,
                                  }}
                                />
                                <div className="relative flex flex-1 items-center gap-2">
                                  <span
                                    className="flex h-4 w-4 items-center justify-center"
                                    style={{ color: device.color }}
                                  >
                                    {device.icon}
                                  </span>
                                  <span className="flex-1 truncate text-[12px] font-medium text-foreground">
                                    {t(device.type)}
                                  </span>
                                  <div className="flex items-center gap-3">
                                    <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                                      {percentage}%
                                    </span>
                                    <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
                                      {formatNumber(device.visitors)}
                                    </span>
                                  </div>
                                </div>
                              </div>
                            )
                          })}
                        </div>
                        {devices.length > 15 && (
                          <div className="mt-3 flex justify-center">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => setShowAllDevices(!showAllDevices)}
                              className="h-7 text-[11px]"
                            >
                              {showAllDevices ? (
                                <>
                                  {t('Show less')}
                                  <ChevronDown className="ms-1 h-3 w-3 rotate-180" />
                                </>
                              ) : (
                                <>
                                  {t('Show more')} ({devices.length - 15} {t('more')})
                                  <ChevronDown className="ms-1 h-3 w-3" />
                                </>
                              )}
                            </Button>
                          </div>
                        )}
                      </TabsContent>
                    </Tabs>
                  </div>
                </div>
              </div>

              {/* Additional Cards Row */}
              <div className="mt-4 grid gap-4 lg:grid-cols-2">
                {/* Peak Hours */}
                <div className="rounded-lg border border-border bg-card overflow-hidden">
                  <div className="border-b border-border px-4 py-2.5">
                    <h3 className="text-[13px] font-semibold text-foreground">
                      {t('Peak hours')}
                    </h3>
                  </div>
                  <div className="px-4 pt-3 pb-4">
                    <div
                      ref={peakHoursChartRef}
                      className="h-[160px] w-full min-h-0 min-w-0"
                    >
                      {peakHoursChartHasDimensions &&
                      typeof window !== 'undefined' ? (
                        <ChartContainer
                          config={{
                            visitors: {
                              label: t('Visitors'),
                              color: 'var(--chart-brand)',
                            },
                          }}
                          className="h-full w-full min-h-0 min-w-0"
                        >
                          <BarChart
                            data={peakHours}
                            margin={{ top: 4, right: 4, left: 0, bottom: 0 }}
                          >
                            <SeriesChartXAxis
                              pointCount={peakHours.length}
                              dataKey="label"
                              maxTicks={6}
                              tick={{
                                fill: 'currentColor',
                                fontSize: 9,
                              }}
                              height={20}
                              padding={{ left: 4, right: 4 }}
                            />
                            <YAxis
                              axisLine={false}
                              tickLine={false}
                              tick={{
                                fill: 'currentColor',
                                fontSize: 9,
                              }}
                              width={USAGE_CHART_Y_AXIS_WIDTH}
                              tickFormatter={peakHoursYAxisTickFormatter}
                            />
                            <ChartTooltip
                              cursor={{
                                fill: 'hsl(var(--accent))',
                                opacity: 0.3,
                              }}
                              content={
                                <ChartTooltipContent
                                  hideLabel
                                  formatter={(value) => (
                                    <span className="text-[12px] font-semibold tabular-nums">
                                      {formatNumber(value as number)}{' '}
                                      {t('visitors')}
                                    </span>
                                  )}
                                />
                              }
                            />
                            <Bar
                              dataKey="visitors"
                              radius={[2, 2, 0, 0]}
                              fill="var(--color-visitors)"
                              fillOpacity={0.85}
                            />
                          </BarChart>
                        </ChartContainer>
                      ) : null}
                    </div>
                  </div>
                </div>

                {/* Visitor Types */}
                <div className="rounded-lg border border-border bg-card">
                  <div className="border-b border-border px-4 py-2.5">
                    <h3 className="text-[13px] font-semibold text-foreground">
                      {t('Visitor types')}
                    </h3>
                  </div>
                  <div className="p-4">
                    <div className="space-y-3">
                      {[
                        {
                          type: 'New',
                          visitors: visitorTypes.new,
                          icon: <UserPlus className="h-4 w-4" />,
                          color: 'var(--chart-1)',
                        },
                        {
                          type: 'Returning',
                          visitors: visitorTypes.returning,
                          icon: <Users className="h-4 w-4" />,
                          color: 'var(--chart-2)',
                        },
                      ].map((visitorType) => {
                        const total = visitorTypes.new + visitorTypes.returning
                        const percentage = Math.round(
                          (visitorType.visitors / total) * 100,
                        )
                        return (
                          <div key={visitorType.type} className="space-y-1.5">
                            <div className="flex items-center justify-between">
                              <div className="flex items-center gap-2">
                                <span
                                  className="flex h-4 w-4 items-center justify-center"
                                  style={{ color: visitorType.color }}
                                >
                                  {visitorType.icon}
                                </span>
                                <span className="text-[12px] font-medium text-foreground">
                                  {t(`${visitorType.type} visitors`)}
                                </span>
                              </div>
                              <div className="flex items-center gap-3">
                                <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                                  {percentage}%
                                </span>
                                <span className="text-[12px] font-semibold tabular-nums text-foreground">
                                  {formatNumber(visitorType.visitors)}
                                </span>
                              </div>
                            </div>
                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                              <div
                                className="h-full transition-all"
                                style={{
                                  width: `${percentage}%`,
                                  backgroundColor: visitorType.color,
                                }}
                              />
                            </div>
                          </div>
                        )
                      })}
                    </div>
                  </div>
                </div>
              </div>

              {/* Goals Section */}
              <div className="mt-4">
                <div className="rounded-lg border border-border bg-card">
                  <div className="border-b border-border px-4 py-2.5">
                    <h3 className="text-[13px] font-semibold text-foreground">
                      {t('Goals')}
                    </h3>
                  </div>
                  <div className="p-6 text-center">
                    <p className="mb-4 text-[13px] text-muted-foreground">
                      {t(
                        'Measure how often visitors complete specific actions. Goals allow you to track registrations, button clicks, form completions, external link clicks, file downloads, 404 error pages and more.',
                      )}
                    </p>
                    <div className="flex items-center justify-center gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-8 text-[12px]"
                      >
                        {t('Hide this report')}
                      </Button>
                      <Button size="sm" className="h-8 text-[12px]">
                        {t('Set up goals')} →
                      </Button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}

          {activeTab === 'settings' && (
            <div className="px-4 py-4 sm:px-6">
              <div className="rounded-xl border border-border bg-card p-5">
                <h3 className="mb-4 text-[14px] font-medium text-foreground">
                  {t('Settings')}
                </h3>
                <p className="text-[13px] text-muted-foreground">
                  {t('Settings content will be displayed here.')}
                </p>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

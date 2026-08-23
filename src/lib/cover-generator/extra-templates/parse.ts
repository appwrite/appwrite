import { COVER_SCREENSHOT_FRAME_WIDTH } from '@/lib/cover-generator/cover-frame-width'
import {
  COVER_ANNOUNCEMENT_DEFAULTS,
  COVER_API_ENDPOINT_DEFAULTS,
  COVER_BIG_TYPE_DEFAULTS,
  COVER_BLOG_POST_DEFAULTS,
  COVER_CHECKLIST_DEFAULTS,
  COVER_CHECKLIST_ITEMS,
  COVER_CODE_DIFF_DEFAULTS,
  COVER_COUNTDOWN_DEFAULTS,
  COVER_DONUT_CHART_DEFAULTS,
  COVER_EVENT_DEFAULTS,
  COVER_LOGO_MARQUEE_DEFAULTS,
  COVER_METRIC_DELTA_DEFAULTS,
  COVER_NUMBERED_STEPS,
  COVER_NUMBERED_STEPS_DEFAULTS,
  COVER_PODCAST_EPISODE_DEFAULTS,
  COVER_PROFILE_CARD_DEFAULTS,
  COVER_PROGRESS_BAR_DEFAULTS,
  COVER_QUOTE_DEFAULTS,
  COVER_SOCIAL_POST_DEFAULTS,
  COVER_STATS_GRID,
  COVER_STATS_GRID_DEFAULTS,
  COVER_STATUS_PILL_DEFAULTS,
  getCoverChecklistItemKeys,
  getCoverLogoMarqueeIconKeys,
  getCoverMetricTrendKeys,
  getCoverStatLabelKeys,
  getCoverStatValueKeys,
  getCoverStepDescriptionKeys,
  getCoverStepTitleKeys,
  parseCoverApiEndpointMethod,
} from '@/lib/cover-generator/extra-templates/constants'
import { parseCoverBadgeColor } from '@/lib/cover-generator/themes'
import type { CoverExtraTemplateId } from '@/lib/cover-generator/extra-templates/ids'
import {
  formatCoverEyebrow,
  parseBooleanParam,
  parseNumberParam,
  stripCoverTitleSuffix,
} from '@/lib/cover-generator/text-utils'
import type {
  CoverExtraTemplateData,
  CoverMetricDeltaTone,
} from '@/lib/cover-generator/types'

function parseTitle(value: string | null | undefined, fallback: string): string {
  return stripCoverTitleSuffix(value?.trim() || fallback) || fallback
}

function parseOptionalText(value: string | null | undefined): string | undefined {
  const raw = value?.trim()
  if (!raw) return undefined
  return stripCoverTitleSuffix(raw) || undefined
}

function setOptional(
  params: URLSearchParams,
  key: string,
  value: string | number | boolean | undefined,
) {
  if (value == null || value === '') return
  params.set(key, String(value))
}

function parseCountdownValue(value: string | null, fallback: number): number {
  return parseNumberParam(value, fallback, 0, 99)
}

export function parseCoverExtraTemplateData(
  template: CoverExtraTemplateId,
  searchParams: URLSearchParams,
): CoverExtraTemplateData {
  switch (template) {
    case 'announcement':
      return {
        template,
        badge: searchParams.get('badge')?.trim() || undefined,
        title: parseTitle(searchParams.get('title'), COVER_ANNOUNCEMENT_DEFAULTS.title),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
      }
    case 'big-type':
      return {
        template,
        title: parseTitle(searchParams.get('title'), COVER_BIG_TYPE_DEFAULTS.title),
        gradientTitle: parseBooleanParam(searchParams.get('gradientTitle'), false),
      }
    case 'checklist':
      return {
        template,
        title: parseOptionalText(searchParams.get('title')),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
        itemCount: parseNumberParam(
          searchParams.get('itemCount'),
          COVER_CHECKLIST_ITEMS.default,
          COVER_CHECKLIST_ITEMS.min,
          COVER_CHECKLIST_ITEMS.max,
        ),
        ...Object.fromEntries(
          getCoverChecklistItemKeys().map((key, index) => [
            key,
            searchParams.get(key)?.trim() || COVER_CHECKLIST_DEFAULTS.items[index],
          ]),
        ),
      }
    case 'numbered-steps':
      return {
        template,
        eyebrow: formatCoverEyebrow(searchParams.get('eyebrow') ?? undefined),
        title: parseTitle(searchParams.get('title'), COVER_NUMBERED_STEPS_DEFAULTS.title),
        stepCount: parseNumberParam(
          searchParams.get('stepCount'),
          COVER_NUMBERED_STEPS.default,
          COVER_NUMBERED_STEPS.min,
          COVER_NUMBERED_STEPS.max,
        ),
        ...Object.fromEntries(
          getCoverStepTitleKeys().map((key, index) => [
            key,
            searchParams.get(key)?.trim() ||
              COVER_NUMBERED_STEPS_DEFAULTS.stepTitles[index],
          ]),
        ),
        ...Object.fromEntries(
          getCoverStepDescriptionKeys().map((key, index) => [
            key,
            searchParams.get(key)?.trim() ||
              COVER_NUMBERED_STEPS_DEFAULTS.stepDescriptions[index],
          ]),
        ),
      }
    case 'api-endpoint':
      return {
        template,
        title: parseOptionalText(searchParams.get('title')),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
        method: parseCoverApiEndpointMethod(searchParams.get('method')),
        path:
          searchParams.get('path')?.trim() || COVER_API_ENDPOINT_DEFAULTS.path,
        status: searchParams.get('status')?.trim() || undefined,
        frameWidthPercent: parseNumberParam(
          searchParams.get('frameWidthPercent'),
          COVER_API_ENDPOINT_DEFAULTS.frameWidthPercent,
          COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
          COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
        ),
      }
    case 'code-diff':
      return {
        template,
        title: parseOptionalText(searchParams.get('title')),
        fileName:
          searchParams.get('fileName')?.trim() || COVER_CODE_DIFF_DEFAULTS.fileName,
        code: (() => {
          const raw = searchParams.get('code')
          return raw && raw.trim().length > 0 ? raw : COVER_CODE_DIFF_DEFAULTS.code
        })(),
        frameWidthPercent: parseNumberParam(
          searchParams.get('frameWidthPercent'),
          COVER_CODE_DIFF_DEFAULTS.frameWidthPercent,
          COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
          COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
        ),
      }
    case 'status-pill':
      return {
        template,
        eyebrow: formatCoverEyebrow(searchParams.get('eyebrow') ?? undefined),
        status:
          searchParams.get('status')?.trim() || COVER_STATUS_PILL_DEFAULTS.status,
        title: parseTitle(searchParams.get('title'), COVER_STATUS_PILL_DEFAULTS.title),
      }
    case 'countdown':
      return {
        template,
        eyebrow: formatCoverEyebrow(searchParams.get('eyebrow') ?? undefined),
        title: parseTitle(searchParams.get('title'), COVER_COUNTDOWN_DEFAULTS.title),
        days: parseCountdownValue(searchParams.get('days'), COVER_COUNTDOWN_DEFAULTS.days),
        hours: parseCountdownValue(searchParams.get('hours'), COVER_COUNTDOWN_DEFAULTS.hours),
        minutes: parseCountdownValue(
          searchParams.get('minutes'),
          COVER_COUNTDOWN_DEFAULTS.minutes,
        ),
        seconds: parseCountdownValue(
          searchParams.get('seconds'),
          COVER_COUNTDOWN_DEFAULTS.seconds,
        ),
        dateLabel: searchParams.get('dateLabel')?.trim() || undefined,
      }
    case 'logo-marquee':
      return {
        template,
        title: parseOptionalText(searchParams.get('title')),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
        ...Object.fromEntries(
          getCoverLogoMarqueeIconKeys().map((key, index) => [
            key,
            searchParams.get(key)?.trim() ||
              COVER_LOGO_MARQUEE_DEFAULTS.icons[index],
          ]),
        ),
      }
    case 'stats-grid':
      return {
        template,
        title: parseOptionalText(searchParams.get('title')),
        statCount: parseNumberParam(
          searchParams.get('statCount'),
          COVER_STATS_GRID.default,
          COVER_STATS_GRID.min,
          COVER_STATS_GRID.max,
        ),
        ...Object.fromEntries(
          getCoverStatValueKeys().map((key, index) => [
            key,
            searchParams.get(key)?.trim() || COVER_STATS_GRID_DEFAULTS.values[index],
          ]),
        ),
        ...Object.fromEntries(
          getCoverStatLabelKeys().map((key, index) => [
            key,
            searchParams.get(key)?.trim() || COVER_STATS_GRID_DEFAULTS.labels[index],
          ]),
        ),
      }
    case 'metric-delta':
      return {
        template,
        label: searchParams.get('label')?.trim() || undefined,
        value:
          searchParams.get('value')?.trim() || COVER_METRIC_DELTA_DEFAULTS.value,
        delta: searchParams.get('delta')?.trim() || undefined,
        deltaTone: (searchParams.get('deltaTone')?.trim().toLowerCase() === 'down'
          ? 'down'
          : 'up') as CoverMetricDeltaTone,
        ...Object.fromEntries(
          getCoverMetricTrendKeys().map((key, index) => [
            key,
            parseNumberParam(
              searchParams.get(key),
              COVER_METRIC_DELTA_DEFAULTS.trend[index] ?? 50,
              0,
              100,
            ),
          ]),
        ),
      }
    case 'donut-chart':
      return {
        template,
        title: parseOptionalText(searchParams.get('title')),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
        percent: parseNumberParam(
          searchParams.get('percent'),
          COVER_DONUT_CHART_DEFAULTS.percent,
          0,
          100,
        ),
        centerLabel: searchParams.get('centerLabel')?.trim() || undefined,
        color: parseCoverBadgeColor(searchParams.get('color')),
      }
    case 'progress-bar':
      return {
        template,
        title: parseOptionalText(searchParams.get('title')),
        percent: parseNumberParam(
          searchParams.get('percent'),
          COVER_PROGRESS_BAR_DEFAULTS.percent,
          0,
          100,
        ),
        label: searchParams.get('label')?.trim() || undefined,
        color: parseCoverBadgeColor(searchParams.get('color')),
      }
    case 'quote':
      return {
        template,
        quote: searchParams.get('quote')?.trim() || COVER_QUOTE_DEFAULTS.quote,
        authorName: searchParams.get('authorName')?.trim() || undefined,
        authorRole: searchParams.get('authorRole')?.trim() || undefined,
        avatar: searchParams.get('avatar')?.trim() || undefined,
      }
    case 'blog-post':
      return {
        template,
        category: formatCoverEyebrow(searchParams.get('category') ?? undefined),
        title: parseTitle(searchParams.get('title'), COVER_BLOG_POST_DEFAULTS.title),
        authorName: searchParams.get('authorName')?.trim() || undefined,
        avatar: searchParams.get('avatar')?.trim() || undefined,
        date: searchParams.get('date')?.trim() || undefined,
        readTime: searchParams.get('readTime')?.trim() || undefined,
      }
    case 'podcast-episode':
      return {
        template,
        episode: searchParams.get('episode')?.trim() || undefined,
        title: parseTitle(
          searchParams.get('title'),
          COVER_PODCAST_EPISODE_DEFAULTS.title,
        ),
        duration: searchParams.get('duration')?.trim() || undefined,
        host: searchParams.get('host')?.trim() || undefined,
      }
    case 'event':
      return {
        template,
        month:
          searchParams.get('month')?.trim().toUpperCase() ||
          COVER_EVENT_DEFAULTS.month,
        day: searchParams.get('day')?.trim() || COVER_EVENT_DEFAULTS.day,
        title: parseTitle(searchParams.get('title'), COVER_EVENT_DEFAULTS.title),
        location: searchParams.get('location')?.trim() || undefined,
        cta: searchParams.get('cta')?.trim() || undefined,
      }
    case 'profile-card':
      return {
        template,
        avatar: searchParams.get('avatar')?.trim() || undefined,
        name: searchParams.get('name')?.trim() || COVER_PROFILE_CARD_DEFAULTS.name,
        role: searchParams.get('role')?.trim() || undefined,
        handle: searchParams.get('handle')?.trim() || undefined,
      }
    case 'social-post':
      return {
        template,
        avatar: searchParams.get('avatar')?.trim() || undefined,
        name: searchParams.get('name')?.trim() || COVER_SOCIAL_POST_DEFAULTS.name,
        handle: searchParams.get('handle')?.trim() || undefined,
        time: searchParams.get('time')?.trim() || undefined,
        text: searchParams.get('text')?.trim() || COVER_SOCIAL_POST_DEFAULTS.text,
        likes: searchParams.get('likes')?.trim() || undefined,
        comments: searchParams.get('comments')?.trim() || undefined,
      }
  }
}

export function appendCoverExtraTemplateSearchParams(
  params: URLSearchParams,
  data: CoverExtraTemplateData,
): void {
  switch (data.template) {
    case 'announcement':
      setOptional(params, 'badge', data.badge)
      params.set('title', stripCoverTitleSuffix(data.title))
      setOptional(params, 'subtitle', data.subtitle)
      break
    case 'big-type':
      params.set('title', stripCoverTitleSuffix(data.title))
      if (data.gradientTitle) {
        setOptional(params, 'gradientTitle', data.gradientTitle)
      }
      break
    case 'checklist':
      setOptional(
        params,
        'title',
        data.title ? stripCoverTitleSuffix(data.title) : undefined,
      )
      setOptional(params, 'subtitle', data.subtitle)
      setOptional(params, 'itemCount', data.itemCount)
      for (const key of getCoverChecklistItemKeys()) {
        setOptional(params, key, data[key])
      }
      break
    case 'numbered-steps':
      setOptional(params, 'eyebrow', formatCoverEyebrow(data.eyebrow))
      params.set('title', stripCoverTitleSuffix(data.title))
      setOptional(params, 'stepCount', data.stepCount)
      for (const key of getCoverStepTitleKeys()) {
        setOptional(params, key, data[key])
      }
      for (const key of getCoverStepDescriptionKeys()) {
        setOptional(params, key, data[key])
      }
      break
    case 'api-endpoint':
      setOptional(
        params,
        'title',
        data.title ? stripCoverTitleSuffix(data.title) : undefined,
      )
      setOptional(params, 'subtitle', data.subtitle)
      params.set('method', data.method)
      params.set('path', data.path)
      setOptional(params, 'status', data.status)
      setOptional(params, 'frameWidthPercent', data.frameWidthPercent)
      break
    case 'code-diff':
      setOptional(
        params,
        'title',
        data.title ? stripCoverTitleSuffix(data.title) : undefined,
      )
      params.set('fileName', data.fileName)
      params.set('code', data.code)
      setOptional(params, 'frameWidthPercent', data.frameWidthPercent)
      break
    case 'status-pill':
      setOptional(params, 'eyebrow', formatCoverEyebrow(data.eyebrow))
      params.set('status', data.status)
      params.set('title', stripCoverTitleSuffix(data.title))
      break
    case 'countdown':
      setOptional(params, 'eyebrow', formatCoverEyebrow(data.eyebrow))
      params.set('title', stripCoverTitleSuffix(data.title))
      setOptional(params, 'days', data.days)
      setOptional(params, 'hours', data.hours)
      setOptional(params, 'minutes', data.minutes)
      setOptional(params, 'seconds', data.seconds)
      setOptional(params, 'dateLabel', data.dateLabel)
      break
    case 'logo-marquee':
      setOptional(
        params,
        'title',
        data.title ? stripCoverTitleSuffix(data.title) : undefined,
      )
      setOptional(params, 'subtitle', data.subtitle)
      for (const key of getCoverLogoMarqueeIconKeys()) {
        setOptional(params, key, data[key])
      }
      break
    case 'stats-grid':
      setOptional(
        params,
        'title',
        data.title ? stripCoverTitleSuffix(data.title) : undefined,
      )
      setOptional(params, 'statCount', data.statCount)
      for (const key of getCoverStatValueKeys()) {
        setOptional(params, key, data[key])
      }
      for (const key of getCoverStatLabelKeys()) {
        setOptional(params, key, data[key])
      }
      break
    case 'metric-delta':
      setOptional(params, 'label', data.label)
      params.set('value', data.value)
      setOptional(params, 'delta', data.delta)
      if (data.deltaTone !== 'up') {
        setOptional(params, 'deltaTone', data.deltaTone)
      }
      for (const key of getCoverMetricTrendKeys()) {
        setOptional(params, key, data[key])
      }
      break
    case 'donut-chart':
      setOptional(
        params,
        'title',
        data.title ? stripCoverTitleSuffix(data.title) : undefined,
      )
      setOptional(params, 'subtitle', data.subtitle)
      setOptional(params, 'percent', data.percent)
      setOptional(params, 'centerLabel', data.centerLabel)
      if (data.color !== COVER_DONUT_CHART_DEFAULTS.color) {
        setOptional(params, 'color', data.color)
      }
      break
    case 'progress-bar':
      setOptional(
        params,
        'title',
        data.title ? stripCoverTitleSuffix(data.title) : undefined,
      )
      setOptional(params, 'percent', data.percent)
      setOptional(params, 'label', data.label)
      if (data.color !== COVER_PROGRESS_BAR_DEFAULTS.color) {
        setOptional(params, 'color', data.color)
      }
      break
    case 'quote':
      params.set('quote', data.quote)
      setOptional(params, 'authorName', data.authorName)
      setOptional(params, 'authorRole', data.authorRole)
      setOptional(params, 'avatar', data.avatar)
      break
    case 'blog-post':
      setOptional(params, 'category', formatCoverEyebrow(data.category))
      params.set('title', stripCoverTitleSuffix(data.title))
      setOptional(params, 'authorName', data.authorName)
      setOptional(params, 'avatar', data.avatar)
      setOptional(params, 'date', data.date)
      setOptional(params, 'readTime', data.readTime)
      break
    case 'podcast-episode':
      setOptional(params, 'episode', data.episode)
      params.set('title', stripCoverTitleSuffix(data.title))
      setOptional(params, 'duration', data.duration)
      setOptional(params, 'host', data.host)
      break
    case 'event':
      params.set('month', data.month)
      params.set('day', data.day)
      params.set('title', stripCoverTitleSuffix(data.title))
      setOptional(params, 'location', data.location)
      setOptional(params, 'cta', data.cta)
      break
    case 'profile-card':
      setOptional(params, 'avatar', data.avatar)
      params.set('name', data.name)
      setOptional(params, 'role', data.role)
      setOptional(params, 'handle', data.handle)
      break
    case 'social-post':
      setOptional(params, 'avatar', data.avatar)
      params.set('name', data.name)
      setOptional(params, 'handle', data.handle)
      setOptional(params, 'time', data.time)
      params.set('text', data.text)
      setOptional(params, 'likes', data.likes)
      setOptional(params, 'comments', data.comments)
      break
  }
}

/**
 * Initial editor values for optional fields (fields that parse to `undefined`
 * when missing). Required fields fall back to defaults during parsing.
 */
export function buildCoverExtraTemplateDefaultParams(
  template: CoverExtraTemplateId,
): Record<string, string> {
  switch (template) {
    case 'announcement':
      return {
        badge: COVER_ANNOUNCEMENT_DEFAULTS.badge,
        title: COVER_ANNOUNCEMENT_DEFAULTS.title,
        subtitle: COVER_ANNOUNCEMENT_DEFAULTS.subtitle,
      }
    case 'big-type':
      return {
        title: COVER_BIG_TYPE_DEFAULTS.title,
      }
    case 'checklist':
      return {
        title: COVER_CHECKLIST_DEFAULTS.title,
        subtitle: COVER_CHECKLIST_DEFAULTS.subtitle,
      }
    case 'numbered-steps':
      return {
        eyebrow: COVER_NUMBERED_STEPS_DEFAULTS.eyebrow,
        title: COVER_NUMBERED_STEPS_DEFAULTS.title,
      }
    case 'api-endpoint':
      return {
        title: COVER_API_ENDPOINT_DEFAULTS.title,
        subtitle: COVER_API_ENDPOINT_DEFAULTS.subtitle,
        status: COVER_API_ENDPOINT_DEFAULTS.status,
      }
    case 'code-diff':
      return { title: COVER_CODE_DIFF_DEFAULTS.title }
    case 'status-pill':
      return {
        eyebrow: COVER_STATUS_PILL_DEFAULTS.eyebrow,
        title: COVER_STATUS_PILL_DEFAULTS.title,
      }
    case 'countdown':
      return {
        eyebrow: COVER_COUNTDOWN_DEFAULTS.eyebrow,
        dateLabel: COVER_COUNTDOWN_DEFAULTS.dateLabel,
      }
    case 'logo-marquee':
      return {
        title: COVER_LOGO_MARQUEE_DEFAULTS.title,
        subtitle: COVER_LOGO_MARQUEE_DEFAULTS.subtitle,
      }
    case 'stats-grid':
      return { title: COVER_STATS_GRID_DEFAULTS.title }
    case 'metric-delta':
      return {
        label: COVER_METRIC_DELTA_DEFAULTS.label,
        delta: COVER_METRIC_DELTA_DEFAULTS.delta,
      }
    case 'donut-chart':
      return {
        title: COVER_DONUT_CHART_DEFAULTS.title,
        subtitle: COVER_DONUT_CHART_DEFAULTS.subtitle,
        centerLabel: COVER_DONUT_CHART_DEFAULTS.centerLabel,
      }
    case 'progress-bar':
      return {
        title: COVER_PROGRESS_BAR_DEFAULTS.title,
        label: COVER_PROGRESS_BAR_DEFAULTS.label,
      }
    case 'quote':
      return {
        authorName: COVER_QUOTE_DEFAULTS.authorName,
        authorRole: COVER_QUOTE_DEFAULTS.authorRole,
      }
    case 'blog-post':
      return {
        category: COVER_BLOG_POST_DEFAULTS.category,
        title: COVER_BLOG_POST_DEFAULTS.title,
        authorName: COVER_BLOG_POST_DEFAULTS.authorName,
        date: COVER_BLOG_POST_DEFAULTS.date,
        readTime: COVER_BLOG_POST_DEFAULTS.readTime,
      }
    case 'podcast-episode':
      return {
        episode: COVER_PODCAST_EPISODE_DEFAULTS.episode,
        title: COVER_PODCAST_EPISODE_DEFAULTS.title,
        duration: COVER_PODCAST_EPISODE_DEFAULTS.duration,
        host: COVER_PODCAST_EPISODE_DEFAULTS.host,
      }
    case 'event':
      return {
        title: COVER_EVENT_DEFAULTS.title,
        location: COVER_EVENT_DEFAULTS.location,
        cta: COVER_EVENT_DEFAULTS.cta,
      }
    case 'profile-card':
      return {
        role: COVER_PROFILE_CARD_DEFAULTS.role,
        handle: COVER_PROFILE_CARD_DEFAULTS.handle,
      }
    case 'social-post':
      return {
        handle: COVER_SOCIAL_POST_DEFAULTS.handle,
        time: COVER_SOCIAL_POST_DEFAULTS.time,
        likes: COVER_SOCIAL_POST_DEFAULTS.likes,
        comments: COVER_SOCIAL_POST_DEFAULTS.comments,
      }
  }
}

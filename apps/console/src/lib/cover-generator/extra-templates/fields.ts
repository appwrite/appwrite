import { COVER_SCREENSHOT_FRAME_WIDTH } from '@/lib/cover-generator/cover-frame-width'
import {
  COVER_ANNOUNCEMENT_DEFAULTS,
  COVER_API_ENDPOINT_DEFAULTS,
  COVER_API_ENDPOINT_METHODS,
  COVER_BIG_TYPE_DEFAULTS,
  COVER_BLOG_POST_DEFAULTS,
  COVER_CHECKLIST_DEFAULTS,
  COVER_CHECKLIST_ITEMS,
  COVER_CODE_DIFF_DEFAULTS,
  COVER_COUNTDOWN_DEFAULTS,
  COVER_BADGE_COLOR_OPTIONS,
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
  getCoverStatLabelKeys,
  getCoverStatValueKeys,
  getCoverStepDescriptionKeys,
  getCoverStepTitleKeys,
} from '@/lib/cover-generator/extra-templates/constants'
import type { CoverFieldDefinition, CoverTemplateDefinition } from '@/lib/cover-generator/types'

const COVER_EXTRA_FRAME_WIDTH_FIELD: CoverFieldDefinition = {
  key: 'frameWidthPercent',
  label: 'Frame width',
  type: 'range',
  min: COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
  max: COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
  step: COVER_SCREENSHOT_FRAME_WIDTH.step,
  unit: '%',
  description: 'Width relative to the current template size (100% fills the template width).',
}

const COVER_EXTRA_CHECKLIST_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: COVER_CHECKLIST_DEFAULTS.title,
  },
  {
    key: 'subtitle',
    label: 'Subtitle',
    type: 'textarea',
    placeholder: COVER_CHECKLIST_DEFAULTS.subtitle,
  },
  {
    key: 'itemCount',
    label: 'Items',
    type: 'range',
    min: COVER_CHECKLIST_ITEMS.min,
    max: COVER_CHECKLIST_ITEMS.max,
    step: 1,
    description: 'Number of checklist rows rendered in the card.',
  },
  ...getCoverChecklistItemKeys().map((key, index): CoverFieldDefinition => {
    const visible = index < COVER_CHECKLIST_ITEMS.default
    return {
      key,
      label: `Item ${index + 1}`,
      type: 'text',
      placeholder: COVER_CHECKLIST_DEFAULTS.items[index],
      description: visible
        ? undefined
        : 'Shown when the items count includes this row.',
    }
  }),
]

const COVER_EXTRA_NUMBERED_STEPS_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'eyebrow',
    label: 'Eyebrow',
    type: 'text',
    placeholder: COVER_NUMBERED_STEPS_DEFAULTS.eyebrow,
  },
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: COVER_NUMBERED_STEPS_DEFAULTS.title,
  },
  {
    key: 'stepCount',
    label: 'Steps',
    type: 'range',
    min: COVER_NUMBERED_STEPS.min,
    max: COVER_NUMBERED_STEPS.max,
    step: 1,
    description: 'Number of step cards rendered in the row.',
  },
  ...getCoverStepTitleKeys().flatMap((key, index): CoverFieldDefinition[] => [
    {
      key,
      label: `Step ${index + 1} title`,
      type: 'text',
      placeholder: COVER_NUMBERED_STEPS_DEFAULTS.stepTitles[index],
    },
    {
      key: getCoverStepDescriptionKeys()[index],
      label: `Step ${index + 1} description`,
      type: 'text',
      placeholder: COVER_NUMBERED_STEPS_DEFAULTS.stepDescriptions[index],
    },
  ]),
]

const COVER_EXTRA_API_ENDPOINT_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: COVER_API_ENDPOINT_DEFAULTS.title,
  },
  {
    key: 'subtitle',
    label: 'Subtitle',
    type: 'textarea',
    placeholder: COVER_API_ENDPOINT_DEFAULTS.subtitle,
  },
  {
    key: 'method',
    label: 'HTTP method',
    type: 'select',
    options: COVER_API_ENDPOINT_METHODS.map((method) => ({
      value: method,
      label: method,
    })),
    description: 'Method chip color follows the brand palette.',
  },
  {
    key: 'path',
    label: 'Endpoint path',
    type: 'text',
    placeholder: COVER_API_ENDPOINT_DEFAULTS.path,
  },
  {
    key: 'status',
    label: 'Status label',
    type: 'text',
    placeholder: COVER_API_ENDPOINT_DEFAULTS.status,
    description: 'Optional status text on the right of the endpoint bar. Clear it to hide.',
  },
  COVER_EXTRA_FRAME_WIDTH_FIELD,
]

const COVER_EXTRA_CODE_DIFF_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: COVER_CODE_DIFF_DEFAULTS.title,
  },
  {
    key: 'fileName',
    label: 'File name',
    type: 'text',
    placeholder: COVER_CODE_DIFF_DEFAULTS.fileName,
    description: 'Shown in the window header next to the change counters.',
  },
  {
    key: 'code',
    label: 'Diff',
    type: 'textarea',
    placeholder: COVER_CODE_DIFF_DEFAULTS.code,
    description:
      'One line per row. Prefix lines with + for additions, - for removals, anything else renders as context.',
  },
  COVER_EXTRA_FRAME_WIDTH_FIELD,
]

const COVER_EXTRA_COUNTDOWN_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'eyebrow',
    label: 'Eyebrow',
    type: 'text',
    placeholder: COVER_COUNTDOWN_DEFAULTS.eyebrow,
  },
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: COVER_COUNTDOWN_DEFAULTS.title,
  },
  ...(
    [
      ['days', 'Days', COVER_COUNTDOWN_DEFAULTS.days],
      ['hours', 'Hours', COVER_COUNTDOWN_DEFAULTS.hours],
      ['minutes', 'Minutes', COVER_COUNTDOWN_DEFAULTS.minutes],
      ['seconds', 'Seconds', COVER_COUNTDOWN_DEFAULTS.seconds],
    ] as const
  ).map(
    ([key, label, placeholder]): CoverFieldDefinition => ({
      key,
      label,
      type: 'number',
      min: 0,
      max: 99,
      placeholder: String(placeholder),
    }),
  ),
  {
    key: 'dateLabel',
    label: 'Date label',
    type: 'text',
    placeholder: COVER_COUNTDOWN_DEFAULTS.dateLabel,
    description: 'Optional line shown below the countdown boxes.',
  },
]

const COVER_EXTRA_LOGO_MARQUEE_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: COVER_LOGO_MARQUEE_DEFAULTS.title,
  },
  {
    key: 'subtitle',
    label: 'Subtitle',
    type: 'textarea',
    placeholder: COVER_LOGO_MARQUEE_DEFAULTS.subtitle,
  },
  ...getCoverLogoMarqueeIconKeys().map((key, index): CoverFieldDefinition => {
    const row = index < 4 ? 1 : 2
    return {
      key,
      label: `Icon ${index + 1}`,
      type: 'image',
      imagePicker: 'builtin-icons',
      placeholder: COVER_LOGO_MARQUEE_DEFAULTS.icons[index],
      description: `Row ${row} of the logo wall.`,
    }
  }),
]

const COVER_EXTRA_STATS_GRID_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: COVER_STATS_GRID_DEFAULTS.title,
  },
  {
    key: 'statCount',
    label: 'Stats',
    type: 'range',
    min: COVER_STATS_GRID.min,
    max: COVER_STATS_GRID.max,
    step: 1,
    description: 'Number of stat cards rendered in the row.',
  },
  ...getCoverStatValueKeys().flatMap((key, index): CoverFieldDefinition[] => [
    {
      key,
      label: `Stat ${index + 1} value`,
      type: 'text',
      placeholder: COVER_STATS_GRID_DEFAULTS.values[index],
    },
    {
      key: getCoverStatLabelKeys()[index],
      label: `Stat ${index + 1} label`,
      type: 'text',
      placeholder: COVER_STATS_GRID_DEFAULTS.labels[index],
    },
  ]),
]

export const COVER_EXTRA_TEMPLATE_DEFINITIONS: CoverTemplateDefinition[] = [
  {
    id: 'announcement',
    label: 'Announcement',
    description: 'Centered badge pill with a large headline and supporting copy.',
    fields: [
      {
        key: 'badge',
        label: 'Badge',
        type: 'text',
        placeholder: COVER_ANNOUNCEMENT_DEFAULTS.badge,
        description: 'Small pill above the title. Clear it to hide.',
      },
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: COVER_ANNOUNCEMENT_DEFAULTS.title,
      },
      {
        key: 'subtitle',
        label: 'Subtitle',
        type: 'textarea',
        placeholder: COVER_ANNOUNCEMENT_DEFAULTS.subtitle,
      },
    ],
  },
  {
    id: 'big-type',
    label: 'Big type',
    description: 'Oversized display headline centered on the canvas.',
    fields: [
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: COVER_BIG_TYPE_DEFAULTS.title,
        description: 'One word or a short phrase, scaled to fill the canvas width.',
      },
      {
        key: 'gradientTitle',
        label: 'Gradient title',
        type: 'boolean',
        description: 'Render the title with the brand gradient instead of solid text.',
      },
    ],
  },
  {
    id: 'checklist',
    label: 'Checklist',
    description: 'Headline on the left with a glass card of checkmarked items.',
    fields: COVER_EXTRA_CHECKLIST_FIELDS,
  },
  {
    id: 'numbered-steps',
    label: 'Numbered steps',
    description: 'Row of numbered step cards for how-it-works and onboarding covers.',
    fields: COVER_EXTRA_NUMBERED_STEPS_FIELDS,
  },
  {
    id: 'api-endpoint',
    label: 'API endpoint',
    description: 'Headline with a glass bar showing the HTTP method and endpoint path.',
    fields: COVER_EXTRA_API_ENDPOINT_FIELDS,
  },
  {
    id: 'code-diff',
    label: 'Code diff',
    description: 'Glass window with added and removed lines for changelog-style covers.',
    fields: COVER_EXTRA_CODE_DIFF_FIELDS,
  },
  {
    id: 'status-pill',
    label: 'Status pill',
    description: 'Large status pill with a headline for GA and beta announcements.',
    fields: [
      {
        key: 'eyebrow',
        label: 'Eyebrow',
        type: 'text',
        placeholder: COVER_STATUS_PILL_DEFAULTS.eyebrow,
      },
      {
        key: 'status',
        label: 'Status',
        type: 'text',
        placeholder: COVER_STATUS_PILL_DEFAULTS.status,
        description: 'Rendered uppercase inside the status pill.',
      },
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: COVER_STATUS_PILL_DEFAULTS.title,
      },
    ],
  },
  {
    id: 'countdown',
    label: 'Countdown',
    description: 'Countdown boxes with a headline for launch and event teasers.',
    fields: COVER_EXTRA_COUNTDOWN_FIELDS,
  },
  {
    id: 'logo-marquee',
    label: 'Logo wall',
    description: 'Two staggered rows of icon chips under a headline.',
    fields: COVER_EXTRA_LOGO_MARQUEE_FIELDS,
  },
  {
    id: 'stats-grid',
    label: 'Stats grid',
    description: 'Row of glass stat cards with big values for company or product metrics.',
    fields: COVER_EXTRA_STATS_GRID_FIELDS,
  },
  {
    id: 'metric-delta',
    label: 'Metric delta',
    description: 'Big metric with a percent change and a framed line chart.',
    fields: [
      {
        key: 'label',
        label: 'Label',
        type: 'text',
        placeholder: COVER_METRIC_DELTA_DEFAULTS.label,
        description: 'Small uppercase label above the value.',
      },
      {
        key: 'value',
        label: 'Value',
        type: 'text',
        placeholder: COVER_METRIC_DELTA_DEFAULTS.value,
      },
      {
        key: 'delta',
        label: 'Delta',
        type: 'text',
        placeholder: COVER_METRIC_DELTA_DEFAULTS.delta,
        description: 'Percent change shown next to the value (for example +24.5%).',
      },
      {
        key: 'deltaTone',
        label: 'Delta direction',
        type: 'select',
        options: [
          { value: 'up', label: 'Up (positive)' },
          { value: 'down', label: 'Down (negative)' },
        ],
      },
    ],
  },
  {
    id: 'donut-chart',
    label: 'Donut chart',
    description: 'Headline on the left with a progress ring on the right.',
    fields: [
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: COVER_DONUT_CHART_DEFAULTS.title,
      },
      {
        key: 'subtitle',
        label: 'Subtitle',
        type: 'textarea',
        placeholder: COVER_DONUT_CHART_DEFAULTS.subtitle,
      },
      {
        key: 'percent',
        label: 'Percent',
        type: 'range',
        min: 0,
        max: 100,
        step: 1,
        unit: '%',
      },
      {
        key: 'color',
        label: 'Color',
        type: 'color',
        options: COVER_BADGE_COLOR_OPTIONS,
      },
      {
        key: 'centerLabel',
        label: 'Center label',
        type: 'text',
        placeholder: COVER_DONUT_CHART_DEFAULTS.centerLabel,
      },
    ],
  },
  {
    id: 'progress-bar',
    label: 'Progress bar',
    description: 'Giant percentage with a progress bar for goals and migrations.',
    fields: [
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: COVER_PROGRESS_BAR_DEFAULTS.title,
      },
      {
        key: 'percent',
        label: 'Percent',
        type: 'range',
        min: 0,
        max: 100,
        step: 1,
        unit: '%',
      },
      {
        key: 'color',
        label: 'Color',
        type: 'color',
        options: COVER_BADGE_COLOR_OPTIONS,
      },
      {
        key: 'label',
        label: 'Label',
        type: 'text',
        placeholder: COVER_PROGRESS_BAR_DEFAULTS.label,
        description: 'Optional line shown below the bar.',
      },
    ],
  },
  {
    id: 'quote',
    label: 'Quote',
    description: 'Pull quote with an oversized quote mark and an author row.',
    fields: [
      {
        key: 'quote',
        label: 'Quote',
        type: 'textarea',
        placeholder: COVER_QUOTE_DEFAULTS.quote,
      },
      {
        key: 'authorName',
        label: 'Author name',
        type: 'text',
        placeholder: COVER_QUOTE_DEFAULTS.authorName,
      },
      {
        key: 'authorRole',
        label: 'Author role',
        type: 'text',
        placeholder: COVER_QUOTE_DEFAULTS.authorRole,
      },
      {
        key: 'avatar',
        label: 'Author avatar',
        type: 'image',
        description: 'Public URL or upload. Falls back to initials when empty.',
      },
    ],
  },
  {
    id: 'blog-post',
    label: 'Blog post',
    description: 'Article cover with a category eyebrow, headline, and author byline.',
    fields: [
      {
        key: 'category',
        label: 'Category',
        type: 'text',
        placeholder: COVER_BLOG_POST_DEFAULTS.category,
      },
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: COVER_BLOG_POST_DEFAULTS.title,
      },
      {
        key: 'authorName',
        label: 'Author name',
        type: 'text',
        placeholder: COVER_BLOG_POST_DEFAULTS.authorName,
      },
      {
        key: 'avatar',
        label: 'Author avatar',
        type: 'image',
        description: 'Public URL or upload. Falls back to initials when empty.',
      },
      {
        key: 'date',
        label: 'Date',
        type: 'date',
        placeholder: COVER_BLOG_POST_DEFAULTS.date,
      },
      {
        key: 'readTime',
        label: 'Reading time',
        type: 'text',
        placeholder: COVER_BLOG_POST_DEFAULTS.readTime,
      },
    ],
  },
  {
    id: 'podcast-episode',
    label: 'Podcast episode',
    description: 'Episode pill, headline, and a waveform strip for podcast covers.',
    fields: [
      {
        key: 'episode',
        label: 'Episode number',
        type: 'text',
        placeholder: COVER_PODCAST_EPISODE_DEFAULTS.episode,
      },
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: COVER_PODCAST_EPISODE_DEFAULTS.title,
      },
      {
        key: 'duration',
        label: 'Duration',
        type: 'text',
        placeholder: COVER_PODCAST_EPISODE_DEFAULTS.duration,
      },
      {
        key: 'host',
        label: 'Host or show',
        type: 'text',
        placeholder: COVER_PODCAST_EPISODE_DEFAULTS.host,
      },
    ],
  },
  {
    id: 'event',
    label: 'Event',
    description: 'Date card with a headline, location, and CTA for meetups and summits.',
    fields: [
      {
        key: 'month',
        label: 'Month',
        type: 'text',
        placeholder: COVER_EVENT_DEFAULTS.month,
      },
      {
        key: 'day',
        label: 'Day',
        type: 'text',
        placeholder: COVER_EVENT_DEFAULTS.day,
      },
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: COVER_EVENT_DEFAULTS.title,
      },
      {
        key: 'location',
        label: 'Location',
        type: 'text',
        placeholder: COVER_EVENT_DEFAULTS.location,
      },
      {
        key: 'cta',
        label: 'CTA label',
        type: 'text',
        placeholder: COVER_EVENT_DEFAULTS.cta,
        description: 'Optional pill button under the location. Clear it to hide.',
      },
    ],
  },
  {
    id: 'profile-card',
    label: 'Profile card',
    description: 'Circular portrait with name, role, and handle for team spotlights.',
    fields: [
      {
        key: 'avatar',
        label: 'Avatar',
        type: 'image',
        description: 'Public URL or upload. Falls back to initials when empty.',
      },
      {
        key: 'name',
        label: 'Name',
        type: 'text',
        placeholder: COVER_PROFILE_CARD_DEFAULTS.name,
      },
      {
        key: 'role',
        label: 'Role',
        type: 'text',
        placeholder: COVER_PROFILE_CARD_DEFAULTS.role,
      },
      {
        key: 'handle',
        label: 'Handle',
        type: 'text',
        placeholder: COVER_PROFILE_CARD_DEFAULTS.handle,
      },
    ],
  },
  {
    id: 'social-post',
    label: 'Social post',
    description: 'Glass card styled like a social post with engagement stats.',
    fields: [
      {
        key: 'avatar',
        label: 'Avatar',
        type: 'image',
        description: 'Public URL or upload. Falls back to initials when empty.',
      },
      {
        key: 'name',
        label: 'Name',
        type: 'text',
        placeholder: COVER_SOCIAL_POST_DEFAULTS.name,
      },
      {
        key: 'handle',
        label: 'Handle',
        type: 'text',
        placeholder: COVER_SOCIAL_POST_DEFAULTS.handle,
      },
      {
        key: 'time',
        label: 'Time',
        type: 'text',
        placeholder: COVER_SOCIAL_POST_DEFAULTS.time,
      },
      {
        key: 'text',
        label: 'Post text',
        type: 'textarea',
        placeholder: COVER_SOCIAL_POST_DEFAULTS.text,
      },
      {
        key: 'comments',
        label: 'Comments count',
        type: 'text',
        placeholder: COVER_SOCIAL_POST_DEFAULTS.comments,
      },
      {
        key: 'likes',
        label: 'Likes count',
        type: 'text',
        placeholder: COVER_SOCIAL_POST_DEFAULTS.likes,
      },
    ],
  },
]

import type { CoverFieldDefinition, CoverTemplateDefinition } from '@/lib/cover-generator/types'
import { DEFAULT_COVER_VALUES } from '@/lib/cover-generator/parse-params'
import {
  COVER_MILESTONE_FIELD_DEFINITIONS,
} from '@/lib/cover-generator/milestone/constants'
import {
  COVER_VERSION_NUMBER_FIELD_DEFINITIONS,
  COVER_VERSION_TITLE_FIELD_DEFINITIONS,
} from '@/lib/cover-generator/version/constants'
import {
  COVER_SCREENSHOT_FRAME_HEIGHT,
  COVER_SCREENSHOT_FRAME_WIDTH,
} from '@/lib/cover-generator/cover-frame-width'
import {
  COVER_CARDS_ANGLED_GRID,
} from '@/lib/cover-generator/cards-angled/constants'
import { COVER_TABLE_GRID, COVER_TABLE_DEFAULT_FRAME_WIDTH_PERCENT } from '@/lib/cover-generator/table/constants'
import {
  COVER_CHART,
} from '@/lib/cover-generator/chart/constants'
import {
  COVER_CLI_CODE_DEFAULT_FRAME_WIDTH_PERCENT,
  DEFAULT_CLI_CODE,
  DEFAULT_CLI_CODE_SUBTITLE,
  DEFAULT_CLI_CODE_TITLE,
  DEFAULT_CLI_TERMINAL_ICON,
  DEFAULT_CLI_TERMINAL_TITLE,
} from '@/lib/cover-generator/cli-code/constants'
import {
  COVER_CODE_SNIPPET_DEFAULT_FRAME_WIDTH_PERCENT,
  COVER_CODE_SNIPPET_FONT_SIZE,
  COVER_CODE_SNIPPET_LANGUAGE_LABELS,
  COVER_CODE_SNIPPET_LANGUAGES,
  DEFAULT_CODE_SNIPPET,
  DEFAULT_CODE_SNIPPET_TITLE,
} from '@/lib/cover-generator/code-snippet/constants'
import { COVER_SCREENSHOT_ANGLED_3D_LIMITS } from '@/lib/perspective-screenshot-card/constants'

const COVER_SCREENSHOT_ANGLED_3D_TEMPLATE_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'displayScale',
    label: 'Scale',
    type: 'range',
    min: COVER_SCREENSHOT_ANGLED_3D_LIMITS.displayScale.min,
    max: COVER_SCREENSHOT_ANGLED_3D_LIMITS.displayScale.max,
    step: COVER_SCREENSHOT_ANGLED_3D_LIMITS.displayScale.step,
    description: 'Overall screenshot size from the bottom-right corner.',
  },
  {
    key: 'posXRatio',
    label: 'Position X',
    type: 'range',
    min: COVER_SCREENSHOT_ANGLED_3D_LIMITS.posXRatio.min,
    max: COVER_SCREENSHOT_ANGLED_3D_LIMITS.posXRatio.max,
    step: COVER_SCREENSHOT_ANGLED_3D_LIMITS.posXRatio.step,
    description: 'Move right (positive) or left (negative) from the corner.',
  },
  {
    key: 'posYRatio',
    label: 'Position Y',
    type: 'range',
    min: COVER_SCREENSHOT_ANGLED_3D_LIMITS.posYRatio.min,
    max: COVER_SCREENSHOT_ANGLED_3D_LIMITS.posYRatio.max,
    step: COVER_SCREENSHOT_ANGLED_3D_LIMITS.posYRatio.step,
    description: 'Move down (positive) or up (negative) from the corner.',
  },
  {
    key: 'rotateX',
    label: 'Tilt X',
    type: 'range',
    min: COVER_SCREENSHOT_ANGLED_3D_LIMITS.rotateX.min,
    max: COVER_SCREENSHOT_ANGLED_3D_LIMITS.rotateX.max,
    step: COVER_SCREENSHOT_ANGLED_3D_LIMITS.rotateX.step,
    description: 'Forward/back tilt of the screenshot plane.',
  },
  {
    key: 'rotateZ',
    label: 'Tilt Z',
    type: 'range',
    min: COVER_SCREENSHOT_ANGLED_3D_LIMITS.rotateZ.min,
    max: COVER_SCREENSHOT_ANGLED_3D_LIMITS.rotateZ.max,
    step: COVER_SCREENSHOT_ANGLED_3D_LIMITS.rotateZ.step,
    description: 'Diagonal spin of the screenshot plane.',
  },
  {
    key: 'rotateY',
    label: 'Tilt Y',
    type: 'range',
    min: COVER_SCREENSHOT_ANGLED_3D_LIMITS.rotateY.min,
    max: COVER_SCREENSHOT_ANGLED_3D_LIMITS.rotateY.max,
    step: COVER_SCREENSHOT_ANGLED_3D_LIMITS.rotateY.step,
    description: 'Left/right yaw of the screenshot plane.',
  },
  {
    key: 'translateX',
    label: 'Shift X',
    type: 'range',
    min: COVER_SCREENSHOT_ANGLED_3D_LIMITS.translateX.min,
    max: COVER_SCREENSHOT_ANGLED_3D_LIMITS.translateX.max,
    step: COVER_SCREENSHOT_ANGLED_3D_LIMITS.translateX.step,
    description: 'Slide the screenshot along its local X axis.',
  },
  {
    key: 'translateY',
    label: 'Shift Y',
    type: 'range',
    min: COVER_SCREENSHOT_ANGLED_3D_LIMITS.translateY.min,
    max: COVER_SCREENSHOT_ANGLED_3D_LIMITS.translateY.max,
    step: COVER_SCREENSHOT_ANGLED_3D_LIMITS.translateY.step,
    description: 'Slide the screenshot along its local Y axis.',
  },
]

const COVER_SCREENSHOT_FRAME_WIDTH_FIELD: CoverFieldDefinition = {
  key: 'frameWidthPercent',
  label: 'Frame width',
  type: 'range',
  min: COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
  max: COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
  step: COVER_SCREENSHOT_FRAME_WIDTH.step,
  unit: '%',
  description: 'Width relative to the current template size (100% fills the template width).',
}

const COVER_SCREENSHOT_ANGLED_FRAME_WIDTH_FIELD: CoverFieldDefinition = {
  ...COVER_SCREENSHOT_FRAME_WIDTH_FIELD,
  label: 'Screenshot size',
  description:
    'Card width relative to the current template size (100% fills the template width). Height follows 16:9.',
}

const COVER_SCREENSHOT_ANGLED_TEMPLATE_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'screenshot',
    label: 'Screenshot',
    type: 'image',
    description: 'Upload or paste a URL/data URI for the screenshot.',
  },
  {
    key: 'zoom',
    label: 'Zoom',
    type: 'range',
    min: 1,
    max: 3,
    step: 0.05,
    description: 'Higher values crop tighter into the image.',
  },
  {
    key: 'focusX',
    label: 'Focus X',
    type: 'range',
    min: 0,
    max: 100,
    step: 1,
    description: 'Horizontal crop focus (0 = left, 100 = right).',
  },
  {
    key: 'focusY',
    label: 'Focus Y',
    type: 'range',
    min: 0,
    max: 100,
    step: 1,
    description: 'Vertical crop focus (0 = top, 100 = bottom).',
  },
  COVER_SCREENSHOT_ANGLED_FRAME_WIDTH_FIELD,
  ...COVER_SCREENSHOT_ANGLED_3D_TEMPLATE_FIELDS,
]

const COVER_SCREENSHOT_FRAME_HEIGHT_FIELD: CoverFieldDefinition = {
  key: 'frameHeightPercent',
  label: 'Frame height',
  type: 'range',
  min: COVER_SCREENSHOT_FRAME_HEIGHT.minPercent,
  max: COVER_SCREENSHOT_FRAME_HEIGHT.maxPercent,
  step: COVER_SCREENSHOT_FRAME_HEIGHT.step,
  unit: '%',
  description:
    'Height as a share of the space below the title down to the bottom edge (100% is as tall as possible).',
}

const COVER_SCREENSHOT_TEMPLATE_FIELDS: CoverFieldDefinition[] = [
  ...COVER_SCREENSHOT_ANGLED_TEMPLATE_FIELDS.slice(0, 4),
  COVER_SCREENSHOT_FRAME_WIDTH_FIELD,
  COVER_SCREENSHOT_FRAME_HEIGHT_FIELD,
  {
    key: 'title',
    label: 'Title',
    type: 'text',
    placeholder: 'Console overview',
  },
  {
    key: 'subtitle',
    label: 'Subtitle',
    type: 'textarea',
    placeholder: 'Manage projects, databases, and storage in one place',
  },
]

const COVER_SCREENSHOT_SIDE_FRAME_WIDTH_FIELD: CoverFieldDefinition = {
  ...COVER_SCREENSHOT_FRAME_WIDTH_FIELD,
  label: 'Screenshot width',
  description:
    'Width of the browser frame. Part of the right edge overflows and is clipped.',
}

const COVER_SCREENSHOT_SIDE_FRAME_HEIGHT_FIELD: CoverFieldDefinition = {
  ...COVER_SCREENSHOT_FRAME_HEIGHT_FIELD,
  label: 'Screenshot height',
  description:
    'Height relative to the available vertical space. Inner content keeps a 16:9 aspect ratio.',
}

const COVER_SCREENSHOT_SIDE_TEMPLATE_FIELDS: CoverFieldDefinition[] = [
  ...COVER_SCREENSHOT_ANGLED_TEMPLATE_FIELDS.slice(0, 4),
  COVER_SCREENSHOT_SIDE_FRAME_WIDTH_FIELD,
  COVER_SCREENSHOT_SIDE_FRAME_HEIGHT_FIELD,
  {
    key: 'title',
    label: 'Title',
    type: 'text',
    placeholder: 'Build faster with Appwrite',
  },
  {
    key: 'subtitle',
    label: 'Subtitle',
    type: 'textarea',
    placeholder: 'The open-source developer platform',
  },
]

const COVER_CARDS_ANGLED_TEMPLATE_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'columns',
    label: 'Columns',
    type: 'range',
    min: COVER_CARDS_ANGLED_GRID.columns.min,
    max: COVER_CARDS_ANGLED_GRID.columns.max,
    step: 1,
  },
  {
    key: 'rows',
    label: 'Rows',
    type: 'range',
    min: COVER_CARDS_ANGLED_GRID.rows.min,
    max: COVER_CARDS_ANGLED_GRID.rows.max,
    step: 1,
  },
  {
    key: 'iconSize',
    label: 'Icon size',
    type: 'range',
    min: COVER_CARDS_ANGLED_GRID.iconSize.min,
    max: COVER_CARDS_ANGLED_GRID.iconSize.max,
    step: COVER_CARDS_ANGLED_GRID.iconSize.step,
  },
  {
    key: 'gap',
    label: 'Gap',
    type: 'range',
    min: COVER_CARDS_ANGLED_GRID.gap.min,
    max: COVER_CARDS_ANGLED_GRID.gap.max,
    step: COVER_CARDS_ANGLED_GRID.gap.step,
  },
  ...COVER_SCREENSHOT_ANGLED_3D_TEMPLATE_FIELDS.map((field) =>
    field.key === 'displayScale'
      ? {
          ...field,
          description: 'Overall card grid size from the bottom-right corner.',
        }
      : field,
  ),
]

const COVER_TABLE_TEMPLATE_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: DEFAULT_COVER_VALUES.title,
  },
  {
    key: 'subtitle',
    label: 'Subtitle',
    type: 'textarea',
    placeholder: DEFAULT_COVER_VALUES.subtitle,
  },
  {
    key: 'columns',
    label: 'Columns',
    type: 'range',
    min: COVER_TABLE_GRID.columns.min,
    max: COVER_TABLE_GRID.columns.max,
    step: 1,
  },
  {
    key: 'rows',
    label: 'Rows',
    type: 'range',
    min: COVER_TABLE_GRID.rows.min,
    max: COVER_TABLE_GRID.rows.max,
    step: 1,
    description: 'Number of data rows in the table (excluding the header).',
  },
  {
    key: 'showHeader',
    label: 'Show header row',
    type: 'boolean',
  },
  {
    key: 'frameWidthPercent',
    label: 'Table width',
    type: 'range',
    min: COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
    max: COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
    step: COVER_SCREENSHOT_FRAME_WIDTH.step,
    unit: '%',
    description: 'Width relative to the template size.',
  },
]

const COVER_CHART_TEMPLATE_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: DEFAULT_COVER_VALUES.title,
  },
  {
    key: 'subtitle',
    label: 'Subtitle',
    type: 'textarea',
    placeholder: DEFAULT_COVER_VALUES.subtitle,
  },
  {
    key: 'pointCount',
    label: 'Data points',
    type: 'range',
    min: COVER_CHART.pointCount.min,
    max: COVER_CHART.pointCount.max,
    step: 1,
    description: 'Number of bars or points on the chart.',
  },
  {
    key: 'showGrid',
    label: 'Show grid lines',
    type: 'boolean',
  },
  {
    key: 'showValues',
    label: 'Show values',
    type: 'boolean',
    description: 'Display numeric values on bars or points.',
  },
  {
    key: 'frameWidthPercent',
    label: 'Chart width',
    type: 'range',
    min: COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
    max: COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
    step: COVER_SCREENSHOT_FRAME_WIDTH.step,
    unit: '%',
    description: 'Width relative to the template size.',
  },
]

const COVER_CLI_CODE_TEMPLATE_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: DEFAULT_CLI_CODE_TITLE,
  },
  {
    key: 'subtitle',
    label: 'Subtitle',
    type: 'textarea',
    placeholder: DEFAULT_CLI_CODE_SUBTITLE,
  },
  {
    key: 'code',
    label: 'Code',
    type: 'textarea',
    placeholder: DEFAULT_CLI_CODE,
    description: 'One command per line. Lines starting with # render as comments.',
  },
  {
    key: 'terminalTitle',
    label: 'Terminal title',
    type: 'text',
    placeholder: DEFAULT_CLI_TERMINAL_TITLE,
    description: 'Title shown in the terminal window header.',
  },
  {
    key: 'terminalIcon',
    label: 'Terminal icon',
    type: 'image',
    imagePicker: 'builtin-icons',
    placeholder: DEFAULT_CLI_TERMINAL_ICON,
    description: 'Icon shown next to the terminal title.',
  },
  {
    key: 'showPrompt',
    label: 'Show prompt',
    type: 'boolean',
    description: 'Prefix each command line with a $ prompt.',
  },
  {
    key: 'frameWidthPercent',
    label: 'Terminal width',
    type: 'range',
    min: COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
    max: COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
    step: COVER_SCREENSHOT_FRAME_WIDTH.step,
    unit: '%',
    description: 'Width relative to the template size.',
  },
]

const COVER_CODE_SNIPPET_TEMPLATE_FIELDS: CoverFieldDefinition[] = [
  {
    key: 'title',
    label: 'Title',
    type: 'text',
    placeholder: DEFAULT_CODE_SNIPPET_TITLE,
    description: 'Single-line headline centered above the code block.',
  },
  {
    key: 'language',
    label: 'Language',
    type: 'select',
    options: COVER_CODE_SNIPPET_LANGUAGES.map((language) => ({
      value: language,
      label: COVER_CODE_SNIPPET_LANGUAGE_LABELS[language],
    })),
    description: 'Syntax highlighting language for the code block.',
  },
  {
    key: 'code',
    label: 'Code',
    type: 'code',
    codeLanguageField: 'language',
    placeholder: DEFAULT_CODE_SNIPPET,
    description: 'Source code to render with syntax highlighting.',
  },
  {
    key: 'codeFontSize',
    label: 'Code font size',
    type: 'range',
    min: COVER_CODE_SNIPPET_FONT_SIZE.min,
    max: COVER_CODE_SNIPPET_FONT_SIZE.max,
    step: COVER_CODE_SNIPPET_FONT_SIZE.step,
    unit: 'px',
    description: 'Font size for syntax-highlighted code lines.',
  },
  {
    key: 'frameWidthPercent',
    label: 'Code block width',
    type: 'range',
    min: COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
    max: COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
    step: COVER_SCREENSHOT_FRAME_WIDTH.step,
    unit: '%',
    description: 'Width relative to the template size.',
  },
]

export const COVER_TEMPLATE_DEFINITIONS: CoverTemplateDefinition[] = [
  {
    id: 'simple-title',
    label: 'Simple title',
    description: 'Brand headline with optional eyebrow and subtitle.',
    fields: [
      {
        key: 'eyebrow',
        label: 'Eyebrow',
        type: 'text',
        placeholder: DEFAULT_COVER_VALUES.eyebrow,
      },
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: DEFAULT_COVER_VALUES.title,
      },
      {
        key: 'subtitle',
        label: 'Subtitle',
        type: 'textarea',
        placeholder: DEFAULT_COVER_VALUES.subtitle,
      },
    ],
  },
  {
    id: 'cli-code',
    label: 'CLI code',
    description: 'Headline on the left with a terminal-style code block on the right.',
    fields: COVER_CLI_CODE_TEMPLATE_FIELDS,
  },
  {
    id: 'code-snippet',
    label: 'Code snippet',
    description: 'Centered headline with a syntax-highlighted code block for sharing examples.',
    fields: COVER_CODE_SNIPPET_TEMPLATE_FIELDS,
  },
  {
    id: 'milestone-split',
    label: 'Milestone split',
    description: 'Headline on the left with a large stat on the right.',
    fields: COVER_MILESTONE_FIELD_DEFINITIONS,
  },
  {
    id: 'milestone-centered',
    label: 'Milestone centered',
    description: 'Centered stat with title and subtitle below.',
    fields: COVER_MILESTONE_FIELD_DEFINITIONS,
  },
  {
    id: 'version-number',
    label: 'Version number',
    description: 'Centered version number with a release eyebrow and brand gradient.',
    fields: COVER_VERSION_NUMBER_FIELD_DEFINITIONS,
  },
  {
    id: 'version-title',
    label: 'Version title',
    description: 'Large centered version number with eyebrow and title below.',
    fields: COVER_VERSION_TITLE_FIELD_DEFINITIONS,
  },
  {
    id: 'integration-icon',
    label: 'Integration icon',
    description: 'Single logo in a glass card with no headline or copy.',
    fields: [
      {
        key: 'icon',
        label: 'Icon',
        type: 'image',
        imagePicker: 'builtin-icons',
        placeholder: '/icons/appwrite.svg',
        description: 'Choose a built-in icon or use a custom URL or upload.',
      },
      {
        key: 'iconSize',
        label: 'Icon size',
        type: 'range',
        min: 96,
        max: 200,
        step: 4,
      },
    ],
  },
  {
    id: 'integration',
    label: 'Integration',
    description: 'Two logos with a connector for partner or integration covers.',
    fields: [
      {
        key: 'logoLeft',
        label: 'Left logo',
        type: 'image',
        imagePicker: 'builtin-icons',
        placeholder: '/icons/appwrite.svg',
        description: 'Choose a built-in icon or use a custom URL or upload.',
      },
      {
        key: 'logoRight',
        label: 'Right logo',
        type: 'image',
        imagePicker: 'builtin-icons',
        placeholder: '/icons/github.svg',
      },
      {
        key: 'connector',
        label: 'Connector',
        type: 'select',
        options: [
          { value: '×', label: '×' },
          { value: '+', label: '+' },
          { value: '→', label: '→' },
        ],
      },
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: 'Appwrite + GitHub',
      },
      {
        key: 'subtitle',
        label: 'Subtitle',
        type: 'textarea',
        placeholder: 'Deploy from any repository in minutes',
      },
    ],
  },
  {
    id: 'showcase-icon',
    label: 'Showcase icon',
    description: 'Single icon or product visual with supporting copy.',
    fields: [
      {
        key: 'icon',
        label: 'Icon',
        type: 'image',
        imagePicker: 'builtin-icons',
        placeholder: '/icons/appwrite.svg',
      },
      {
        key: 'iconSize',
        label: 'Icon size',
        type: 'range',
        min: 48,
        max: 220,
        step: 4,
      },
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: 'Storage that scales with you',
      },
      {
        key: 'subtitle',
        label: 'Subtitle',
        type: 'textarea',
        placeholder: 'Secure file storage with built-in previews',
      },
    ],
  },
  {
    id: 'title-icon',
    label: 'Title and icon',
    description: 'Product-style lockup with an icon beside the headline.',
    fields: [
      {
        key: 'icon',
        label: 'Icon',
        type: 'image',
        imagePicker: 'builtin-icons',
        placeholder: 'lucide:users',
        description: 'Choose a built-in icon or use a custom URL or upload.',
      },
      {
        key: 'iconSize',
        label: 'Icon size',
        type: 'range',
        min: 40,
        max: 128,
        step: 4,
        description: 'Sized to sit inline with the headline.',
      },
      {
        key: 'title',
        label: 'Title',
        type: 'textarea',
        placeholder: 'Auth',
      },
    ],
  },
  {
    id: 'screenshot',
    label: 'Screenshot',
    description: 'Product screenshot with zoom and crop controls.',
    fields: COVER_SCREENSHOT_TEMPLATE_FIELDS,
  },
  {
    id: 'screenshot-side',
    label: 'Screenshot side',
    description:
      'Product screenshot in a browser frame from the right with title copy on the left.',
    fields: COVER_SCREENSHOT_SIDE_TEMPLATE_FIELDS,
  },
  {
    id: 'screenshot-angled',
    label: 'Screenshot angled',
    description:
      'Product screenshot in a floating browser frame with a subtle perspective tilt.',
    fields: COVER_SCREENSHOT_ANGLED_TEMPLATE_FIELDS,
  },
  {
    id: 'cards-angled',
    label: 'Cards angled',
    description:
      'Grid of integration icon cards in a floating glass layout with perspective tilt.',
    fields: COVER_CARDS_ANGLED_TEMPLATE_FIELDS,
  },
  {
    id: 'table',
    label: 'Table',
    description: 'Glass card with a configurable comparison or feature table.',
    fields: COVER_TABLE_TEMPLATE_FIELDS,
  },
  {
    id: 'bar-chart',
    label: 'Bar chart',
    description: 'Glass card with a vertical bar chart for metrics and comparisons.',
    fields: COVER_CHART_TEMPLATE_FIELDS,
  },
  {
    id: 'line-chart',
    label: 'Line chart',
    description: 'Glass card with a line chart for trends over time.',
    fields: [
      ...COVER_CHART_TEMPLATE_FIELDS,
      {
        key: 'showArea',
        label: 'Show area fill',
        type: 'boolean',
        description: 'Fill the area under the line with a gradient.',
      },
    ],
  },
]

export function getCoverTemplateDefinition(templateId: string) {
  return COVER_TEMPLATE_DEFINITIONS.find((item) => item.id === templateId)
}

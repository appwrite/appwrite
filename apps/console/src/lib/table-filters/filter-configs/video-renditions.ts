/**
 * Filters for a single video's renditions list.
 *
 * `listRenditions` only accepts optional `output` and `status` query params (one
 * value each). The renditions view applies FiltersPopover conditions client-side
 * on the full list returned by the API.
 */

import type { FilterColumn } from '../types'

const RENDITION_OUTPUT_ELEMENTS = [
  { value: 'hls', label: 'HLS' },
  { value: 'dash', label: 'DASH' },
  { value: 'cmaf', label: 'CMAF' },
]

const RENDITION_STATUS_ELEMENTS = [
  { value: 'pending', label: 'Pending' },
  { value: 'started', label: 'Started' },
  { value: 'ended', label: 'Ended' },
  { value: 'uploading', label: 'Uploading' },
  { value: 'ready', label: 'Ready' },
  { value: 'error', label: 'Failed' },
  { value: 'aborted', label: 'Aborted' },
]

export const videoRenditionsFilterColumns: FilterColumn[] = [
  { id: '$id', title: 'Rendition ID', type: 'string' },
  { id: 'name', title: 'Name', type: 'string' },
  { id: 'profileId', title: 'Profile ID', type: 'string' },
  { id: 'profileName', title: 'Profile name', type: 'string' },
  {
    id: 'output',
    title: 'Output',
    type: 'enum',
    format: 'enum',
    elements: RENDITION_OUTPUT_ELEMENTS,
    optional: false,
  },
  {
    id: 'status',
    title: 'Status',
    type: 'enum',
    format: 'enum',
    elements: RENDITION_STATUS_ELEMENTS,
    optional: false,
  },
  { id: 'width', title: 'Width', type: 'integer' },
  { id: 'height', title: 'Height', type: 'integer' },
  { id: 'videoBitRate', title: 'Video bitrate (kbps)', type: 'integer' },
  { id: 'audioBitRate', title: 'Audio bitrate (kbps)', type: 'integer' },
  { id: 'progress', title: 'Progress', type: 'string' },
  { id: 'targetDuration', title: 'Segment length', type: 'string' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
  { id: '$updatedAt', title: '$updatedAt', type: 'datetime' },
  { id: 'startedAt', title: 'Started at', type: 'datetime' },
  { id: 'endedAt', title: 'Ended at', type: 'datetime' },
]

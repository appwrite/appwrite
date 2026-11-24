/**
 * Client-side filters for the project encoding profiles list (API: `GET /project/profiles`).
 */

import type { FilterColumn } from '../types'

export const videoProfilesFilterColumns: FilterColumn[] = [
  { id: '$id', title: 'Profile ID', type: 'string' },
  { id: 'name', title: 'Name', type: 'string' },
  { id: 'width', title: 'Width', type: 'integer' },
  { id: 'height', title: 'Height', type: 'integer' },
  { id: 'videoBitRate', title: 'Video bitrate (kbps)', type: 'integer' },
  { id: 'audioBitRate', title: 'Audio bitrate (kbps)', type: 'integer' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
  { id: '$updatedAt', title: '$updatedAt', type: 'datetime' },
]

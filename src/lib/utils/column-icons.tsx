import {
  Type,
  Hash,
  ToggleLeft,
  Calendar,
  Mail,
  Link2,
  MapPin,
  Columns3,
  FileJson,
  Route,
  Layers,
  type LucideIcon,
} from 'lucide-react'

/**
 * Get the appropriate icon component for a column type
 *
 * @param type - The column type (e.g., 'string', 'integer', 'point', etc.)
 * @returns The Lucide icon component for that column type
 */
export function getColumnIcon(type: string): LucideIcon {
  switch (type) {
    case 'string':
    case 'varchar':
    case 'text':
    case 'mediumtext':
    case 'longtext':
      return Type
    case 'integer':
    case 'float':
    case 'double':
      return Hash
    case 'boolean':
      return ToggleLeft
    case 'datetime':
      return Calendar
    case 'email':
      return Mail
    case 'url':
      return Link2
    case 'ip':
      return MapPin
    case 'enum':
      return Columns3
    case 'point':
      return MapPin
    case 'linestring':
      return Route
    case 'polygon':
      return Layers
    default:
      return FileJson
  }
}

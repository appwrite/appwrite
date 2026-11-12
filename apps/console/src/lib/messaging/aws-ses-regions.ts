/**
 * Amazon SES API regions.
 *
 * The Console SDK types `region` as a string. This list matches the SES
 * Service API endpoints table (not the SMTP-only subset):
 * https://docs.aws.amazon.com/general/latest/gr/ses.html
 */
export const AwsSesRegion = {
  UsEast1: 'us-east-1',
  UsEast2: 'us-east-2',
  UsWest1: 'us-west-1',
  UsWest2: 'us-west-2',
  AfSouth1: 'af-south-1',
  ApSouth1: 'ap-south-1',
  ApSouth2: 'ap-south-2',
  ApSoutheast1: 'ap-southeast-1',
  ApSoutheast2: 'ap-southeast-2',
  ApSoutheast3: 'ap-southeast-3',
  ApSoutheast5: 'ap-southeast-5',
  ApNortheast1: 'ap-northeast-1',
  ApNortheast2: 'ap-northeast-2',
  ApNortheast3: 'ap-northeast-3',
  CaCentral1: 'ca-central-1',
  CaWest1: 'ca-west-1',
  EuCentral1: 'eu-central-1',
  EuCentral2: 'eu-central-2',
  EuWest1: 'eu-west-1',
  EuWest2: 'eu-west-2',
  EuWest3: 'eu-west-3',
  EuNorth1: 'eu-north-1',
  EuSouth1: 'eu-south-1',
  IlCentral1: 'il-central-1',
  MeSouth1: 'me-south-1',
  MeCentral1: 'me-central-1',
  SaEast1: 'sa-east-1',
  UsGovEast1: 'us-gov-east-1',
  UsGovWest1: 'us-gov-west-1',
} as const

export type AwsSesRegion = (typeof AwsSesRegion)[keyof typeof AwsSesRegion]

export const DEFAULT_AWS_SES_REGION = AwsSesRegion.UsEast1

export type AwsSesRegionOption = {
  value: AwsSesRegion
  name: string
}

/** Geographic names from AWS. `us-east-1` is first because it is the default. */
export const AWS_SES_REGION_OPTIONS: readonly AwsSesRegionOption[] = [
  { value: AwsSesRegion.UsEast1, name: 'US East (N. Virginia)' },
  { value: AwsSesRegion.UsEast2, name: 'US East (Ohio)' },
  { value: AwsSesRegion.UsWest1, name: 'US West (N. California)' },
  { value: AwsSesRegion.UsWest2, name: 'US West (Oregon)' },
  { value: AwsSesRegion.AfSouth1, name: 'Africa (Cape Town)' },
  { value: AwsSesRegion.ApSouth2, name: 'Asia Pacific (Hyderabad)' },
  { value: AwsSesRegion.ApSoutheast3, name: 'Asia Pacific (Jakarta)' },
  { value: AwsSesRegion.ApSoutheast5, name: 'Asia Pacific (Malaysia)' },
  { value: AwsSesRegion.ApSouth1, name: 'Asia Pacific (Mumbai)' },
  { value: AwsSesRegion.ApNortheast3, name: 'Asia Pacific (Osaka)' },
  { value: AwsSesRegion.ApNortheast2, name: 'Asia Pacific (Seoul)' },
  { value: AwsSesRegion.ApSoutheast1, name: 'Asia Pacific (Singapore)' },
  { value: AwsSesRegion.ApSoutheast2, name: 'Asia Pacific (Sydney)' },
  { value: AwsSesRegion.ApNortheast1, name: 'Asia Pacific (Tokyo)' },
  { value: AwsSesRegion.CaCentral1, name: 'Canada (Central)' },
  { value: AwsSesRegion.CaWest1, name: 'Canada West (Calgary)' },
  { value: AwsSesRegion.EuCentral1, name: 'Europe (Frankfurt)' },
  { value: AwsSesRegion.EuWest1, name: 'Europe (Ireland)' },
  { value: AwsSesRegion.EuWest2, name: 'Europe (London)' },
  { value: AwsSesRegion.EuSouth1, name: 'Europe (Milan)' },
  { value: AwsSesRegion.EuWest3, name: 'Europe (Paris)' },
  { value: AwsSesRegion.EuNorth1, name: 'Europe (Stockholm)' },
  { value: AwsSesRegion.EuCentral2, name: 'Europe (Zurich)' },
  { value: AwsSesRegion.IlCentral1, name: 'Israel (Tel Aviv)' },
  { value: AwsSesRegion.MeSouth1, name: 'Middle East (Bahrain)' },
  { value: AwsSesRegion.MeCentral1, name: 'Middle East (UAE)' },
  { value: AwsSesRegion.SaEast1, name: 'South America (São Paulo)' },
  { value: AwsSesRegion.UsGovEast1, name: 'AWS GovCloud (US-East)' },
  { value: AwsSesRegion.UsGovWest1, name: 'AWS GovCloud (US-West)' },
]

const AWS_SES_REGION_VALUES = new Set<string>(
  AWS_SES_REGION_OPTIONS.map((option) => option.value),
)

export function isAwsSesRegion(value: string): value is AwsSesRegion {
  return AWS_SES_REGION_VALUES.has(value)
}

export function formatAwsSesRegionLabel(option: {
  value: string
  name?: string
}): string {
  return option.name ? `${option.name} (${option.value})` : option.value
}

export type AwsSesRegionSelectOption = { value: string; label: string }

export const AWS_SES_REGION_SELECT_OPTIONS: AwsSesRegionSelectOption[] =
  AWS_SES_REGION_OPTIONS.map((option) => ({
    value: option.value,
    label: formatAwsSesRegionLabel(option),
  }))

/** Keep an unknown saved region selectable so existing providers still load. */
export function awsSesRegionSelectOptions(
  current?: string | null,
): AwsSesRegionSelectOption[] {
  const trimmed = current?.trim() ?? ''
  if (!trimmed || isAwsSesRegion(trimmed)) {
    return AWS_SES_REGION_SELECT_OPTIONS
  }
  return [{ value: trimmed, label: trimmed }, ...AWS_SES_REGION_SELECT_OPTIONS]
}

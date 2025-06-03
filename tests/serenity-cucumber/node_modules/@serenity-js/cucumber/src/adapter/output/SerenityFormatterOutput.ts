import type { OutputDescriptor } from './OutputDescriptor';

/**
 * @group Integration
 */
export interface SerenityFormatterOutput {
    get(): OutputDescriptor;
}

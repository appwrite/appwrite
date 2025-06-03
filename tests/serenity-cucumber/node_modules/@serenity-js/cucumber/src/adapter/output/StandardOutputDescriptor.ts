import type { OutputDescriptor } from './OutputDescriptor';

/**
 * @group Integration
 */
export class StandardOutputDescriptor implements OutputDescriptor {
    value(): string {
        return '';
    }

    cleanUp(): Promise<void> {
        return Promise.resolve();
    }
}

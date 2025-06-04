import type { JSONValue } from 'tiny-types';

export interface ClassDescriptor {
    moduleId: string;
    className: string;
    parameter: JSONValue;
}

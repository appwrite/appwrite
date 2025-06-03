import type { JSONObject } from 'tiny-types';

import { Tag } from './Tag';

/**
 * Indicates that execution of a given scene
 * has been retried for the `currentRetry`-th time.
 */
export class ExecutionRetriedTag extends Tag {
    static readonly Type = 'retry';

    static fromJSON(o: JSONObject): ExecutionRetriedTag {
        return new ExecutionRetriedTag(o.retry as number, o.description as string);
    }

    constructor(
        public readonly retry: number,
        public readonly description?: string,
    ) {
        super(description || `${ retry }${ nth(retry) } retry`, ExecutionRetriedTag.Type);
    }
}

/**
 * @private
 * @param n
 */
function nth(n): string {
    return ['st', 'nd', 'rd'][((n + 90) % 100 - 10) % 10 - 1] || 'th';
}

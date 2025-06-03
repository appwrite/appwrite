import { ensure } from './ensure';
import { deprecated } from './objects';

/**
 * @desc This function has been deprecated. Please use {@link ensure} instead
 * @deprecated
 */
export const check = deprecated('Please use `ensure` instead')(ensure);

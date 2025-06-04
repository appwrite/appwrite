import { TinyType } from '../TinyType';
import { Predicate } from './Predicate';

export function isEqualTo(expectedValue: TinyType): Predicate<TinyType>;
export function isEqualTo<T>(expectedValue: T): Predicate<T>;

/**
 * @desc Ensures that the `value` is equal to `expectedValue`.
 * This {@link Predicate} is typically used in combination with other {@link Predicate}s.
 *
 * @example <caption>Comparing Tiny Types</caption>
 * import { ensure, isEqualTo, TinyType, TinyTypeOf } from 'tiny-types';
 *
 * class AccountId         extends TinyTypeOf<number>() {}
 * class Command           extends TinyTypeOf<AccountId>() {}
 * class UpgradeAccount    extends Command {}
 *
 * class AccountsService {
 *     constructor(public readonly loggedInUser: AccountId) {}
 *     handle(command: Command) {
 *         ensure('AccountId', command.value, isEqualTo(this.loggedInUser));
 *     }
 *  }
 *
 * @example <caption>Comparing primitives</caption>
 * import { ensure, isEqualTo, TinyType } from 'tiny-types';
 *
 * class Admin extends TinyType {
 *     constructor(public readonly id: number) {
 *         ensure('Admin::id', id, isEqualTo(1));
 *     }
 * }
 *
 * @param {string | number | symbol | TinyType | object} expectedValue
 * @returns {Predicate<any>}
 */
export function isEqualTo<T>(expectedValue: T): Predicate<T> {
    return Predicate.to(`be equal to ${ String(expectedValue) }`, (value: any) =>
        (hasEquals(value) && hasEquals(expectedValue))
            ? value.equals(expectedValue)
            : value === expectedValue,
    );
}

function hasEquals(value: unknown): value is { equals: (another: any) => boolean } {
    return !! value && (value instanceof TinyType || (value as any).equals);
}

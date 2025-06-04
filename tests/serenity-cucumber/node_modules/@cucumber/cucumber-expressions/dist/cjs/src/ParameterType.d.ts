interface Constructor<T> extends Function {
    new (...args: unknown[]): T;
    prototype: T;
}
type Factory<T> = (...args: unknown[]) => T;
export type RegExps = StringOrRegExp | readonly StringOrRegExp[];
export type StringOrRegExp = string | RegExp;
export default class ParameterType<T> {
    readonly name: string | undefined;
    readonly type: Constructor<T> | Factory<T> | null;
    readonly useForSnippets?: boolean | undefined;
    readonly preferForRegexpMatch?: boolean | undefined;
    readonly builtin?: boolean | undefined;
    private transformFn;
    static compare(pt1: ParameterType<unknown>, pt2: ParameterType<unknown>): number;
    static checkParameterTypeName(typeName: string): void;
    static isValidParameterTypeName(typeName: string): boolean;
    regexpStrings: readonly string[];
    /**
     * @param name {String} the name of the type
     * @param regexps {Array.<RegExp | String>,RegExp,String} that matche the type
     * @param type {Function} the prototype (constructor) of the type. May be null.
     * @param transform {Function} function transforming string to another type. May be null.
     * @param useForSnippets {boolean} true if this should be used for snippets. Defaults to true.
     * @param preferForRegexpMatch {boolean} true if this is a preferential type. Defaults to false.
     * @param builtin whether or not this is a built-in type
     */
    constructor(name: string | undefined, regexps: RegExps, type: Constructor<T> | Factory<T> | null, transform?: (...match: string[]) => T | PromiseLike<T>, useForSnippets?: boolean | undefined, preferForRegexpMatch?: boolean | undefined, builtin?: boolean | undefined);
    transform(thisObj: unknown, groupValues: string[] | null): any;
}
export {};
//# sourceMappingURL=ParameterType.d.ts.map
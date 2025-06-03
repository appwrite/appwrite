import { Get } from 'type-fest';

type Maybe<T> = T | null | undefined;
type Preserve<T, U> = T extends U ? U : never;
type Optionals<T> = Extract<T, null | undefined>;
type Defined<T> = T extends undefined ? never : T;
type NotNull<T> = T extends null ? never : T;
type _<T> = T extends {} ? {
    [k in keyof T]: T[k];
} : T;
type Flags = 's' | 'd' | '';
type SetFlag<Old extends Flags, F extends Flags> = Exclude<Old, ''> | F;
type UnsetFlag<Old extends Flags, F extends Flags> = Exclude<Old, F> extends never ? '' : Exclude<Old, F>;
type ToggleDefault<F extends Flags, D> = Preserve<D, undefined> extends never ? SetFlag<F, 'd'> : UnsetFlag<F, 'd'>;
type ResolveFlags<T, F extends Flags, D = T> = Extract<F, 'd'> extends never ? T : D extends undefined ? T : Defined<T>;
type Concat<T, U> = NonNullable<T> & NonNullable<U> extends never ? never : (NonNullable<T> & NonNullable<U>) | Optionals<U>;

type Params = Record<string, unknown>;
declare class ValidationError extends Error {
    value: any;
    path?: string;
    type?: string;
    errors: string[];
    params?: Params;
    inner: ValidationError[];
    static formatError(message: string | ((params: Params) => string) | unknown, params: Params): any;
    static isError(err: any): err is ValidationError;
    constructor(errorOrErrors: string | ValidationError | readonly ValidationError[], value?: any, field?: string, type?: string);
}

type PanicCallback = (err: Error) => void;
type NextCallback = (err: ValidationError[] | ValidationError | null) => void;
type CreateErrorOptions = {
    path?: string;
    message?: Message<any>;
    params?: ExtraParams;
    type?: string;
};
type TestContext<TContext = {}> = {
    path: string;
    options: ValidateOptions<TContext>;
    originalValue: any;
    parent: any;
    from?: Array<{
        schema: ISchema<any, TContext>;
        value: any;
    }>;
    schema: any;
    resolve: <T>(value: T | Reference<T>) => T;
    createError: (params?: CreateErrorOptions) => ValidationError;
};
type TestFunction<T = unknown, TContext = {}> = (this: TestContext<TContext>, value: T, context: TestContext<TContext>) => void | boolean | ValidationError | Promise<boolean | ValidationError>;
type TestOptions<TSchema extends AnySchema = AnySchema> = {
    value: any;
    path?: string;
    options: InternalOptions;
    originalValue: any;
    schema: TSchema;
};
type TestConfig<TValue = unknown, TContext = {}> = {
    name?: string;
    message?: Message<any>;
    test: TestFunction<TValue, TContext>;
    params?: ExtraParams;
    exclusive?: boolean;
    skipAbsent?: boolean;
};
type Test = ((opts: TestOptions, panic: PanicCallback, next: NextCallback) => void) & {
    OPTIONS?: TestConfig;
};

declare class ReferenceSet extends Set<unknown | Reference> {
    describe(): unknown[];
    resolveAll(resolve: (v: unknown | Reference) => unknown): unknown[];
    clone(): ReferenceSet;
    merge(newItems: ReferenceSet, removeItems: ReferenceSet): ReferenceSet;
}

type SchemaSpec<TDefault> = {
    coerce: boolean;
    nullable: boolean;
    optional: boolean;
    default?: TDefault | (() => TDefault);
    abortEarly?: boolean;
    strip?: boolean;
    strict?: boolean;
    recursive?: boolean;
    label?: string | undefined;
    meta?: any;
};
type SchemaOptions<TType, TDefault> = {
    type: string;
    spec?: Partial<SchemaSpec<TDefault>>;
    check: (value: any) => value is NonNullable<TType>;
};
type AnySchema<TType = any, C = any, D = any, F extends Flags = Flags> = Schema<TType, C, D, F>;
interface CastOptions$1<C = {}> {
    parent?: any;
    context?: C;
    assert?: boolean;
    stripUnknown?: boolean;
    path?: string;
    resolved?: boolean;
}
interface CastOptionalityOptions<C = {}> extends Omit<CastOptions$1<C>, 'assert'> {
    /**
     * Whether or not to throw TypeErrors if casting fails to produce a valid type.
     * defaults to `true`. The `'ignore-optionality'` options is provided as a migration
     * path from pre-v1 where `schema.nullable().required()` was allowed. When provided
     * cast will only throw for values that are the wrong type *not* including `null` and `undefined`
     */
    assert: 'ignore-optionality';
}
type RunTest = (opts: TestOptions, panic: PanicCallback, next: NextCallback) => void;
type TestRunOptions = {
    tests: RunTest[];
    path?: string | undefined;
    options: InternalOptions;
    originalValue: any;
    value: any;
};
interface SchemaRefDescription {
    type: 'ref';
    key: string;
}
interface SchemaInnerTypeDescription extends SchemaDescription {
    innerType?: SchemaFieldDescription | SchemaFieldDescription[];
}
interface SchemaObjectDescription extends SchemaDescription {
    fields: Record<string, SchemaFieldDescription>;
}
interface SchemaLazyDescription {
    type: string;
    label?: string;
    meta: object | undefined;
}
type SchemaFieldDescription = SchemaDescription | SchemaRefDescription | SchemaObjectDescription | SchemaInnerTypeDescription | SchemaLazyDescription;
interface SchemaDescription {
    type: string;
    label?: string;
    meta: object | undefined;
    oneOf: unknown[];
    notOneOf: unknown[];
    default?: unknown;
    nullable: boolean;
    optional: boolean;
    tests: Array<{
        name?: string;
        params: ExtraParams | undefined;
    }>;
}
declare abstract class Schema<TType = any, TContext = any, TDefault = any, TFlags extends Flags = ''> implements ISchema<TType, TContext, TFlags, TDefault> {
    readonly type: string;
    readonly __outputType: ResolveFlags<TType, TFlags, TDefault>;
    readonly __context: TContext;
    readonly __flags: TFlags;
    readonly __isYupSchema__: boolean;
    readonly __default: TDefault;
    readonly deps: readonly string[];
    tests: Test[];
    transforms: TransformFunction<AnySchema>[];
    private conditions;
    private _mutate?;
    private internalTests;
    protected _whitelist: ReferenceSet;
    protected _blacklist: ReferenceSet;
    protected exclusiveTests: Record<string, boolean>;
    protected _typeCheck: (value: any) => value is NonNullable<TType>;
    spec: SchemaSpec<any>;
    constructor(options: SchemaOptions<TType, any>);
    get _type(): string;
    clone(spec?: Partial<SchemaSpec<any>>): this;
    label(label: string): this;
    meta(): Record<string, unknown> | undefined;
    meta(obj: Record<string, unknown>): this;
    withMutation<T>(fn: (schema: this) => T): T;
    concat(schema: this): this;
    concat(schema: AnySchema): AnySchema;
    isType(v: unknown): v is TType;
    resolve(options: ResolveOptions<TContext>): this;
    protected resolveOptions<T extends InternalOptions<any>>(options: T): T;
    /**
     * Run the configured transform pipeline over an input value.
     */
    cast(value: any, options?: CastOptions$1<TContext>): this['__outputType'];
    cast(value: any, options: CastOptionalityOptions<TContext>): this['__outputType'] | null | undefined;
    protected _cast(rawValue: any, options: CastOptions$1<TContext>): any;
    protected _validate(_value: any, options: InternalOptions<TContext> | undefined, panic: (err: Error, value: unknown) => void, next: (err: ValidationError[], value: unknown) => void): void;
    /**
     * Executes a set of validations, either schema, produced Tests or a nested
     * schema validate result.
     */
    protected runTests(runOptions: TestRunOptions, panic: (err: Error, value: unknown) => void, next: (errors: ValidationError[], value: unknown) => void): void;
    asNestedTest({ key, index, parent, parentPath, originalParent, options, }: NestedTestConfig): RunTest;
    validate(value: any, options?: ValidateOptions<TContext>): Promise<this['__outputType']>;
    validateSync(value: any, options?: ValidateOptions<TContext>): this['__outputType'];
    isValid(value: any, options?: ValidateOptions<TContext>): Promise<boolean>;
    isValidSync(value: any, options?: ValidateOptions<TContext>): value is this['__outputType'];
    protected _getDefault(options?: ResolveOptions<TContext>): any;
    getDefault(options?: ResolveOptions<TContext>): TDefault;
    default(def: DefaultThunk<any>): any;
    strict(isStrict?: boolean): this;
    protected nullability(nullable: boolean, message?: Message<any>): this;
    protected optionality(optional: boolean, message?: Message<any>): this;
    optional(): any;
    defined(message?: Message<any>): any;
    nullable(): any;
    nonNullable(message?: Message<any>): any;
    required(message?: Message<any>): any;
    notRequired(): any;
    transform(fn: TransformFunction<this>): this;
    /**
     * Adds a test function to the schema's queue of tests.
     * tests can be exclusive or non-exclusive.
     *
     * - exclusive tests, will replace any existing tests of the same name.
     * - non-exclusive: can be stacked
     *
     * If a non-exclusive test is added to a schema with an exclusive test of the same name
     * the exclusive test is removed and further tests of the same name will be stacked.
     *
     * If an exclusive test is added to a schema with non-exclusive tests of the same name
     * the previous tests are removed and further tests of the same name will replace each other.
     */
    test(options: TestConfig<this['__outputType'], TContext>): this;
    test(test: TestFunction<this['__outputType'], TContext>): this;
    test(name: string, test: TestFunction<this['__outputType'], TContext>): this;
    test(name: string, message: Message, test: TestFunction<this['__outputType'], TContext>): this;
    when(builder: ConditionBuilder<this>): this;
    when(keys: string | string[], builder: ConditionBuilder<this>): this;
    when(options: ConditionConfig<this>): this;
    when(keys: string | string[], options: ConditionConfig<this>): this;
    typeError(message: Message): this;
    oneOf<U extends TType>(enums: ReadonlyArray<U | Reference>, message?: Message<{
        values: any;
    }>): this;
    oneOf(enums: ReadonlyArray<TType | Reference>, message: Message<{
        values: any;
    }>): any;
    notOneOf<U extends TType>(enums: ReadonlyArray<Maybe<U> | Reference>, message?: Message<{
        values: any;
    }>): this;
    strip(strip?: boolean): any;
    /**
     * Return a serialized description of the schema including validations, flags, types etc.
     *
     * @param options Provide any needed context for resolving runtime schema alterations (lazy, when conditions, etc).
     */
    describe(options?: ResolveOptions<TContext>): SchemaDescription;
}
interface Schema<TType = any, TContext = any, TDefault = any, TFlags extends Flags = ''> {
    validateAt(path: string, value: any, options?: ValidateOptions<TContext>): Promise<any>;
    validateSyncAt(path: string, value: any, options?: ValidateOptions<TContext>): any;
    equals: Schema['oneOf'];
    is: Schema['oneOf'];
    not: Schema['notOneOf'];
    nope: Schema['notOneOf'];
}

type ReferenceOptions<TValue = unknown> = {
    map?: (value: unknown) => TValue;
};
declare function create$9<TValue = unknown>(key: string, options?: ReferenceOptions<TValue>): Reference<TValue>;
declare class Reference<TValue = unknown> {
    readonly key: string;
    readonly isContext: boolean;
    readonly isValue: boolean;
    readonly isSibling: boolean;
    readonly path: any;
    readonly getter: (data: unknown) => unknown;
    readonly map?: (value: unknown) => TValue;
    readonly __isYupRef: boolean;
    constructor(key: string, options?: ReferenceOptions<TValue>);
    getValue(value: any, parent?: {}, context?: {}): TValue;
    /**
     *
     * @param {*} value
     * @param {Object} options
     * @param {Object=} options.context
     * @param {Object=} options.parent
     */
    cast(value: any, options?: {
        parent?: {};
        context?: {};
    }): TValue;
    resolve(): this;
    describe(): SchemaRefDescription;
    toString(): string;
    static isRef(value: any): value is Reference;
}

type ConditionBuilder<T extends ISchema<any, any>> = (values: any[], schema: T, options: ResolveOptions) => ISchema<any>;
type ConditionConfig<T extends ISchema<any>> = {
    is: any | ((...values: any[]) => boolean);
    then?: (schema: T) => ISchema<any>;
    otherwise?: (schema: T) => ISchema<any>;
};
type ResolveOptions<TContext = any> = {
    value?: any;
    parent?: any;
    context?: TContext;
};

type ObjectShape = {
    [k: string]: ISchema<any> | Reference;
};
type AnyObject = {
    [k: string]: any;
};
type ResolveStrip<T extends ISchema<any>> = T extends ISchema<any, any, infer F> ? Extract<F, 's'> extends never ? T['__outputType'] : never : T['__outputType'];
type TypeFromShape<S extends ObjectShape, _C> = {
    [K in keyof S]: S[K] extends ISchema<any> ? ResolveStrip<S[K]> : S[K] extends Reference<infer T> ? T : unknown;
};
type DefaultFromShape<Shape extends ObjectShape> = {
    [K in keyof Shape]: Shape[K] extends ISchema<any> ? Shape[K]['__default'] : undefined;
};
type ConcatObjectTypes<T extends Maybe<AnyObject>, U extends Maybe<AnyObject>> = ({
    [P in keyof T]: P extends keyof NonNullable<U> ? NonNullable<U>[P] : T[P];
} & U) | Optionals<U>;
type PartialDeep<T> = T extends string | number | bigint | boolean | null | undefined | symbol | Date ? T | undefined : T extends Array<infer ArrayType> ? Array<PartialDeep<ArrayType>> : T extends ReadonlyArray<infer ArrayType> ? ReadonlyArray<ArrayType> : {
    [K in keyof T]?: PartialDeep<T[K]>;
};
type OptionalKeys<T extends {}> = {
    [k in keyof T]: undefined extends T[k] ? k : never;
}[keyof T];
type RequiredKeys<T extends object> = Exclude<keyof T, OptionalKeys<T>>;
type MakePartial<T extends object> = {
    [k in OptionalKeys<T> as T[k] extends never ? never : k]?: T[k];
} & {
    [k in RequiredKeys<T> as T[k] extends never ? never : k]: T[k];
};

interface ISchema<T, C = any, F extends Flags = any, D = any> {
    __flags: F;
    __context: C;
    __outputType: T;
    __default: D;
    cast(value: any, options?: CastOptions$1<C>): T;
    cast(value: any, options: CastOptionalityOptions<C>): T | null | undefined;
    validate(value: any, options?: ValidateOptions<C>): Promise<T>;
    asNestedTest(config: NestedTestConfig): Test;
    describe(options?: ResolveOptions<C>): SchemaFieldDescription;
    resolve(options: ResolveOptions<C>): ISchema<T, C, F>;
}
type DefaultThunk<T, C = any> = T | ((options?: ResolveOptions<C>) => T);
type InferType<T extends ISchema<any, any>> = T['__outputType'];
type TransformFunction<T extends AnySchema> = (this: T, value: any, originalValue: any, schema: T) => any;
interface Ancester<TContext> {
    schema: ISchema<any, TContext>;
    value: any;
}
interface ValidateOptions<TContext = {}> {
    /**
     * Only validate the input, skipping type casting and transformation. Default - false
     */
    strict?: boolean;
    /**
     * Return from validation methods on the first error rather than after all validations run. Default - true
     */
    abortEarly?: boolean;
    /**
     * Remove unspecified keys from objects. Default - false
     */
    stripUnknown?: boolean;
    /**
     * When false validations will not descend into nested schema (relevant for objects or arrays). Default - true
     */
    recursive?: boolean;
    /**
     * Any context needed for validating schema conditions (see: when())
     */
    context?: TContext;
}
interface InternalOptions<TContext = any> extends ValidateOptions<TContext> {
    __validating?: boolean;
    originalValue?: any;
    index?: number;
    key?: string;
    parent?: any;
    path?: string;
    sync?: boolean;
    from?: Ancester<TContext>[];
}
interface MessageParams {
    path: string;
    value: any;
    originalValue: any;
    label: string;
    type: string;
    spec: SchemaSpec<any> & Record<string, unknown>;
}
type Message<Extra extends Record<string, unknown> = any> = string | ((params: Extra & MessageParams) => unknown) | Record<PropertyKey, unknown>;
type ExtraParams = Record<string, unknown>;
interface NestedTestConfig {
    options: InternalOptions<any>;
    parent: any;
    originalParent: any;
    parentPath: string | undefined;
    key?: string;
    index?: number;
}

type AnyPresentValue = {};
type TypeGuard<TType> = (value: any) => value is NonNullable<TType>;
interface MixedOptions<TType> {
    type?: string;
    check?: TypeGuard<TType>;
}
declare function create$8<TType extends AnyPresentValue>(spec?: MixedOptions<TType> | TypeGuard<TType>): MixedSchema<TType | undefined, AnyObject, undefined, "">;
declare namespace create$8 {
    var prototype: MixedSchema<any, any, any, any>;
}
declare class MixedSchema<TType extends Maybe<AnyPresentValue> = AnyPresentValue | undefined, TContext = AnyObject, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TType, TContext, TDefault, TFlags> {
    constructor(spec?: MixedOptions<TType> | TypeGuard<TType>);
}
interface MixedSchema<TType extends Maybe<AnyPresentValue> = AnyPresentValue | undefined, TContext = AnyObject, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TType, TContext, TDefault, TFlags> {
    default<D extends Maybe<TType>>(def: DefaultThunk<D, TContext>): MixedSchema<TType, TContext, D, ToggleDefault<TFlags, D>>;
    concat<IT, IC, ID, IF extends Flags>(schema: MixedSchema<IT, IC, ID, IF>): MixedSchema<Concat<TType, IT>, TContext & IC, ID, TFlags | IF>;
    concat<IT, IC, ID, IF extends Flags>(schema: Schema<IT, IC, ID, IF>): MixedSchema<Concat<TType, IT>, TContext & IC, ID, TFlags | IF>;
    concat(schema: this): this;
    defined(msg?: Message): MixedSchema<Defined<TType>, TContext, TDefault, TFlags>;
    optional(): MixedSchema<TType | undefined, TContext, TDefault, TFlags>;
    required(msg?: Message): MixedSchema<NonNullable<TType>, TContext, TDefault, TFlags>;
    notRequired(): MixedSchema<Maybe<TType>, TContext, TDefault, TFlags>;
    nullable(msg?: Message): MixedSchema<TType | null, TContext, TDefault, TFlags>;
    nonNullable(): MixedSchema<Exclude<TType, null>, TContext, TDefault, TFlags>;
    strip(enabled: false): MixedSchema<TType, TContext, TDefault, UnsetFlag<TFlags, 's'>>;
    strip(enabled?: true): MixedSchema<TType, TContext, TDefault, SetFlag<TFlags, 's'>>;
}

declare function create$7(): BooleanSchema;
declare function create$7<T extends boolean, TContext extends Maybe<AnyObject> = AnyObject>(): BooleanSchema<T | undefined, TContext>;
declare namespace create$7 {
    var prototype: BooleanSchema<any, any, any, any>;
}
declare class BooleanSchema<TType extends Maybe<boolean> = boolean | undefined, TContext = AnyObject, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TType, TContext, TDefault, TFlags> {
    constructor();
    isTrue(message?: Message<any> | undefined): BooleanSchema<true | Optionals<TType>, TContext, TFlags>;
    isFalse(message?: Message<any> | undefined): BooleanSchema<false | Optionals<TType>, TContext, TFlags>;
    default<D extends Maybe<TType>>(def: DefaultThunk<D, TContext>): BooleanSchema<TType, TContext, D, ToggleDefault<TFlags, D>>;
    defined(msg?: Message): BooleanSchema<Defined<TType>, TContext, TDefault, TFlags>;
    optional(): BooleanSchema<TType | undefined, TContext, TDefault, TFlags>;
    required(msg?: Message): BooleanSchema<NonNullable<TType>, TContext, TDefault, TFlags>;
    notRequired(): BooleanSchema<Maybe<TType>, TContext, TDefault, TFlags>;
    nullable(): BooleanSchema<TType | null, TContext, TDefault, TFlags>;
    nonNullable(msg?: Message): BooleanSchema<NotNull<TType>, TContext, TDefault, TFlags>;
    strip(enabled: false): BooleanSchema<TType, TContext, TDefault, UnsetFlag<TFlags, 's'>>;
    strip(enabled?: true): BooleanSchema<TType, TContext, TDefault, SetFlag<TFlags, 's'>>;
}

interface MixedLocale {
    default?: Message;
    required?: Message;
    oneOf?: Message<{
        values: any;
    }>;
    notOneOf?: Message<{
        values: any;
    }>;
    notNull?: Message;
    notType?: Message;
    defined?: Message;
}
interface StringLocale {
    length?: Message<{
        length: number;
    }>;
    min?: Message<{
        min: number;
    }>;
    max?: Message<{
        max: number;
    }>;
    matches?: Message<{
        regex: RegExp;
    }>;
    email?: Message<{
        regex: RegExp;
    }>;
    url?: Message<{
        regex: RegExp;
    }>;
    uuid?: Message<{
        regex: RegExp;
    }>;
    trim?: Message;
    lowercase?: Message;
    uppercase?: Message;
}
interface NumberLocale {
    min?: Message<{
        min: number;
    }>;
    max?: Message<{
        max: number;
    }>;
    lessThan?: Message<{
        less: number;
    }>;
    moreThan?: Message<{
        more: number;
    }>;
    positive?: Message<{
        more: number;
    }>;
    negative?: Message<{
        less: number;
    }>;
    integer?: Message;
}
interface DateLocale {
    min?: Message<{
        min: Date | string;
    }>;
    max?: Message<{
        max: Date | string;
    }>;
}
interface ObjectLocale {
    noUnknown?: Message;
}
interface ArrayLocale {
    length?: Message<{
        length: number;
    }>;
    min?: Message<{
        min: number;
    }>;
    max?: Message<{
        max: number;
    }>;
}
interface BooleanLocale {
    isValue?: Message;
}
interface LocaleObject {
    mixed?: MixedLocale;
    string?: StringLocale;
    number?: NumberLocale;
    date?: DateLocale;
    boolean?: BooleanLocale;
    object?: ObjectLocale;
    array?: ArrayLocale;
}
declare const _default: LocaleObject;

type MatchOptions = {
    excludeEmptyString?: boolean;
    message: Message<{
        regex: RegExp;
    }>;
    name?: string;
};
declare function create$6(): StringSchema;
declare function create$6<T extends string, TContext extends Maybe<AnyObject> = AnyObject>(): StringSchema<T | undefined, TContext>;
declare namespace create$6 {
    var prototype: StringSchema<any, any, any, any>;
}

declare class StringSchema<TType extends Maybe<string> = string | undefined, TContext = AnyObject, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TType, TContext, TDefault, TFlags> {
    constructor();
    length(length: number | Reference<number>, message?: Message<{
        length: number;
    }>): this;
    min(min: number | Reference<number>, message?: Message<{
        min: number;
    }>): this;
    max(max: number | Reference<number>, message?: Message<{
        max: number;
    }>): this;
    matches(regex: RegExp, options?: MatchOptions | MatchOptions['message']): this;
    email(message?: Message<{
        regex: RegExp;
    }>): this;
    url(message?: Message<{
        regex: RegExp;
    }>): this;
    uuid(message?: Message<{
        regex: RegExp;
    }>): this;
    ensure(): StringSchema<NonNullable<TType>>;
    trim(message?: Message<any>): this;
    lowercase(message?: Message<any>): this;
    uppercase(message?: Message<any>): this;
}
interface StringSchema<TType extends Maybe<string> = string | undefined, TContext = AnyObject, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TType, TContext, TDefault, TFlags> {
    default<D extends Maybe<TType>>(def: DefaultThunk<D, TContext>): StringSchema<TType, TContext, D, ToggleDefault<TFlags, D>>;
    oneOf<U extends TType>(arrayOfValues: ReadonlyArray<U | Reference<U>>, message?: MixedLocale['oneOf']): StringSchema<U | Optionals<TType>, TContext, TDefault, TFlags>;
    oneOf(enums: ReadonlyArray<TType | Reference>, message?: Message<{
        values: any;
    }>): this;
    concat<UType extends Maybe<string>, UContext, UDefault, UFlags extends Flags>(schema: StringSchema<UType, UContext, UDefault, UFlags>): StringSchema<Concat<TType, UType>, TContext & UContext, UDefault, TFlags | UFlags>;
    concat(schema: this): this;
    defined(msg?: Message): StringSchema<Defined<TType>, TContext, TDefault, TFlags>;
    optional(): StringSchema<TType | undefined, TContext, TDefault, TFlags>;
    required(msg?: Message): StringSchema<NonNullable<TType>, TContext, TDefault, TFlags>;
    notRequired(): StringSchema<Maybe<TType>, TContext, TDefault, TFlags>;
    nullable(msg?: Message<any>): StringSchema<TType | null, TContext, TDefault, TFlags>;
    nonNullable(): StringSchema<NotNull<TType>, TContext, TDefault, TFlags>;
    strip(enabled: false): StringSchema<TType, TContext, TDefault, UnsetFlag<TFlags, 's'>>;
    strip(enabled?: true): StringSchema<TType, TContext, TDefault, SetFlag<TFlags, 's'>>;
}

declare function create$5(): NumberSchema;
declare function create$5<T extends number, TContext extends Maybe<AnyObject> = AnyObject>(): NumberSchema<T | undefined, TContext>;
declare namespace create$5 {
    var prototype: NumberSchema<any, any, any, any>;
}
declare class NumberSchema<TType extends Maybe<number> = number | undefined, TContext = AnyObject, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TType, TContext, TDefault, TFlags> {
    constructor();
    min(min: number | Reference<number>, message?: Message<{
        min: number;
    }>): this;
    max(max: number | Reference<number>, message?: Message<{
        max: number;
    }>): this;
    lessThan(less: number | Reference<number>, message?: Message<{
        less: number;
    }>): this;
    moreThan(more: number | Reference<number>, message?: Message<{
        more: number;
    }>): this;
    positive(msg?: Message<{
        more: number;
    }>): this;
    negative(msg?: Message<{
        less: number;
    }>): this;
    integer(message?: Message<any>): this;
    truncate(): this;
    round(method?: 'ceil' | 'floor' | 'round' | 'trunc'): this;
}
interface NumberSchema<TType extends Maybe<number> = number | undefined, TContext = AnyObject, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TType, TContext, TDefault, TFlags> {
    default<D extends Maybe<TType>>(def: DefaultThunk<D, TContext>): NumberSchema<TType, TContext, D, ToggleDefault<TFlags, D>>;
    concat<UType extends Maybe<number>, UContext, UFlags extends Flags, UDefault>(schema: NumberSchema<UType, UContext, UDefault, UFlags>): NumberSchema<Concat<TType, UType>, TContext & UContext, UDefault, TFlags | UFlags>;
    concat(schema: this): this;
    defined(msg?: Message): NumberSchema<Defined<TType>, TContext, TDefault, TFlags>;
    optional(): NumberSchema<TType | undefined, TContext, TDefault, TFlags>;
    required(msg?: Message): NumberSchema<NonNullable<TType>, TContext, TDefault, TFlags>;
    notRequired(): NumberSchema<Maybe<TType>, TContext, TDefault, TFlags>;
    nullable(msg?: Message): NumberSchema<TType | null, TContext, TDefault, TFlags>;
    nonNullable(): NumberSchema<NotNull<TType>, TContext, TDefault, TFlags>;
    strip(enabled: false): NumberSchema<TType, TContext, TDefault, UnsetFlag<TFlags, 's'>>;
    strip(enabled?: true): NumberSchema<TType, TContext, TDefault, SetFlag<TFlags, 's'>>;
}

declare function create$4(): DateSchema;
declare function create$4<T extends Date, TContext extends Maybe<AnyObject> = AnyObject>(): DateSchema<T | undefined, TContext>;
declare namespace create$4 {
    var prototype: DateSchema<any, any, any, any>;
    var INVALID_DATE: Date;
}
declare class DateSchema<TType extends Maybe<Date> = Date | undefined, TContext = AnyObject, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TType, TContext, TDefault, TFlags> {
    static INVALID_DATE: Date;
    constructor();
    private prepareParam;
    min(min: unknown | Reference<Date>, message?: Message<{
        min: string | Date;
    }>): this;
    max(max: unknown | Reference, message?: Message<{
        max: string | Date;
    }>): this;
}
interface DateSchema<TType extends Maybe<Date>, TContext = AnyObject, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TType, TContext, TDefault, TFlags> {
    default<D extends Maybe<TType>>(def: DefaultThunk<D, TContext>): DateSchema<TType, TContext, D, ToggleDefault<TFlags, D>>;
    concat<TOther extends DateSchema<any, any>>(schema: TOther): TOther;
    defined(msg?: Message): DateSchema<Defined<TType>, TContext, TDefault, TFlags>;
    optional(): DateSchema<TType | undefined, TContext, TDefault, TFlags>;
    required(msg?: Message): DateSchema<NonNullable<TType>, TContext, TDefault, TFlags>;
    notRequired(): DateSchema<Maybe<TType>, TContext, TDefault, TFlags>;
    nullable(msg?: Message): DateSchema<TType | null, TContext, TDefault, TFlags>;
    nonNullable(): DateSchema<NotNull<TType>, TContext, TDefault, TFlags>;
    strip(enabled: false): DateSchema<TType, TContext, TDefault, UnsetFlag<TFlags, 's'>>;
    strip(enabled?: true): DateSchema<TType, TContext, TDefault, SetFlag<TFlags, 's'>>;
}

type MakeKeysOptional<T> = T extends AnyObject ? _<MakePartial<T>> : T;
type Shape<T extends Maybe<AnyObject>, C = any> = {
    [field in keyof T]-?: ISchema<T[field], C> | Reference;
};
type ObjectSchemaSpec = SchemaSpec<any> & {
    noUnknown?: boolean;
};
declare function create$3<C extends Maybe<AnyObject> = AnyObject, S extends ObjectShape = {}>(spec?: S): ObjectSchema<_<TypeFromShape<S, C>>, C, _<DefaultFromShape<S>>, "">;
declare namespace create$3 {
    var prototype: ObjectSchema<any, any, any, any>;
}
interface ObjectSchema<TIn extends Maybe<AnyObject>, TContext = AnyObject, TDefault = any, TFlags extends Flags = ''> extends Schema<MakeKeysOptional<TIn>, TContext, TDefault, TFlags> {
    default<D extends Maybe<AnyObject>>(def: DefaultThunk<D, TContext>): ObjectSchema<TIn, TContext, D, ToggleDefault<TFlags, 'd'>>;
    defined(msg?: Message): ObjectSchema<Defined<TIn>, TContext, TDefault, TFlags>;
    optional(): ObjectSchema<TIn | undefined, TContext, TDefault, TFlags>;
    required(msg?: Message): ObjectSchema<NonNullable<TIn>, TContext, TDefault, TFlags>;
    notRequired(): ObjectSchema<Maybe<TIn>, TContext, TDefault, TFlags>;
    nullable(msg?: Message): ObjectSchema<TIn | null, TContext, TDefault, TFlags>;
    nonNullable(): ObjectSchema<NotNull<TIn>, TContext, TDefault, TFlags>;
    strip(enabled: false): ObjectSchema<TIn, TContext, TDefault, UnsetFlag<TFlags, 's'>>;
    strip(enabled?: true): ObjectSchema<TIn, TContext, TDefault, SetFlag<TFlags, 's'>>;
}
declare class ObjectSchema<TIn extends Maybe<AnyObject>, TContext = AnyObject, TDefault = any, TFlags extends Flags = ''> extends Schema<MakeKeysOptional<TIn>, TContext, TDefault, TFlags> {
    fields: Shape<NonNullable<TIn>, TContext>;
    spec: ObjectSchemaSpec;
    private _sortErrors;
    private _nodes;
    private _excludedEdges;
    constructor(spec?: Shape<TIn, TContext>);
    protected _cast(_value: any, options?: InternalOptions<TContext>): any;
    protected _validate(_value: any, options: InternalOptions<TContext> | undefined, panic: (err: Error, value: unknown) => void, next: (err: ValidationError[], value: unknown) => void): void;
    clone(spec?: ObjectSchemaSpec): this;
    concat<IIn extends Maybe<AnyObject>, IC, ID, IF extends Flags>(schema: ObjectSchema<IIn, IC, ID, IF>): ObjectSchema<ConcatObjectTypes<TIn, IIn>, TContext & IC, Extract<IF, 'd'> extends never ? TDefault extends AnyObject ? ID extends AnyObject ? _<ConcatObjectTypes<TDefault, ID>> : ID : ID : ID, TFlags | IF>;
    concat(schema: this): this;
    protected _getDefault(options?: ResolveOptions<TContext>): any;
    private setFields;
    shape<U extends ObjectShape>(additions: U, excludes?: readonly [string, string][]): ObjectSchema<_<{ [P in keyof TIn]: P extends keyof U ? TypeFromShape<U, TContext>[P] : TIn[P]; } & TypeFromShape<U, TContext>> | _<Extract<TIn, null | undefined>>, TContext, Extract<TFlags, "d"> extends never ? _<TDefault & DefaultFromShape<U>> : TDefault, TFlags>;
    partial(): ObjectSchema<Partial<TIn>, TContext, TDefault, TFlags>;
    deepPartial(): ObjectSchema<PartialDeep<TIn>, TContext, TDefault, TFlags>;
    pick<TKey extends keyof TIn>(keys: readonly TKey[]): ObjectSchema<{ [K in TKey]: TIn[K]; }, TContext, TDefault, TFlags>;
    omit<TKey extends keyof TIn>(keys: readonly TKey[]): ObjectSchema<Omit<TIn, TKey>, TContext, TDefault, TFlags>;
    from(from: string, to: keyof TIn, alias?: boolean): this;
    /** Parse an input JSON string to an object */
    json(): this;
    noUnknown(message?: Message): this;
    noUnknown(noAllow: boolean, message?: Message): this;
    unknown(allow?: boolean, message?: Message<any>): this;
    transformKeys(fn: (key: string) => string): this;
    camelCase(): this;
    snakeCase(): this;
    constantCase(): this;
    describe(options?: ResolveOptions<TContext>): SchemaObjectDescription;
}

type InnerType<T> = T extends Array<infer I> ? I : never;
type RejectorFn = (value: any, index: number, array: readonly any[]) => boolean;
declare function create$2<C extends Maybe<AnyObject> = AnyObject, T = any>(type?: ISchema<T, C>): ArraySchema<T[] | undefined, C, undefined, "">;
declare namespace create$2 {
    var prototype: ArraySchema<any, any, any, any>;
}
interface ArraySchemaSpec<TIn, TContext> extends SchemaSpec<any> {
    types?: ISchema<InnerType<TIn>, TContext>;
}
declare class ArraySchema<TIn extends any[] | null | undefined, TContext, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TIn, TContext, TDefault, TFlags> {
    spec: ArraySchemaSpec<TIn, TContext>;
    readonly innerType?: ISchema<InnerType<TIn>, TContext>;
    constructor(type?: ISchema<InnerType<TIn>, TContext>);
    protected _cast(_value: any, _opts: InternalOptions<TContext>): any;
    protected _validate(_value: any, options: InternalOptions<TContext> | undefined, panic: (err: Error, value: unknown) => void, next: (err: ValidationError[], value: unknown) => void): void;
    clone(spec?: SchemaSpec<any>): this;
    /** Parse an input JSON string to an object */
    json(): this;
    concat<IIn extends Maybe<any[]>, IC, ID, IF extends Flags>(schema: ArraySchema<IIn, IC, ID, IF>): ArraySchema<Concat<TIn, IIn>, TContext & IC, Extract<IF, 'd'> extends never ? TDefault : ID, TFlags | IF>;
    concat(schema: this): this;
    of<U>(schema: ISchema<U, TContext>): ArraySchema<U[] | Optionals<TIn>, TContext, TFlags>;
    length(length: number | Reference<number>, message?: Message<{
        length: number;
    }>): this;
    min(min: number | Reference<number>, message?: Message<{
        min: number;
    }>): this;
    max(max: number | Reference<number>, message?: Message<{
        max: number;
    }>): this;
    ensure(): ArraySchema<TIn, TContext, TIn, ToggleDefault<TFlags, TIn>>;
    compact(rejector?: RejectorFn): this;
    describe(options?: ResolveOptions<TContext>): SchemaInnerTypeDescription;
}
interface ArraySchema<TIn extends any[] | null | undefined, TContext, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TIn, TContext, TDefault, TFlags> {
    default<D extends Maybe<TIn>>(def: DefaultThunk<D, TContext>): ArraySchema<TIn, TContext, D, ToggleDefault<TFlags, D>>;
    defined(msg?: Message): ArraySchema<Defined<TIn>, TContext, TDefault, TFlags>;
    optional(): ArraySchema<TIn | undefined, TContext, TDefault, TFlags>;
    required(msg?: Message): ArraySchema<NonNullable<TIn>, TContext, TDefault, TFlags>;
    notRequired(): ArraySchema<Maybe<TIn>, TContext, TDefault, TFlags>;
    nullable(msg?: Message): ArraySchema<TIn | null, TContext, TDefault, TFlags>;
    nonNullable(): ArraySchema<NotNull<TIn>, TContext, TDefault, TFlags>;
    strip(enabled: false): ArraySchema<TIn, TContext, TDefault, UnsetFlag<TFlags, 's'>>;
    strip(enabled?: true): ArraySchema<TIn, TContext, TDefault, SetFlag<TFlags, 's'>>;
}

type AnyTuple = [unknown, ...unknown[]];
declare function create$1<T extends AnyTuple>(schemas: {
    [K in keyof T]: ISchema<T[K]>;
}): TupleSchema<T | undefined, AnyObject, undefined, "">;
declare namespace create$1 {
    var prototype: TupleSchema<any, any, any, any>;
}
interface TupleSchemaSpec<T> extends SchemaSpec<any> {
    types: T extends any[] ? {
        [K in keyof T]: ISchema<T[K]>;
    } : never;
}
interface TupleSchema<TType extends Maybe<AnyTuple> = AnyTuple | undefined, TContext = AnyObject, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TType, TContext, TDefault, TFlags> {
    default<D extends Maybe<TType>>(def: DefaultThunk<D, TContext>): TupleSchema<TType, TContext, D, ToggleDefault<TFlags, D>>;
    concat<TOther extends TupleSchema<any, any>>(schema: TOther): TOther;
    defined(msg?: Message): TupleSchema<Defined<TType>, TContext, TDefault, TFlags>;
    optional(): TupleSchema<TType | undefined, TContext, TDefault, TFlags>;
    required(msg?: Message): TupleSchema<NonNullable<TType>, TContext, TDefault, TFlags>;
    notRequired(): TupleSchema<Maybe<TType>, TContext, TDefault, TFlags>;
    nullable(msg?: Message): TupleSchema<TType | null, TContext, TDefault, TFlags>;
    nonNullable(): TupleSchema<NotNull<TType>, TContext, TDefault, TFlags>;
    strip(enabled: false): TupleSchema<TType, TContext, TDefault, UnsetFlag<TFlags, 's'>>;
    strip(enabled?: true): TupleSchema<TType, TContext, TDefault, SetFlag<TFlags, 's'>>;
}
declare class TupleSchema<TType extends Maybe<AnyTuple> = AnyTuple | undefined, TContext = AnyObject, TDefault = undefined, TFlags extends Flags = ''> extends Schema<TType, TContext, TDefault, TFlags> {
    spec: TupleSchemaSpec<TType>;
    constructor(schemas: [ISchema<any>, ...ISchema<any>[]]);
    protected _cast(inputValue: any, options: InternalOptions<TContext>): any;
    protected _validate(_value: any, options: InternalOptions<TContext> | undefined, panic: (err: Error, value: unknown) => void, next: (err: ValidationError[], value: unknown) => void): void;
    describe(options?: ResolveOptions<TContext>): SchemaInnerTypeDescription;
}

declare function create<TSchema extends ISchema<any, TContext>, TContext extends Maybe<AnyObject> = AnyObject>(builder: (value: any, options: ResolveOptions<TContext>) => TSchema): Lazy<InferType<TSchema>, TContext, any>;
interface LazySpec {
    meta: Record<string, unknown> | undefined;
    optional: boolean;
}
declare class Lazy<T, TContext = AnyObject, TFlags extends Flags = any> implements ISchema<T, TContext, TFlags, undefined> {
    private builder;
    type: "lazy";
    __isYupSchema__: boolean;
    readonly __outputType: T;
    readonly __context: TContext;
    readonly __flags: TFlags;
    readonly __default: undefined;
    spec: LazySpec;
    constructor(builder: any);
    clone(spec?: Partial<LazySpec>): Lazy<T, TContext, TFlags>;
    private _resolve;
    private optionality;
    optional(): Lazy<T | undefined, TContext, TFlags>;
    resolve(options: ResolveOptions<TContext>): Schema<T, TContext, undefined, TFlags>;
    cast(value: any, options?: CastOptions$1<TContext>): T;
    cast(value: any, options?: CastOptionalityOptions<TContext>): T | null | undefined;
    asNestedTest(config: NestedTestConfig): RunTest;
    validate(value: any, options?: ValidateOptions<TContext>): Promise<T>;
    validateSync(value: any, options?: ValidateOptions<TContext>): T;
    validateAt(path: string, value: any, options?: ValidateOptions<TContext>): Promise<any>;
    validateSyncAt(path: string, value: any, options?: ValidateOptions<TContext>): any;
    isValid(value: any, options?: ValidateOptions<TContext>): Promise<boolean>;
    isValidSync(value: any, options?: ValidateOptions<TContext>): boolean;
    describe(options?: ResolveOptions<TContext>): SchemaLazyDescription | SchemaFieldDescription;
    meta(): Record<string, unknown> | undefined;
    meta(obj: Record<string, unknown>): Lazy<T, TContext, TFlags>;
}

declare function getIn<C = any>(schema: any, path: string, value?: any, context?: C): {
    schema: ISchema<any> | Reference<any>;
    parent: any;
    parentPath: string;
};
declare function reach<P extends string, S extends ISchema<any>>(obj: S, path: P, value?: any, context?: any): Reference<Get<InferType<S>, P>> | ISchema<Get<InferType<S>, P>, S['__context']>;

declare const isSchema: (obj: any) => obj is ISchema<any, any, any, any>;

declare function printValue(value: any, quoteStrings?: boolean): any;

declare function setLocale(custom: LocaleObject): void;

declare function addMethod<T extends ISchema<any>>(schemaType: (...arg: any[]) => T, name: string, fn: (this: T, ...args: any[]) => T): void;
declare function addMethod<T extends new (...args: any) => ISchema<any>>(schemaType: T, name: string, fn: (this: InstanceType<T>, ...args: any[]) => InstanceType<T>): void;
type AnyObjectSchema = ObjectSchema<any, any, any, any>;
type CastOptions = Omit<CastOptions$1, 'path' | 'resolved'>;

export { AnyObject, AnyObjectSchema, AnySchema, ArraySchema, InferType as Asserts, BooleanSchema, CastOptions, CreateErrorOptions, DateSchema, DefaultFromShape, DefaultThunk, Defined, Flags, ISchema, InferType, LocaleObject, MakePartial, Maybe, Message, MixedOptions, MixedSchema, TypeGuard as MixedTypeGuard, NotNull, NumberSchema, ObjectSchema, ObjectShape, Optionals, Schema, SchemaDescription, SchemaFieldDescription, SchemaInnerTypeDescription, SchemaLazyDescription, SchemaObjectDescription, SchemaRefDescription, SetFlag, StringSchema, TestConfig, TestContext, TestFunction, TestOptions, ToggleDefault, TupleSchema, TypeFromShape, UnsetFlag, ValidateOptions, ValidationError, addMethod, create$2 as array, create$7 as bool, create$7 as boolean, create$4 as date, _default as defaultLocale, getIn, isSchema, create as lazy, create$8 as mixed, create$5 as number, create$3 as object, printValue, reach, create$9 as ref, setLocale, create$6 as string, create$1 as tuple };

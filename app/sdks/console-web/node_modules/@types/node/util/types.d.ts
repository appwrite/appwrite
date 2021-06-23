declare module 'util/types' {
    function isAnyArrayBuffer(object: any): object is ArrayBufferLike;
    function isArgumentsObject(object: any): object is IArguments;
    function isArrayBuffer(object: any): object is ArrayBuffer;
    function isArrayBufferView(object: any): object is NodeJS.ArrayBufferView;
    function isAsyncFunction(object: any): boolean;
    function isBigInt64Array(value: any): value is BigInt64Array;
    function isBigUint64Array(value: any): value is BigUint64Array;
    function isBooleanObject(object: any): object is Boolean;
    function isBoxedPrimitive(object: any): object is String | Number | BigInt | Boolean | Symbol;
    function isDataView(object: any): object is DataView;
    function isDate(object: any): object is Date;
    function isExternal(object: any): boolean;
    function isFloat32Array(object: any): object is Float32Array;
    function isFloat64Array(object: any): object is Float64Array;
    function isGeneratorFunction(object: any): object is GeneratorFunction;
    function isGeneratorObject(object: any): object is Generator;
    function isInt8Array(object: any): object is Int8Array;
    function isInt16Array(object: any): object is Int16Array;
    function isInt32Array(object: any): object is Int32Array;
    function isMap<T>(
        object: T | {},
    ): object is T extends ReadonlyMap<any, any>
        ? unknown extends T
            ? never
            : ReadonlyMap<any, any>
        : Map<any, any>;
    function isMapIterator(object: any): boolean;
    function isModuleNamespaceObject(value: any): boolean;
    function isNativeError(object: any): object is Error;
    function isNumberObject(object: any): object is Number;
    function isPromise(object: any): object is Promise<any>;
    function isProxy(object: any): boolean;
    function isRegExp(object: any): object is RegExp;
    function isSet<T>(
        object: T | {},
    ): object is T extends ReadonlySet<any>
        ? unknown extends T
            ? never
            : ReadonlySet<any>
        : Set<any>;
    function isSetIterator(object: any): boolean;
    function isSharedArrayBuffer(object: any): object is SharedArrayBuffer;
    function isStringObject(object: any): object is String;
    function isSymbolObject(object: any): object is Symbol;
    function isTypedArray(object: any): object is NodeJS.TypedArray;
    function isUint8Array(object: any): object is Uint8Array;
    function isUint8ClampedArray(object: any): object is Uint8ClampedArray;
    function isUint16Array(object: any): object is Uint16Array;
    function isUint32Array(object: any): object is Uint32Array;
    function isWeakMap(object: any): object is WeakMap<any, any>;
    function isWeakSet(object: any): object is WeakSet<any>;
}

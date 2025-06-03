/* eslint-disable @typescript-eslint/ban-types */
export type Constructor<T> = new (...args: any[]) => T;
export type ConstructorOrAbstract<T = {}> = Function & { prototype: T };                  // tslint:disable-line:ban-types
export type ConstructorAbstractOrInstance<T = {}> = T | ConstructorOrAbstract;            // tslint:disable-line:ban-types
/* eslint-enable @typescript-eslint/ban-types */

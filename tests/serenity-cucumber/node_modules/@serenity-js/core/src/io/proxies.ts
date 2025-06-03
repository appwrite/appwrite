export interface Dictionary {
    [key: string]: any;
}

/**
 * @private
 */
export function caseInsensitive<T extends Dictionary>(dictionary: T): T & Dictionary {
    return new Proxy(dictionary, {
        get: <K extends keyof T & string>(obj: T & Dictionary, key: K) => {     // eslint-disable-line unicorn/prevent-abbreviations
            const found = Object.keys(obj)
                .find(k => k.toLocaleLowerCase() === key.toLocaleLowerCase());

            return found && obj[found];
        },
    });
}

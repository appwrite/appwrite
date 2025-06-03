type PackageJSON = {
    engines: {
        node: string;
    };
    enginesTested: {
        node: string;
    };
};
export declare function validateNodeEngineVersion(currentVersion: string, onError: (message: string) => void, onWarning: (message: string) => void, readPackageJSON?: () => PackageJSON): void;
export {};

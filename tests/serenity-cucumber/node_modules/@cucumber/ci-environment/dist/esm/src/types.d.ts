export type CiEnvironment = {
    name: string;
    url: string;
    buildNumber?: string;
    git?: Git;
};
export type Git = {
    remote: string;
    revision: string;
    branch?: string;
    tag?: string;
};
export type CiEnvironments = {
    ciEnvironments: readonly CiEnvironment[];
};
export type Env = Record<string, string | undefined>;
//# sourceMappingURL=types.d.ts.map
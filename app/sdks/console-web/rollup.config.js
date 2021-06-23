import pkg from "./package.json";
import typescript from "@rollup/plugin-typescript";

export default {
    external: Object.keys(pkg.dependencies),
    input: "src/sdk.ts",
    plugins: [typescript()],
    output: [
        {
            format: "cjs",
            file: pkg.main,
            esModule: false,
            sourcemap: true,
        },
        {
            format: "es",
            file: pkg.module,
            sourcemap: true,
        },
        {
            format: "iife",
            file: pkg.jsdelivr,
            name: "window",
            extend: true,
            globals: {
                "cross-fetch": "window",
                "FormData": "FormData",
            },
        },
    ],
};

<!doctype html>
<html>
    <head>
        <link rel="preconnect" href="https://assets.appwrite.io/" crossorigin>
        <meta name="color-scheme" content="light dark">
        <meta name="supported-color-schemes" content="light dark">
        <style type="text/css">
            :root {
                color-scheme: light dark;
                supported-color-schemes: light dark;
            }

            @media (prefers-color-scheme: dark ) {
                body {
                    color: #616b7c !important;
                    background-color: #ffffff !important;
                }
                a {
                    color: currentColor !important;
                }
                a.button {
                    color: #ffffff !important;
                    background-color: #2D2D31 !important;
                    border-color: #414146 !important;
                }
                h1, h2, h3 {
                    color: #373b4d !important;
                }
                h4 {
                    color: #4f5769 !important;
                }
                p.security-phrase:not(:empty), hr {
                    border-color: #e8e9f0 !important;
                }
            }
        </style>
        <style>
            @font-face {
                font-family: 'Inter';
                src: url('https://assets.appwrite.io/fonts/inter/Inter-Regular.woff2') format('woff2');
                font-weight: 400;
                font-style: normal;
                font-display: swap;
            }
            
            @font-face {
                font-family: 'DM Sans';
                src: url('https://assets.appwrite.io/fonts/dm-sans/dm-sans-v16-latin-600.woff2') format('woff2');
                font-weight: 600;
                font-style: normal;
                font-display: swap;
            }
            
            @font-face {
                font-family: 'Poppins';
                src: url('https://assets.appwrite.io/fonts/poppins/poppins-v23-latin-regular.woff2') format('woff2');
                font-weight: 400;
                font-style: normal;
                font-display: swap;
            }
        </style>
        <style>
            body {
                padding: 32px;
                line-height: 1.5;
                color: #616b7c;
                font-size: 15px;
                font-weight: 400;
                font-family: "Inter", sans-serif;
                background-color: #ffffff;
                margin: 0;
                padding: 0;
                line-height: 150%;
            }
            a {
                color: currentColor;
                word-break: break-all;
            }
            a.button {
                box-sizing: border-box;
                display: inline-block;
                text-align: center;
                text-decoration: none;
                padding: 9px 14px;
                color: #ffffff;
                background-color: #2D2D31;
                border: 1px solid #414146;
                border-radius: 8px;
            }
            a.button:hover,
            a.button:focus {
                opacity: 0.8;
            }
            table {
                width: 100%;
                border-spacing: 0 !important;
            }
            table, tr, th, td {
                margin: 0;
                padding: 0;
            }
            td {
                vertical-align: top;
            }
            .main {
                max-width: 650px;
                margin: 0 auto;
                margin-top: 32px;
            }
            h1 {
                font-size: 22px;
                margin-bottom: 0px;
                margin-top: 0px;
                color: #373b4d;
            }
            h2 {
                font-size: 20px;
                font-weight: 600;
                color: #373b4d;
            }
            h3 {
                font-size: 14px;
                font-weight: 500;
                color: #373b4d;
                line-height: 21px;
                margin: 0;
                padding: 0;
            }
            h4 {
                font-family: "DM Sans", sans-serif;
                font-weight: 600;
                font-size: 12px;
                color: #4f5769;
                margin: 0;
                padding: 0;
            }
            hr {
                border: none;
                border-top: 1px solid #e8e9f0;
            }
            h* {
                font-family: 'Poppins', sans-serif;
            }
            p {
                margin-bottom: 10px;
            }
            p.security-phrase:not(:empty) {
                opacity: 0.7;
                margin-top: 32px;
                padding-top: 32px;
                border-top: 1px solid #e8e9f0;
            }
        </style>
    </head>

<body style="direction: {{direction}}">

<div style="display: none; overflow: hidden; max-height: 0; max-width: 0; opacity: 0; line-height: 1px;">
    {{preview}}
    <div>{{previewWhitespace}}</div>
</div>

<div style="max-width:650px; word-wrap: break-word; overflow-wrap: break-word;
  word-break: normal; margin:0 auto;">
    <table style="margin-top: 32px">
        <tr>
            <td>
                {{body}}
            </td>
        </tr>
    </table>
</div>

</body>
</html>
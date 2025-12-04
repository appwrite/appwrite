<!doctype html>
<html>
    <head>
        <link rel="preconnect" href="https://assets.appwrite.io/" crossorigin>
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
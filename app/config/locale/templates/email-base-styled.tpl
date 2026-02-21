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
                    background-color: {{accentColor}} !important;
                    border-color: {{accentColor}} !important;
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
        </style>
        <style>
            @media (max-width:500px) {
                .mobile-full-width {
                    width: 100%;
                }
            }
            .main a {
                color: currentColor;
            }
            .main {
                padding: 32px;
                line-height: 1.5;
                color: #616b7c;
                font-size: 15px;
                font-weight: 400;
                font-family: "Inter", sans-serif;
                background-color: #ffffff;
                margin: 0;
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
        </style>
        <style>
            a.button {
                display: inline-block;
                background: {{accentColor}};
                color: #ffffff;
                border-radius: 8px;
                height: 48px;
                line-height: 24px;
                padding: 12px 20px;
                box-sizing: border-box;
                cursor: pointer;
                text-align: center;
                text-decoration: none;
                border-color: {{accentColor}};
                border-style: solid;
                border-width: 1px;
                margin-right: 24px;
                margin-top: 8px;
            }
            a.button:hover,
            a.button:focus {
                opacity: 0.8;
            }
            @media only screen and (max-width: 600px) {
                .button {
                    width: 100%;
                }
            }
            .social-icon {
                border-radius: 6px;
                background: rgba(216, 216, 219, 0.1);
                width: 32px;
                height: 32px;
                line-height: 32px;
                display: flex; 
                align-items: center; 
                justify-content: center;
            }
            .social-icon > img {
                margin: auto;
            }
            p.security-phrase:not(:empty) {
                opacity: 0.7;
                margin: 0;
                padding: 0;
                margin-top: 32px;
                padding-top: 32px;
                border-top: 1px solid #e8e9f0;
            }
        </style>
    </head>

    <body>
        <div style="display: none; overflow: hidden; max-height: 0; max-width: 0; opacity: 0; line-height: 1px;">
            {{preview}}
            <div>{{previewWhitespace}}</div>
        </div>

        <div class="main">
            <table>
                <tr>
                    <td>
                        <img
                            height="26px"
                            src="{{logoUrl}}"
                            alt="{{platform}} logo"
                        />
                    </td>
                </tr>
            </table>

            <table style="margin-top: 32px">
                <tr>
                    <td>
                        <h1>{{heading}}</h1>
                    </td>
                </tr>
            </table>

            <table style="margin-top: 16px">
                <tr>
                    <td>
{{body}}
                    </td>
                </tr>
            </table>

            <table
                style="
                    padding-top: 32px;
                    margin-top: 32px;
                    border-top: solid 1px #e8e9f0;
                "
            >
                <tr>
                    <td></td>
                </tr>
            </table>

            <table style="width: auto; margin: 0 auto">
                <tr>
                    <td style="padding-left: 4px; padding-right: 4px">
                        <a
                            href="{{twitter}}"
                            class="social-icon"
                            title="Twitter"
                        >
                            <img src="https://cloud.appwrite.io/images/mails/x.png" height="24" width="24" />
                        </a>
                    </td>
                    <td style="padding-left: 4px; padding-right: 4px">
                        <a
                            href="{{discord}}"
                            class="social-icon"
                        >
                            <img src="https://cloud.appwrite.io/images/mails/discord.png" height="24" width="24" />
                        </a>
                    </td>
                    <td style="padding-left: 4px; padding-right: 4px">
                        <a
                            href="{{github}}"
                            class="social-icon"
                        >
                            <img src="https://cloud.appwrite.io/images/mails/github.png" height="24" width="24" />
                        </a>
                    </td>
                </tr>
            </table>
            <table style="width: auto; margin: 0 auto; margin-top: 60px">
                <tr>
                    <td><a href="{{terms}}">Terms</a></td>
                    <td style="color: #e8e9f0">
                        <div style="margin: 0 8px">|</div>
                    </td>
                    <td><a href="{{privacy}}">Privacy</a></td>
                </tr>
            </table>
            <p style="text-align: center" align="center">
                &copy; {{year}} {{platform}} | 251 Little Falls Drive, Wilmington 19808,
                Delaware, United States
            </p>
        </div>
    </body>
</html>
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
    <head>
        <link
            href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=DM+Sans:wght@500;600&display=swap"
            rel="stylesheet"
        />
        <style>
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
            }
            table {
                width: 100%;
                border-spacing: 0;
            }
            table,
            tr,
            th,
            td {
                margin: 0;
                padding: 0;
            }
            td {
                vertical-align: top;
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
            h3,
            td h3 {
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
            .main a.button {
                display: inline-block;
                background: #fd366e;
                color: #ffffff;
                border-radius: 8px;
                height: 48px;
                padding: 12px 20px;
                box-sizing: border-box;
                cursor: pointer;
                text-align: center;
                text-decoration: none;
                border-color: #fd366e;
                border-style: solid;
                border-width: 1px;
                margin-right: 24px;
                margin-top: 8px;
            }
            .main a.button:hover,
            .main a.button:focus {
                opacity: 0.8;
            }
            .main a.button.is-reverse {
                background: #fff;
                color: #fd366e;
            }
            .fs12 {
                font-size: 12px;
            }
            .ta-right {
                text-align: right !important;
            }
            .ta-left {
                text-align: left !important;
            }
            .ff-dmsans {
                font-family: "DM Sans", sans-serif !important;
            }
            .ff-inter {
                font-family: "Inter", sans-serif !important;
            }
            .w500 {
                font-weight: 500 !important;
            }
            .w400 {
                font-weight: 400 !important;
            }
            .w600 {
                font-weight: 600 !important;
            }
            .tc-accent {
                color: #da1a5b;
            }
            .pt-16 {
                padding-top: 16px !important;
            }
            .pt-32 {
                padding-top: 32px !important;
            }
            .divider {
                padding-top: 32px;
                border-bottom: solid 1px #d8d8db;
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

            @media only screen and (max-width: 600px) {
                .button {
                    width: 100%;
                }
            }
        </style>
    </head>

    <body>
        <div class="main" style="max-width: 650px; margin: 0 auto">
            <table>
                <tr>
                    <td>
                        <img
                            height="32px"
                            src="https://appwrite.io/assets/logotype/white.png"
                        />
                    </td>
                </tr>
            </table>

            <table style="margin-top: 32px">
                <tr>
                    <td>
                        <h1>{{subject}}</h1>
                    </td>
                </tr>
            </table>

            <table style="margin-top: 40px">
                <tr>
                    <td>{{message}}</td>
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
                            href="https://twitter.com/appwrite"
                            class="social-icon"
                            title="Twitter"
                        >
                            <img src="https://appwrite.io/email/x.png" height="24" width="24" />
                        </a>
                    </td>
                    <td style="padding-left: 4px; padding-right: 4px">
                        <a
                            href="https://appwrite.io/discord"
                            class="social-icon"
                        >
                            <img src="https://appwrite.io/email/discord.png" height="24" width="24" />
                        </a>
                    </td>
                    <td style="padding-left: 4px; padding-right: 4px">
                        <a
                            href="https://github.com/appwrite/appwrite"
                            class="social-icon"
                        >
                            <img src="https://appwrite.io/email/github.png" height="24" width="24" />
                        </a>
                    </td>
                </tr>
            </table>
            <table style="width: auto; margin: 0 auto; margin-top: 60px">
                <tr>
                    <td><a href="https://appwrite.io/terms">Terms</a></td>
                    <td style="color: #e8e9f0">
                        <div style="margin: 0 8px">|</div>
                    </td>
                    <td><a href="https://appwrite.io/privacy">Privacy</a></td>
                </tr>
            </table>
            <p style="text-align: center" align="center">
                &copy; 2023 Appwrite | 251 Little Falls Drive, Wilmington 19808,
                Delaware, United States
            </p>
        </div>
    </body>
</html>
<!doctype html>
<html>
    <head>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600&display=swap">
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
    </head>

    <body>
        <div class="main">
            <table style="margin-top: 32px">
                <tr>
                    <td>
                        <h1>{{subject}}</h1>
                    </td>
                </tr>
            </table>

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
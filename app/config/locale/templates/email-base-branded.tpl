<!doctype html>
<html>
    <head>
        <link
            href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600&display=swap"
            rel="stylesheet"
        />
        <style>
            .main {
                padding: 32px;
                color: #616b7c;
                font-size: 15px;
                font-family: "Inter", sans-serif;
                line-height: 1.5;
            }

            table {
                width: 100%;
                border-spacing: 0 !important;
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

            .main a {
                color: currentColor;
                word-break: break-all;
            }

            h1,
            h2,
            h3,
            h4,
            h5,
            h6 {
                font-family: "Poppins", sans-serif;
            }

            hr {
                border: none;
                border-top: 1px solid #e8e9f0;
            }

            p {
                margin-bottom: 10px;
            }
        </style>
    </head>

    <body>
        <div
            class="main"
            style="
                direction: {{direction}};
                max-width: 650px;
                word-wrap: break-word;
                overflow-wrap: break-word;
                word-break: break-word;
                margin: 0 auto;
            "
        >
            <table style="margin-top: 32px">
                <tr>
                    <td>{{body}}</td>
                </tr>
            </table>

            <div
                style="
                    border-top: solid 1px #ededf0;
                    width: 100%;
                    color: #818186;
                "
            >
                <table style="padding-top: 24px; margin: 0 auto; width: auto">
                    <tr>
                        <td style="display: flex">
                            <a target="_blank" href="https://appwrite.io?utm_source=email&utm_medium=footer&utm_campaign=user_emails">
                                <img
                                    src="https://appwrite.io/email/footer.png"
                                    width="378"
                                    height="27"
                                />
                            </a>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </body>
</html>
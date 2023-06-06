<!doctype html>
<html>

<head>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
            href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600&display=swap"
            rel="stylesheet">
    <style>
        a { color:currentColor; }
        body {
            padding: 32px;
            color: #616B7C;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            line-height: 15px;
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

        h* {
            font-family: 'Poppins', sans-serif;
        }

        hr {
            border: none;
            border-top: 1px solid #E8E9F0;
        }
    </style>
</head>

<body style="direction: {{direction}}">

<div style="max-width:650px; margin:0 auto;">
    <table style="margin-top: 32px">
        <tr>
            <td>
                <h1>
                    {{subject}}
                </h1>
            </td>
        </tr>
    </table>

    <table style="margin-top: 40px">
        <tr>
            <td>
                <p>{{hello}}</p>

                <p>{{body}}</p>

                <a href="{{redirect}}" target="_blank">{{redirect}}</a>

                <p><br />{{footer}}</p>
                <br />

                <p>{{thanks}}
                <br />
                {{signature}}
                </p>
            </td>
        </tr>
    </table>
</div>

</body>

</html>
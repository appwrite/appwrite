<!doctype html>
<html>

<head>
  <meta name="viewport" content="width=device-width" />
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <title>{{title}}</title>
  <style>
    body {
      background-color: {{bg-body}};
      color: {{text-content}};
      font-family: sans-serif;
      -webkit-font-smoothing: antialiased;
      font-size: 14px;
      line-height: 1.4;
      margin: 0;
      padding: 0;
      -ms-text-size-adjust: 100%;
      -webkit-text-size-adjust: 100%;
    }

    table {
      border-collapse: separate;
      mso-table-lspace: 0pt;
      mso-table-rspace: 0pt;
      width: 100%;
    }

    table td {
      font-family: sans-serif;
      font-size: 14px;
      vertical-align: top;
    }

    .body {
      background-color: {{bg-body}};
      width: 100%;
    }

    .container {
      display: block;
      margin: 0 auto !important;
      max-width: 580px;
      padding: 10px;
      width: 580px;
    }

    .content {
      box-sizing: border-box;
      display: block;
      margin: 0 auto;
      max-width: 580px;
      padding: 10px;
      color: {{text-content}};
    }

    .main {
      background: {{bg-content}};
      border-radius: 10px;
      width: 100%;
    }

    .wrapper {
      box-sizing: border-box;
      padding: 30px 30px 15px 30px;
    }

    .content-block {
      padding-bottom: 10px;
      padding-top: 10px;
    }

    p {
      font-family: sans-serif;
      font-size: 14px;
      font-weight: normal;
      margin: 0;
      margin-bottom: 15px;
    }

    a {
      word-break: break-all;
    }

    .btn {
      box-sizing: border-box;
      width: 100%;
    }

    .btn>tbody>tr>td {
      padding-bottom: 15px;
      padding-top: 15px;
    }

    .btn table {
      width: auto;
    }

    .btn table td {
      background-color: {{bg-content}};
      border-radius: 20px;
      text-align: center;
    }

    .btn a {
      background-color: {{bg-content}};
      border-radius: 20px;
      box-sizing: border-box;
      color: #577590;
      cursor: pointer;
      display: inline-block;
      font-size: 14px;
      font-weight: bold;
      margin: 0;
      padding: 12px 25px;
      text-decoration: none;
      text-transform: capitalize;
    }

    .btn-primary table td {
      background-color: {{bg-cta}};
    }

    .btn-primary a {
      background-color: {{bg-cta}};
      color: {{text-cta}};
    }

    @media only screen and (max-width: 620px) {
      .container {
        padding: 0;
        width: 100%;
      }

      .btn-primary a {
        font-size: 13px;
      }
    }

    @media all {
      .ExternalClass {
        width: 100%;
      }

      .ExternalClass,
      .ExternalClass p,
      .ExternalClass span,
      .ExternalClass font,
      .ExternalClass td,
      .ExternalClass div {
        line-height: 100%;
      }

      .apple-link a {
        color: inherit !important;
        font-family: inherit !important;
        font-size: inherit !important;
        font-weight: inherit !important;
        line-height: inherit !important;
        text-decoration: none !important;
      }

      #MessageViewBody a {
        color: inherit;
        text-decoration: none;
        font-size: inherit;
        font-family: inherit;
        font-weight: inherit;
        line-height: inherit;
      }

      .btn-primary table td:hover {
        opacity: 0.7 !important;
      }

      .btn-primary a:hover {
        opacity: 0.7 !important;
      }
    }
  </style>
</head>

<body style="direction: {{direction}}">
  <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="body">
    <tr>
      <td>&nbsp;</td>
      <td class="container">
        <div class="content">
          <table role="presentation" class="main">
            <tr>
              <td class="wrapper">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                  <tr>
                    <td>{{content}}</td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </div>

        <!-- <div style="text-align: center; line-height: 25px; margin: 15px 0; font-size: 12px; color: #40404c;">
          <a href="https://appwrite.io" style="text-decoration: none; color: #40404c;">Powered by <img src="https://appwrite.io/images/appwrite-footer-light.svg" height="15" style="margin: -3px 0" /></a>
        </div> -->
      </td>
      <td>&nbsp;</td>
    </tr>
  </table>
</body>

</html>
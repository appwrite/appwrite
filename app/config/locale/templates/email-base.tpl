<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="https://www.w3.org/1999/xhtml">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <meta name="viewport" content="width=device-width" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/foundation-emails/2.3.1/foundation-emails.min.css">
  <link rel="preconnect" href="https://fonts.gstatic.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300&display=swap" rel="stylesheet">
  <title>{{title}}</title>
</head>
<!-- Injection point for the inline <style> element. Don't remove this comment! -->
<!-- <style> -->
<!-- Wrapper for the body of the email -->
<body>
<table class="body" data-made-with-foundation>
  <tr>
    <!-- The class, align, and <center> tag center the container -->
    <td class="float-center" align="center" valign="top">
      <center>
        <style>
          body,
          html,
          .body {
            background-color: {{colorBg}} !important;
            color: {{colorText}} !important;
            direction: {{direction}} !important;
          }
      
          .header {
            background-color: {{colorBgPrimary}} !important;
          }

          .header * {
            color: {{colorTextPrimary}} !important;
          }

          .content {
            background-color: {{colorBgContent}} !important;
          }

          .content * {
            color: {{colorText}} !important;
          }

          .cta,
          .cta * {
            background-color: {{colorBgPrimary}} !important;
            border-color: {{colorBgPrimary}} !important;
            color: {{colorTextPrimary}} !important;
          }

          body, h1, h2, h3, h4, h5, h6, p, a, table.body, td, th {
            font-family: 'Poppins', sans-serif !important;
            text-align: center;
          }
        </style>
        <spacer size="16"></spacer>

        <container>

          <row class="header">
            <columns>

              <spacer size="16"></spacer>

              <h1 class="text-center">{{project}}</h1>
            </columns>
          </row>
          <row class="content">
            <columns>

              <spacer size="32"></spacer>

              <h4 class="text-center">{{title}}</h4>

              <spacer size="32"></spacer>

              {{content}}

            </columns>
          </row>
        </container>
      </center>
    </td>
  </tr>
</table>
</body>

</html>
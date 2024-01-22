<p>Hi <strong>{{user}}</strong>,</p>
<p>Your webhook <strong>{{webhook}}</strong> on project <strong>{{project}}</strong> has been paused after {{attempts}} consecutive failures.</p>
<p>Webhook Endpoint: <strong>{{url}}</strong></p>
<p>Error: <strong>{{error}}</strong></p>
<p>To restore your webhook's functionality and reset attempts, we suggest to follow the below steps:</p>
<ol>
    <li>Examine the logs of both Appwrite Console and your webhook server to identify the issue.</li>
    <li>Investigate potential network issues and use webhook testing tools to verify expected behaviour.</li>
    <li>Ensure the webhook endpoint is reachable and configured to accept incoming POST requests.</li>
    <li>Confirm that the webhook doesn't return error status codes such as 400 or 500.</li>
</ol>
<p>After the issue is resolved, please make sure to re-enable the webhook directly through the webhook settings.</p>

<table border="0" cellspacing="0" cellpadding="0" style="padding-top: 10px; padding-bottom: 10px; margin-top: 32px">
    <tr>
        <td style="border-radius: 8px; display: block; width: 100%;">
            <a class="mobile-full-width" rel="noopener" target="_blank" href="{{host}}{{path}}" style="font-size: 14px; font-family: Inter; color: #ffffff; text-decoration: none; background-color: #FD366E; border-radius: 8px; padding: 9px 14px; border: 1px solid #FD366E; display: inline-block; text-align:center; box-sizing: border-box;">Webhook settings</a>
        </td>
    </tr>
</table>
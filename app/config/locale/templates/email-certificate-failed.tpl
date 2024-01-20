




<p>Hello,</p>
<p>Domain <strong>{{domain}}</strong> failed to generate certificate after <strong>{{attempts}}</strong> consecutive attempts with following error:</p>

<table border="0" cellspacing="0" cellpadding="0" style="padding-top: 10px; padding-bottom: 10px; display: inline-block;">
    <tr>
        <td align="center" style="border-radius: 8px; background-color: #ffffff;">
            <p style="text-align: start; font-size: 14px; font-family: Inter; color: #414146; text-decoration: none; border-radius: 8px; padding: 32px; border: 1px solid #EDEDF0; display: inline-block; word-break: break-word;">{{error}}</p>
        </td>
    </tr>
</table>

<p>We suggest to follow the below steps:</p>
<ol>
    <li>Examine the logs above to identify the issue</li>
    <li>Ensure the domain did not expire without a renewal</li>
    <li>Check DNS configuration for any unwanted changes</li>
</ol>

<p>The previous certificate will be valid for 30 days since the first failure. We highly recommend investigating this issue, otherwise the domain will end up without a valid HTTPS communication.</p>
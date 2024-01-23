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
    <li>Examine the logs above to try and identify the issue</li>
    <li>Ensure the domain did not expire without a renewal</li>
    <li>Check DNS configuration for any unwanted changes</li>
</ol>

<p>The existing certificate will remain valid for 30 days from the initial failure. It is highly recommended to investigate this issue; failing to do so will lead to security vulnerabilities.</p>
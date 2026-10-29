<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="width: auto;">
    <tr>
        <td style="padding: 3px 10px; border-radius: 999px; border: 1px solid {{statusBorder}}; background-color: {{statusBackground}}; color: {{statusColor}}; font-size: 12px; font-weight: 600; line-height: 18px; white-space: nowrap;">&#9679;&nbsp;{{statusLabel}}</td>
        <td style="padding-left: 10px; color: #818186; font-size: 13px; line-height: 26px; white-space: nowrap;">{{changedAt}}</td>
    </tr>
</table>

<p style="margin: 20px 0 0;">{{summary}}</p>

<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="margin-top: 24px; border: 1px solid #e8e9f0; border-radius: 12px; border-collapse: separate; background-color: #fafafb;">
    <tr>
        <td style="padding: 16px 20px; width: 50%; border-right: 1px solid #e8e9f0;">
            <div style="color: #818186; font-size: 12px; line-height: 18px;">{{valueLabel}}</div>
            <div style="margin-top: 4px; color: {{valueColor}}; font-size: 22px; font-weight: 600; line-height: 30px; white-space: nowrap;">{{value}}</div>
            <div style="color: #818186; font-size: 13px; line-height: 20px;">{{valueNote}}</div>
        </td>
        <td style="padding: 16px 20px; width: 50%;">
            <div style="color: #818186; font-size: 12px; line-height: 18px;">Threshold</div>
            <div style="margin-top: 4px; color: #373b4d; font-size: 22px; font-weight: 600; line-height: 30px; white-space: nowrap;">{{threshold}}</div>
            <div style="color: #818186; font-size: 13px; line-height: 20px;">{{thresholdNote}}</div>
        </td>
    </tr>
</table>

<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="margin-top: 24px; font-size: 14px; line-height: 20px;">
    {{rows}}
</table>

<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="margin-top: 32px; width: auto;">
    <tr>
        <td style="border-radius: 8px;">
            <a class="mobile-full-width" rel="noopener" target="_blank" href="{{url}}" style="font-size: 14px; font-family: Inter, sans-serif; color: #ffffff; text-decoration: none; background-color: #2D2D31; border-radius: 8px; padding: 9px 14px; border: 1px solid #414146; display: inline-block; text-align: center; box-sizing: border-box;">{{action}}</a>
        </td>
    </tr>
</table>

<p style="margin: 24px 0 0; color: #818186; font-size: 13px; line-height: 20px;">{{reason}}</p>

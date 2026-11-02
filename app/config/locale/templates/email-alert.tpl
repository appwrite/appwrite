{{location}}

<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="width: auto; margin-top: 16px;">
    <tr>
        <td style="padding: 3px 10px; border-radius: 999px; border: 1px solid {{statusBorder}}; background-color: {{statusBackground}}; color: {{statusColor}}; font-size: 12px; font-weight: 600; line-height: 18px; white-space: nowrap;">&#9679;&nbsp;{{statusLabel}}</td>
        <td style="padding-left: 10px; color: #6c6c75; font-size: 13px; line-height: 26px; white-space: nowrap;">{{changedAt}}</td>
    </tr>
</table>

{{callout}}

<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="margin-top: 20px; border: 1px solid #e8e9f0; border-radius: 12px; border-collapse: separate; background-color: #fafafb;">
    <tr>
        <td style="padding: 16px 20px; width: 50%; border-right: 1px solid #e8e9f0;">
            <div style="color: #6c6c75; font-size: 12px; line-height: 18px;">{{valueLabel}}</div>
            <div style="margin-top: 4px; color: {{valueColor}}; font-size: 22px; font-weight: 600; line-height: 30px; white-space: nowrap;">{{value}}</div>
            <div style="color: #6c6c75; font-size: 13px; line-height: 20px;">{{valueNote}}</div>
        </td>
        <td style="padding: 16px 20px; width: 50%;">
            <div style="color: #6c6c75; font-size: 12px; line-height: 18px;">{{thresholdLabel}}</div>
            <div style="margin-top: 4px; color: #19191c; font-size: 22px; font-weight: 600; line-height: 30px; white-space: nowrap;">{{threshold}}</div>
            <div style="color: #6c6c75; font-size: 13px; line-height: 20px;">{{thresholdNote}}</div>
        </td>
    </tr>
    {{meter}}
</table>

<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="margin-top: 20px; font-size: 14px; line-height: 20px;">
    {{rows}}
</table>

<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="margin-top: 28px; width: auto;">
    <tr>
        <td style="border-radius: 8px; vertical-align: middle;">
            <a class="mobile-full-width" rel="noopener" target="_blank" href="{{url}}" style="font-size: 14px; font-family: Inter, sans-serif; color: #ffffff; text-decoration: none; background-color: #2D2D31; border-radius: 8px; padding: 9px 14px; border: 1px solid #414146; display: inline-block; text-align: center; box-sizing: border-box;">{{action}}</a>
        </td>
        {{link}}
    </tr>
</table>

<p style="margin: 28px 0 0; padding-top: 16px; border-top: 1px solid #e8e9f0; color: #6c6c75; font-size: 13px; line-height: 20px;">{{reason}}{{unsubscribe}}</p>

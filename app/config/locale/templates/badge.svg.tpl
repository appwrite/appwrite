<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="{{totalWidth}}" height="20" role="img" aria-label="{{label}}: {{message}}">
    <title>{{label}}: {{message}}</title>
    <linearGradient id="s" x2="0" y2="100%">
        <stop offset="0" stop-color="#bbb" stop-opacity=".1"/>
        <stop offset="1" stop-opacity=".1"/>
    </linearGradient>
    <clipPath id="r">
        <rect width="{{totalWidth}}" height="20" rx="3" fill="#fff"/>
    </clipPath>
    <g clip-path="url(#r)">
        <rect width="{{labelWidth}}" height="20" fill="#FD366E"/>
        <rect x="{{labelWidth}}" width="100%" height="20" fill="{{colorCode}}"/>
        <rect width="{{totalWidth}}" height="20" fill="url(#s)"/>
    </g>
    <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" text-rendering="geometricPrecision" font-size="110">
        <image x="5" y="3" width="14" height="14" href="data:image/svg+xml,%3Csvg width='24' height='24' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M24.4429 17.4322V22.9096H10.7519C6.76318 22.9096 3.28044 20.7067 1.4171 17.4322C1.14622 16.9561 0.909137 16.4567 0.710264 15.9383C0.319864 14.9225 0.0744552 13.8325 0 12.6952V11.2143C0.0161646 10.9609 0.0416361 10.7094 0.0749451 10.4609C0.143032 9.95105 0.245898 9.45211 0.381093 8.96711C1.66006 4.36909 5.81877 1 10.7519 1C15.6851 1 19.8433 4.36909 21.1223 8.96711H15.2682C14.3072 7.4683 12.6437 6.4774 10.7519 6.4774C8.86017 6.4774 7.19668 7.4683 6.23562 8.96711C5.9427 9.42274 5.71542 9.92516 5.56651 10.4609C5.43425 10.936 5.36371 11.4369 5.36371 11.9548C5.36371 13.5248 6.01324 14.94 7.05463 15.9383C8.01961 16.865 9.32061 17.4322 10.7519 17.4322H24.4429Z' fill='%23ffffff'/%3E%3Cpath d='M24.4429 10.4609V15.9383H14.4492C15.4906 14.94 16.1401 13.5248 16.1401 11.9548C16.1401 11.4369 16.0696 10.936 15.9373 10.4609H24.4429Z' fill='%23ffffff'/%3E%3C/svg%3E"/>
        <text aria-hidden="true" x="{{labelTextX}}" y="150" text-anchor="start" fill="#010101" fill-opacity=".3" transform="scale(.1)" font-size="{{labelFontSize}}">{{label}}</text>
        <text x="{{labelTextX}}" y="140" text-anchor="start" transform="scale(.1)" fill="#fff" font-size="{{labelFontSize}}">{{label}}</text>
        <text aria-hidden="true" x="{{messageTextX}}" y="150" text-anchor="start" fill="#010101" fill-opacity=".3" transform="scale(.1)">{{message}}</text>
        <text x="{{messageTextX}}" y="140" text-anchor="start" transform="scale(.1)" fill="#fff">{{message}}</text>
    </g>
</svg>

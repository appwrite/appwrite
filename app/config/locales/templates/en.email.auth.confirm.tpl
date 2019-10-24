<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Hello {{name}},
    <br />
    <br />
    Follow this link to verify your email address.
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    If you didn’t ask to verify this address, you can ignore this message.
    <br />
    <br />
    Thanks,
    <br />
    {{project}} team
</div>
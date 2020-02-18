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
    Follow this link to reset your {{project}} password.
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    If you didn't ask to reset your password, you can ignore this message.
    <br />
    <br />
    Thanks,
    <br />
    {{project}} team
</div>

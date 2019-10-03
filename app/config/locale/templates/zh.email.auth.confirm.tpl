<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    {{name}} 你好，
    <br />
    <br />
    请点击下方的链接验证你的电子邮箱地址。
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    如果你没有请求验证本邮箱，请忽略这份邮件。
    <br />
    <br />
    谢谢。
    <br />
    来自 {{project}}
</div>
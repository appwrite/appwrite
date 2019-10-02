<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Здравствуйте {{name}},
    <br />
    <br />
    Перейдите по ссылке чтобы подтвердить свою адрес электронной почты.
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Если вы не запрашивали подтверждение этого адреса, проигнорируйте это сообщение.
    <br />
    <br />
    Спасибо,
    <br />
    команда {{project}}
</div>
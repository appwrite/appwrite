<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    안녕하세요 {{name}}님,
    <br />
    <br />
    회원님 이메일을 인증하러 아래 링크를 클릭하세요.
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    만약 인증 요청하지 않으셨다면 이 이메일을 무시하세요.
    <br />
    <br />
    감사합니다!
    <br />
    {{project}}팀 드림
</div>
<?php

return <<<'HTML'
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <style>
        :root{--bg:#000000;--panel:#191915;--stroke:#363636;--accent:#C09C3A;--text:#A8AEAC;--muted:#7B7A7A;--dark:#363636;}
        *{box-sizing:border-box;}
        body{margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg);color:var(--text);font-family:Inter,Segoe UI,Arial,sans-serif;}
        form{background:var(--panel);border:1px solid var(--stroke);padding:24px;border-radius:12px;min-width:300px;max-width:360px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,0.4);}
        h2{margin:0 0 16px;font-size:20px;color:var(--text);letter-spacing:0.3px;}
        label{display:block;margin:10px 0 6px;color:var(--muted);}
        input{width:100%;padding:12px;border-radius:8px;border:1px solid var(--stroke);background:var(--dark);color:var(--text);outline:none;}
        input:focus{border-color:var(--accent);}
        button{margin-top:16px;width:100%;padding:12px;border:none;border-radius:8px;background:var(--accent);color:var(--black,#000);font-weight:700;cursor:pointer;}
    </style>
</head>
<body>
    <form method="POST" action="/login">
        <h2>Login</h2>
        <label>Username</label><input name="username" autocomplete="username" required />
        <label>Password</label><input name="password" type="password" autocomplete="current-password" required />
        <button type="submit">Sign In</button>
    </form>
</body>
</html>
HTML;

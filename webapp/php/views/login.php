<div class="header">
  <h1>ログイン</h1>
</div>

<? if (flash('notice')): ?>
<div id="notice-message" class="alert alert-danger">
  <?= escape_html(flash('notice')) ?>
</div>
<? endif ?>

<div class="submit">
  <form method="post" action="/login">
    <div class="form-account-name">
      <span>アカウント名</span>
      <input type="text" name="account_name">
    </div>
    <div class="form-password">
      <span>パスワード</span>
      <input type="password" name="password">
    </div>
    <div class="form-submit">
      <input type="submit" name="submit" value="submit">
    </div>
  </form>
</div>

<div class="isu-register">
  <a href="/register">ユーザー登録</a>
</div>

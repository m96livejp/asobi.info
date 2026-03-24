(function () {
  var el = document.getElementById('auth-area');
  if (!el) return;

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderLogin() {
    var redirect = encodeURIComponent(location.href);
    el.innerHTML =
      '<a href="https://asobi.info/login.php?redirect=' + redirect + '" class="auth-login-btn">ログイン</a>';
  }

  function renderUser(user) {
    var name = esc(user.display_name);
    var avatar = user.avatar_url
      ? '<img src="' + esc(user.avatar_url) + '" alt="' + name + '" class="auth-avatar">'
      : '<span class="auth-initials">' + esc(user.display_name.charAt(0)) + '</span>';
    var redirect = encodeURIComponent(location.href);

    el.innerHTML =
      '<div class="auth-user">' +
        '<button class="auth-toggle" id="auth-toggle" aria-label="アカウントメニュー">' +
          avatar +
          '<span class="auth-name">' + name + '</span>' +
          '<span class="auth-caret">▾</span>' +
        '</button>' +
        '<div class="auth-dropdown" id="auth-dropdown">' +
          '<a href="https://asobi.info/profile.php">プロフィール</a>' +
          '<a href="https://asobi.info/logout.php?redirect=' + redirect + '">ログアウト</a>' +
        '</div>' +
      '</div>';

    var toggle = document.getElementById('auth-toggle');
    var dropdown = document.getElementById('auth-dropdown');

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      dropdown.classList.toggle('open');
    });
    document.addEventListener('click', function () {
      dropdown.classList.remove('open');
    });
  }

  fetch('/api/me.php')
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.logged_in) {
        renderUser(data);
      } else {
        renderLogin();
      }
    })
    .catch(renderLogin);
})();

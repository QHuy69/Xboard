<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0f766e">
  <title>Quản trị kho tải · {{ $appName }}</title>
  <style>
    :root{--ink:#16302e;--muted:#687b79;--line:#d8e6e3;--brand:#0d9f8c;--danger:#d54848;--soft:#eff8f7}*{box-sizing:border-box}body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:#f1f6f5}button,input,textarea,select{font:inherit}.wrap{width:min(1040px,calc(100% - 28px));margin:36px auto}.panel{background:#fff;border:1px solid var(--line);border-radius:22px;box-shadow:0 18px 48px rgba(35,79,74,.1);overflow:hidden}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:22px 26px;border-bottom:1px solid var(--line)}.panel-head h1{font-size:22px;margin:0}.links{display:flex;gap:8px}.btn,.link{border:0;border-radius:11px;padding:10px 15px;font-weight:750;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn-primary{background:var(--brand);color:#fff}.btn-soft,.link{background:var(--soft);color:#176d64}.btn-danger{background:#fff0f0;color:var(--danger)}.content{padding:26px}.login{max-width:430px;margin:65px auto}.login h2{margin:0 0 8px}.login p{color:var(--muted);margin:0 0 24px;line-height:1.55}.field{display:grid;gap:7px;margin-bottom:15px}.field label{font-size:13px;font-weight:750}.field input,.field textarea,.field select{width:100%;border:1px solid #cddcda;border-radius:11px;padding:11px 12px;background:#fff;color:var(--ink);outline:none}.field input:focus,.field textarea:focus,.field select:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(13,159,140,.12)}.field textarea{min-height:90px;resize:vertical}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.toolbar{display:flex;align-items:center;justify-content:space-between;gap:15px;margin:28px 0 14px}.toolbar h2{font-size:18px;margin:0}.app-row{border:1px solid var(--line);border-radius:17px;padding:18px;margin-bottom:14px;background:#fbfdfd}.app-row-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}.app-row-head strong{font-size:15px}.row-actions{display:flex;gap:6px}.icon-btn{width:34px;height:34px;border:1px solid var(--line);background:#fff;border-radius:9px;cursor:pointer}.icon-btn.remove{color:var(--danger)}.app-grid{display:grid;grid-template-columns:1.25fr .8fr .65fr;gap:12px}.app-grid .wide{grid-column:1/-1}.toggle{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:700}.toggle input{width:18px;height:18px;accent-color:var(--brand)}.footer-actions{position:sticky;bottom:0;display:flex;justify-content:flex-end;gap:10px;margin:24px -26px -26px;padding:16px 26px;border-top:1px solid var(--line);background:rgba(255,255,255,.94);backdrop-filter:blur(8px)}.status{min-height:22px;margin-top:12px;font-size:14px;color:var(--muted)}.status.ok{color:#087b52}.status.error{color:#c43737}.hidden{display:none!important}
    @media(max-width:700px){.wrap{width:min(100% - 16px,1040px);margin:8px auto}.panel{border-radius:16px}.panel-head,.content{padding:17px}.panel-head{align-items:flex-start}.panel-head h1{font-size:19px}.links{flex-direction:column}.grid,.app-grid{grid-template-columns:1fr}.app-grid .wide{grid-column:auto}.footer-actions{margin:20px -17px -17px;padding:14px 17px}.login{margin:35px auto}.toolbar{align-items:flex-start}}
  </style>
</head>
<body>
  <main class="wrap">
    <section class="panel">
      <header class="panel-head">
        <h1>Quản trị kho tải ứng dụng</h1>
        <div class="links"><a class="link" href="/" target="_blank">Xem trang tải</a><button id="logout" class="btn btn-danger hidden" type="button">Đăng xuất</button></div>
      </header>

      <div class="content">
        <form id="login-form" class="login">
          <h2>Đăng nhập quản trị</h2>
          <p>Dùng tài khoản quản trị Xboard. Mật khẩu không được lưu trên trang này.</p>
          <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" required></div>
          <div class="field"><label for="password">Mật khẩu</label><input id="password" name="password" type="password" autocomplete="current-password" minlength="8" required></div>
          <button class="btn btn-primary" type="submit">Đăng nhập</button>
          <div id="login-status" class="status" aria-live="polite"></div>
        </form>

        <form id="editor" class="hidden">
          <div class="grid">
            <div class="field"><label for="title">Tiêu đề trang</label><input id="title" required maxlength="120"></div>
            <div class="field"><label for="support_url">Liên kết hỗ trợ</label><input id="support_url" type="url" placeholder="https://..."></div>
          </div>
          <div class="field"><label for="subtitle">Mô tả đầu trang</label><textarea id="subtitle" maxlength="500"></textarea></div>
          <div class="field"><label for="notice">Thông báo an toàn</label><textarea id="notice" maxlength="1000"></textarea></div>

          <div class="toolbar"><h2>Danh sách ứng dụng</h2><button id="add-app" class="btn btn-soft" type="button">+ Thêm ứng dụng</button></div>
          <div id="apps"></div>

          <div class="footer-actions"><span id="save-status" class="status" aria-live="polite"></span><button class="btn btn-primary" type="submit">Lưu thay đổi</button></div>
        </form>
      </div>
    </section>
  </main>

  <script>
    (function () {
      var apiBase = @json('/api/v2/' . $securePath . '/resource-portal');
      var loginUrl = '/api/v2/passport/auth/login';
      var tokenKey = 'zaoguang_resource_admin';
      var apps = [];
      var loginForm = document.getElementById('login-form');
      var editor = document.getElementById('editor');
      var logout = document.getElementById('logout');
      var appsRoot = document.getElementById('apps');

      function token() { return sessionStorage.getItem(tokenKey) || ''; }
      function status(id, message, type) { var node = document.getElementById(id); node.textContent = message || ''; node.className = 'status ' + (type || ''); }
      function escapeHtml(value) { var div = document.createElement('div'); div.textContent = value == null ? '' : String(value); return div.innerHTML; }
      async function parse(response) { var body = await response.json().catch(function () { return {}; }); if (!response.ok) throw new Error(body.message || body.error || 'Yêu cầu thất bại'); return body.data === undefined ? body : body.data; }
      async function adminFetch(path, options) {
        var opts = Object.assign({}, options || {});
        opts.headers = Object.assign({'Accept':'application/json','Content-Type':'application/json','Authorization':token()}, opts.headers || {});
        return parse(await fetch(apiBase + path, opts));
      }

      function platformOptions(selected) {
        return [['windows','Windows'],['android','Android'],['macos','macOS'],['ios','iOS'],['linux','Linux'],['other','Khác']].map(function (item) {
          return '<option value="' + item[0] + '"' + (item[0] === selected ? ' selected' : '') + '>' + item[1] + '</option>';
        }).join('');
      }
      function renderApps() {
        if (!apps.length) appsRoot.innerHTML = '<div class="status">Chưa có ứng dụng. Nhấn “Thêm ứng dụng” để tạo.</div>';
        else appsRoot.innerHTML = apps.map(function (app, index) {
          return '<section class="app-row" data-index="' + index + '">' +
            '<div class="app-row-head"><strong>Ứng dụng ' + (index + 1) + '</strong><div class="row-actions">' +
            '<button class="icon-btn" type="button" data-action="up" title="Đưa lên">↑</button><button class="icon-btn" type="button" data-action="down" title="Đưa xuống">↓</button><button class="icon-btn remove" type="button" data-action="remove" title="Xóa">×</button></div></div>' +
            '<div class="app-grid"><div class="field"><label>Tên ứng dụng</label><input data-key="name" maxlength="100" required value="' + escapeHtml(app.name) + '"></div>' +
            '<div class="field"><label>Nền tảng</label><select data-key="platform">' + platformOptions(app.platform) + '</select></div>' +
            '<div class="field"><label>Phiên bản</label><input data-key="version" maxlength="50" value="' + escapeHtml(app.version) + '"></div>' +
            '<div class="field wide"><label>Liên kết tải xuống</label><input data-key="download_url" type="url" placeholder="https://..." value="' + escapeHtml(app.download_url) + '"></div>' +
            '<div class="field wide"><label>Mô tả ngắn</label><textarea data-key="description" maxlength="300">' + escapeHtml(app.description) + '</textarea></div>' +
            '<label class="toggle wide"><input data-key="enabled" type="checkbox"' + (app.enabled ? ' checked' : '') + '> Hiển thị ứng dụng này</label></div></section>';
        }).join('');
      }
      function syncApps() {
        Array.prototype.forEach.call(appsRoot.querySelectorAll('.app-row'), function (row) {
          var index = Number(row.dataset.index); if (!apps[index]) return;
          Array.prototype.forEach.call(row.querySelectorAll('[data-key]'), function (field) {
            apps[index][field.dataset.key] = field.type === 'checkbox' ? field.checked : field.value;
          });
        });
      }
      function showEditor(config) {
        document.getElementById('title').value = config.title || '';
        document.getElementById('subtitle').value = config.subtitle || '';
        document.getElementById('notice').value = config.notice || '';
        document.getElementById('support_url').value = config.support_url || '';
        apps = Array.isArray(config.apps) ? config.apps : [];
        renderApps(); loginForm.classList.add('hidden'); editor.classList.remove('hidden'); logout.classList.remove('hidden');
      }
      async function load() {
        try { showEditor(await adminFetch('/fetch')); }
        catch (error) { sessionStorage.removeItem(tokenKey); editor.classList.add('hidden'); logout.classList.add('hidden'); loginForm.classList.remove('hidden'); status('login-status', 'Phiên đăng nhập đã hết hạn.', 'error'); }
      }

      loginForm.addEventListener('submit', async function (event) {
        event.preventDefault(); status('login-status', 'Đang đăng nhập...');
        try {
          var data = await parse(await fetch(loginUrl, {method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify({email:document.getElementById('email').value,password:document.getElementById('password').value})}));
          if (!data.is_admin || !data.auth_data) throw new Error('Tài khoản này không có quyền quản trị');
          sessionStorage.setItem(tokenKey, data.auth_data); document.getElementById('password').value = ''; await load();
        } catch (error) { status('login-status', error.message, 'error'); }
      });
      logout.addEventListener('click', function () { sessionStorage.removeItem(tokenKey); location.reload(); });
      document.getElementById('add-app').addEventListener('click', function () { syncApps(); apps.push({name:'Ứng dụng mới',platform:'windows',version:'',download_url:'',description:'',enabled:true,sort:apps.length}); renderApps(); });
      appsRoot.addEventListener('click', function (event) {
        var button = event.target.closest('[data-action]'); if (!button) return; syncApps(); var row = button.closest('.app-row'); var index = Number(row.dataset.index); var action = button.dataset.action;
        if (action === 'remove') apps.splice(index, 1);
        if (action === 'up' && index > 0) { var previous = apps[index - 1]; apps[index - 1] = apps[index]; apps[index] = previous; }
        if (action === 'down' && index < apps.length - 1) { var next = apps[index + 1]; apps[index + 1] = apps[index]; apps[index] = next; }
        renderApps();
      });
      editor.addEventListener('submit', async function (event) {
        event.preventDefault(); syncApps(); status('save-status', 'Đang lưu...');
        var payload = {title:document.getElementById('title').value,subtitle:document.getElementById('subtitle').value,notice:document.getElementById('notice').value,support_url:document.getElementById('support_url').value,apps:apps.map(function(app,index){return Object.assign({},app,{enabled:!!app.enabled,sort:index});})};
        try { showEditor(await adminFetch('/save',{method:'POST',body:JSON.stringify(payload)})); status('save-status', 'Đã lưu thành công.', 'ok'); }
        catch (error) { status('save-status', error.message, 'error'); }
      });
      if (token()) load();
    })();
  </script>
</body>
</html>

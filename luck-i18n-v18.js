/* Runtime translations for the pre-built Luck theme. */
(function () {
  var brandTitle = 'ZaoGuang Service';
  var supported = ['zh-CN', 'zh-TW', 'en-US', 'vi-VN', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];
  // The server receives the real Accept-Language header.  Use it first because
  // some Chromium setups expose only en-US through navigator.language.
  var serverLanguages = Array.isArray(window.LUCK_SERVER_LANGUAGES) ? window.LUCK_SERVER_LANGUAGES : [];
  var browserLanguages = (navigator.languages || [navigator.language || '']).map(function (value) {
    return String(value || '').replace('_', '-');
  });
  var raw = serverLanguages.concat(browserLanguages).map(function (value) {
    return String(value || '').replace('_', '-');
  }).filter(Boolean);
  var locale = raw.find(function (value) { return supported.indexOf(value) !== -1; }) ||
    supported.find(function (value) {
      var base = value.toLowerCase().split('-')[0];
      return raw.some(function (preferred) { return preferred.toLowerCase().split('-')[0] === base; });
    }) || window.LUCK_DEFAULT_LANGUAGE || 'vi-VN';
  // Chrome's display language and its web-content language list can differ.
  // For Vietnamese systems, do not let a generic en-US content preference hide
  // Vietnamese when it is present elsewhere in the browser preferences.
  var timeZone = '';
  try { timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (ignore) {}
  var hasVietnamesePreference = raw.some(function (value) { return value.toLowerCase().split('-')[0] === 'vi'; });
  if (locale === 'en-US' && (hasVietnamesePreference || /^(Asia\/Ho_Chi_Minh|Asia\/Saigon)$/.test(timeZone))) locale = 'vi-VN';
  document.documentElement.lang = locale;
  window.V2BOARD_CONFIG = window.V2BOARD_CONFIG || {};
  window.V2BOARD_CONFIG.LANGUAGE = locale;
  // Keep the last language choice available to authenticated API requests.
  // The backend stores this value on the user record and uses it for
  // localized subscription-info nodes, regardless of the client language.
  try {
    document.cookie = 'luck_locale=' + encodeURIComponent(locale) + '; path=/; max-age=31536000; SameSite=Lax';
  } catch (ignoreCookie) {}
  function enforceBrandTitle() {
    if (document.title !== brandTitle) document.title = brandTitle;
  }
  enforceBrandTitle();
  if (document.head) new MutationObserver(enforceBrandTitle).observe(document.head, { childList: true, characterData: true, subtree: true });

  var en = {
    '仪表板':'Dashboard','账户概览与服务状态':'Account overview and service status','首页':'Home','主要功能':'Main features','欢迎回来':'Welcome back','欢迎回来,':'Welcome back,','欢迎回来，':'Welcome back,','已验证':'Verified','管理员':'Administrator','我的订阅':'My subscription','无订阅':'No subscription','流量重置时间':'Traffic reset','不重置':'No reset','到期时间':'Expiration','永久':'Never expires','永久有效':'Never expires','立即购买订阅':'Buy subscription','佣金余额':'Commission balance','账户余额':'Account balance','我的钱包':'My wallet','重要通知':'Important notifications','暂无通知':'No notifications','客户端下载':'Client downloads','桌面端':'Desktop','安卓手机':'Android','苹果设备':'Apple device','流量使用情况':'Traffic usage','已使用流量':'Used traffic','总流量':'Total traffic','已用流量':'Used','剩余流量':'Remaining','订阅链接':'Subscription links','订阅链接 ':'Subscription links','通用订阅':'Universal subscription','适用于大部分客户端':'Works with most clients','Clash 订阅':'Clash subscription','V2RayN 订阅':'V2RayN subscription','Shadowsocks 订阅':'Shadowsocks subscription','SingBox 订阅':'SingBox subscription','Hiddify 订阅':'Hiddify subscription','适用于 Clash 系列':'For Clash clients','适用于 V2Ray 系列':'For V2Ray clients','适用于 SS 系列':'For Shadowsocks clients','适用于 SingBox 系列':'For SingBox clients','适用于 Hiddify 系列':'For Hiddify clients','节点列表':'Nodes','订阅套餐':'Plans','订单管理':'Orders','工单系统':'Tickets','流量明细':'Traffic details','邀请推广':'Referral','个人资料':'Profile','使用文档':'Documentation','用户中心':'User center','登录':'Log in','立即登录':'Log in now','立即注册':'Register now','注册':'Register','退出登录':'Log out','修改密码':'Change password','刷新':'Refresh','刷新数据':'Refresh data','下载':'Download','复制链接':'Copy link','复制订阅链接':'Copy subscription link','二维码':'QR code','查看二维码':'View QR code','保存二维码':'Save QR code','支付':'Pay','立即支付':'Pay now','取消':'Cancel','确认':'Confirm','详情':'Details','状态':'Status','类型':'Type','金额':'Amount','操作':'Actions','创建时间':'Created at','套餐详情':'Plan details','优惠券':'Coupon','充值':'Top up','余额支付':'Balance payment','礼品卡兑换':'Redeem gift card','兑换礼品卡':'Redeem gift card','生成邀请码':'Generate invitation code','暂无邀请码':'No invitation code','暂无佣金记录':'No commission records','加载失败':'Failed to load','重新加载':'Reload','正在加载世界地图...':'Loading world map...','正在生成二维码...':'Generating QR code...','通过':'Via','Windows':'Windows','Android':'Android','iOS':'iOS','macOS':'macOS','Linux':'Linux','已使用流量':'Traffic used','流量充足':'Traffic available','服务条款':'Terms of service','支持':'Support','联系':'Contact','特性':'Features','价格':'Pricing','产品':'Product','优势':'Advantages','开始注册使用':'Start using','进入用户中心':'Open user center','登录账户':'Account login','没有账号？':'No account?','已有账号？立即登录':'Already have an account? Log in','邮箱':'Email','密码':'Password','记住我':'Remember me','忘记密码？':'Forgot password?','请输入邮箱':'Enter email','请输入密码':'Enter password','登录成功':'Login successful','登录失败':'Login failed','语言':'Language'
  };
  var vi = {
    '仪表板':'Bảng điều khiển','账户概览与服务状态':'Tổng quan tài khoản và trạng thái dịch vụ','首页':'Trang chủ','主要功能':'Tính năng chính','欢迎回来':'Chào mừng bạn trở lại','欢迎回来,':'Chào mừng bạn trở lại,','已验证':'Đã xác minh','管理员':'Quản trị viên','我的订阅':'Gói đăng ký của tôi','无订阅':'Chưa có gói đăng ký','流量重置时间':'Thời gian đặt lại lưu lượng','不重置':'Không đặt lại','到期时间':'Ngày hết hạn','永久有效':'Không giới hạn','立即购买订阅':'Mua gói ngay','佣金余额':'Số dư hoa hồng','账户余额':'Số dư tài khoản','我的钱包':'Ví của tôi','重要通知':'Thông báo quan trọng','暂无通知':'Chưa có thông báo','客户端下载':'Tải ứng dụng','桌面端':'Máy tính','安卓手机':'Android','苹果设备':'Thiết bị Apple','流量使用情况':'Tình trạng sử dụng lưu lượng','已使用流量':'Đã sử dụng','总流量':'Tổng lưu lượng','已用流量':'Đã dùng','剩余流量':'Còn lại','订阅链接':'Liên kết đăng ký','通用订阅':'Đăng ký chung','Clash 订阅':'Gói Clash','V2RayN 订阅':'Gói V2RayN','Shadowsocks 订阅':'Gói Shadowsocks','SingBox 订阅':'Gói SingBox','Hiddify 订阅':'Gói Hiddify','适用于大部分客户端':'Hoạt động với hầu hết ứng dụng','适用于 Clash 系列':'Dùng cho ứng dụng Clash','适用于 V2Ray 系列':'Dùng cho ứng dụng V2Ray','适用于 SS 系列':'Dùng cho ứng dụng Shadowsocks','适用于 SingBox 系列':'Dùng cho ứng dụng SingBox','适用于 Hiddify 系列':'Dùng cho ứng dụng Hiddify','节点列表':'Danh sách node','订阅套餐':'Gói đăng ký','订单管理':'Quản lý đơn hàng','工单系统':'Hệ thống ticket','流量明细':'Chi tiết lưu lượng','邀请推广':'Giới thiệu bạn bè','个人资料':'Hồ sơ cá nhân','使用文档':'Tài liệu hướng dẫn','用户中心':'Trung tâm người dùng','登录':'Đăng nhập','立即登录':'Đăng nhập ngay','立即注册':'Đăng ký ngay','注册':'Đăng ký','退出登录':'Đăng xuất','修改密码':'Đổi mật khẩu','刷新':'Làm mới','刷新数据':'Làm mới dữ liệu','下载':'Tải xuống','复制链接':'Sao chép liên kết','复制订阅链接':'Sao chép liên kết đăng ký','二维码':'Mã QR','查看二维码':'Xem mã QR','保存二维码':'Lưu mã QR','支付':'Thanh toán','立即支付':'Thanh toán ngay','取消':'Hủy','确认':'Xác nhận','详情':'Chi tiết','状态':'Trạng thái','类型':'Loại','金额':'Số tiền','操作':'Thao tác','创建时间':'Thời gian tạo','套餐详情':'Chi tiết gói','优惠券':'Mã giảm giá','充值':'Nạp tiền','余额支付':'Thanh toán bằng số dư','礼品卡兑换':'Đổi thẻ quà tặng','兑换礼品卡':'Đổi thẻ quà tặng','生成邀请码':'Tạo mã mời','暂无邀请码':'Chưa có mã mời','暂无佣金记录':'Chưa có lịch sử hoa hồng','加载失败':'Tải thất bại','重新加载':'Tải lại','正在加载世界地图...':'Đang tải bản đồ thế giới...','正在生成二维码...':'Đang tạo mã QR...','支持':'Hỗ trợ','联系':'Liên hệ','特性':'Tính năng','价格':'Giá','产品':'Sản phẩm','优势':'Ưu điểm','开始注册使用':'Bắt đầu sử dụng','进入用户中心':'Mở trung tâm người dùng','登录账户':'Đăng nhập tài khoản','没有账号？':'Chưa có tài khoản?','已有账号？立即登录':'Đã có tài khoản? Đăng nhập','邮箱':'Email','密码':'Mật khẩu','记住我':'Ghi nhớ tôi','忘记密码？':'Quên mật khẩu?','请输入邮箱':'Nhập email','请输入密码':'Nhập mật khẩu','登录成功':'Đăng nhập thành công','登录失败':'Đăng nhập thất bại','语言':'Ngôn ngữ'
  };
  Object.assign(en, {
    '文档':'Documentation','使用指南与帮助中心':'Guides and help center','详细的使用指南和常见问题解答':'Detailed guides and frequently asked questions','暂无文档':'No documents','管理员还没有创建任何文档':'The administrator has not created any documents yet',
    '个人资料':'Profile','账户与安全管理':'Account and security management','账户设置与安全管理':'Account settings and security management','安全设置':'Security settings','当前密码':'Current password','请输入当前密码':'Enter your current password','新密码':'New password','请输入新密码':'Enter your new password','确认密码':'Confirm password','请再次输入新密码':'Enter your new password again','通知设置':'Notification settings','到期邮件提醒':'Expiration email reminder','开启后，服务到期前24小时会发送邮件提醒':'When enabled, an email reminder is sent 24 hours before service expiration','流量邮件提醒':'Traffic email reminder','开启后，流量使用达到95%时会发送邮件提醒':'When enabled, an email reminder is sent when traffic usage reaches 95%','订阅管理':'Subscription management','重置订阅链接':'Reset subscription link','重置后原有的订阅链接将失效，请及时更新客户端配置。':'Your existing subscription link will stop working after reset. Update your client configuration promptly.','用户头像':'User avatar',
    '邀请':'Referral','推广-奖励与佣金管理':'Referral rewards and commission management','推广奖励与佣金管理':'Referral rewards and commission management','我的邀请':'My referrals','当前剩余佣金':'Available commission','划转到余额':'Transfer to balance','推广 /佣金提现':'Referral / commission withdrawal','推广佣金提现':'Referral / commission withdrawal','已注册用户':'Registered users','佣金比例':'Commission rate','确认中佣金':'Pending commission','累计佣金':'Total commission','邀请管理':'Invitation management','邀请码管理':'Invitation code management','佣金发放记录':'Commission payout history','点击上方按钮生成邀请码':'Click the button above to generate an invitation code','邀请好友注册付费后获得佣金':'Earn commission when invited friends register and pay',
    '购买订阅':'Buy subscription','套餐':'Plan','服务':'Service','查看详情':'View details','保存':'Save','提交':'Submit','返回':'Back','删除':'Delete','编辑':'Edit','关闭':'Close','确定':'OK','错误':'Error','成功':'Success','加载中...':'Loading...','暂无数据':'No data'
  });
  Object.assign(vi, {
    '通用':'Chung','通用Liên kết đăng ký':'Liên kết đăng ký chung','扫描':'Quét','扫描二维码':'Quét mã QR','使用客户端扫描':'Dùng ứng dụng để quét','快速导入':'Nhập nhanh',
    '会员':'Thành viên','每月1日重置':'Đặt lại vào ngày 1 hàng tháng','自动续费':'Tự động gia hạn','无':'Không','无限':'Không giới hạn',
    '欢迎回来，':'Chào mừng bạn trở lại,','永久':'Không giới hạn',
    '文档':'Tài liệu','使用指南与帮助中心':'Hướng dẫn sử dụng và trung tâm trợ giúp','详细的使用指南和常见问题解答':'Hướng dẫn chi tiết và câu hỏi thường gặp','暂无文档':'Chưa có tài liệu','管理员还没有创建任何文档':'Quản trị viên chưa tạo tài liệu nào',
    '个人资料':'Hồ sơ cá nhân','账户与安全管理':'Quản lý tài khoản và bảo mật','账户设置与安全管理':'Cài đặt tài khoản và quản lý bảo mật','安全设置':'Cài đặt bảo mật','当前密码':'Mật khẩu hiện tại','请输入当前密码':'Nhập mật khẩu hiện tại','新密码':'Mật khẩu mới','请输入新密码':'Nhập mật khẩu mới','确认密码':'Xác nhận mật khẩu','请再次输入新密码':'Nhập lại mật khẩu mới','通知设置':'Cài đặt thông báo','到期邮件提醒':'Nhắc hạn qua email','开启后，服务到期前24小时会发送邮件提醒':'Khi bật, email nhắc hạn sẽ được gửi trước 24 giờ','流量邮件提醒':'Nhắc lưu lượng qua email','开启后，流量使用达到95%时会发送邮件提醒':'Khi bật, email sẽ được gửi khi lưu lượng đạt 95%','订阅管理':'Quản lý đăng ký','重置订阅链接':'Đặt lại liên kết đăng ký','重置后原有的订阅链接将失效，请及时更新客户端配置。':'Liên kết đăng ký cũ sẽ hết hiệu lực sau khi đặt lại. Hãy cập nhật ứng dụng của bạn.','用户头像':'Ảnh đại diện người dùng',
    '邀请':'Mời bạn bè','推广-奖励与佣金管理':'Quản lý giới thiệu, thưởng và hoa hồng','推广奖励与佣金管理':'Quản lý giới thiệu, thưởng và hoa hồng','我的邀请':'Lời mời của tôi','当前剩余佣金':'Hoa hồng khả dụng','划转到余额':'Chuyển vào số dư','推广 /佣金提现':'Giới thiệu / rút hoa hồng','推广佣金提现':'Giới thiệu / rút hoa hồng','已注册用户':'Người dùng đã đăng ký','佣金比例':'Tỷ lệ hoa hồng','确认中佣金':'Hoa hồng chờ xác nhận','累计佣金':'Tổng hoa hồng','邀请管理':'Quản lý lời mời','邀请码管理':'Quản lý mã mời','佣金发放记录':'Lịch sử chi trả hoa hồng','点击上方按钮生成邀请码':'Nhấn nút phía trên để tạo mã mời','邀请好友注册付费后获得佣金':'Nhận hoa hồng khi bạn bè được mời đăng ký và thanh toán',
    '购买订阅':'Mua gói đăng ký','套餐':'Gói','服务':'Dịch vụ','查看详情':'Xem chi tiết','保存':'Lưu','提交':'Gửi','返回':'Quay lại','删除':'Xóa','编辑':'Chỉnh sửa','关闭':'Đóng','确定':'Đồng ý','错误':'Lỗi','成功':'Thành công','加载中...':'Đang tải...','暂无数据':'Chưa có dữ liệu',
    'Commission':'Hoa hồng','Account':'Tài khoản','Total':'Tổng','天':'ngày','天前':'ngày trước','月':'tháng','年':'năm','COMMISSION':'HOA HỒNG','ACCOUNT':'TÀI KHOẢN','TOTAL':'TỔNG','Traffic available':'Lưu lượng còn đủ','MAIN FEATURES':'TÍNH NĂNG CHÍNH','Home':'Trang chủ','Plans':'Gói đăng ký','Nodes':'Danh sách node','Orders':'Quản lý đơn hàng','Tickets':'Hệ thống ticket','Traffic details':'Chi tiết lưu lượng','Referral':'Giới thiệu bạn bè','Profile':'Hồ sơ cá nhân','Documentation':'Tài liệu','Dashboard':'Bảng điều khiển','Change password':'Đổi mật khẩu','Generate invitation code':'Tạo mã mời','No invitation code':'Chưa có mã mời','No commission records':'Chưa có lịch sử hoa hồng','No documents':'Chưa có tài liệu','Administrator':'Quản trị viên','Redeem gift card':'Đổi thẻ quà tặng'
  });
  Object.assign(en, {
    '订阅方案':'Plans','选择最适合您的服务套餐':'Choose the service plan that suits you best','常规套餐':'Standard plans','不限时套餐':'Unlimited plans','暂无可用套餐':'No plans available','服务器':'Servers','全球节点实时状态监控':'Real-time global node status','全球节点分布':'Global node distribution','暂无节点数据':'No node data','工单':'Tickets','技术支持与问题反馈':'Technical support and feedback','创建工单':'Create ticket','主题':'Subject','级别':'Priority','最后回复':'Last reply','流量统计':'Traffic statistics','详细的使用数据分析':'Detailed usage analysis','今日流量':'Today\'s traffic','本月流量':'This month\'s traffic','流量使用趋势':'Traffic usage trend','上传':'Upload','记录时间':'Recorded at','流量明细记录':'Traffic detail records','上传流量':'Upload traffic','下载流量':'Download traffic','服务器倍率':'Server multiplier','暂无流量记录':'No traffic records','余额':'Balance','订单':'Orders','交易记录与订单管理':'Transaction and order management','全部':'All','待支付':'Pending payment','开通中':'Activating','已取消':'Cancelled','已完成':'Completed','已折抵':'Deducted','暂无订单':'No orders','您还没有任何订单记录':'You have no order history'
  });
  Object.assign(vi, {
    '订阅方案':'Gói đăng ký','选择最适合您的服务套餐':'Chọn gói dịch vụ phù hợp nhất với bạn','常规套餐':'Gói thông thường','不限时套餐':'Gói không giới hạn thời gian','暂无可用套餐':'Chưa có gói khả dụng','服务器':'Máy chủ','全球节点实时状态监控':'Giám sát trạng thái node toàn cầu theo thời gian thực','全球节点分布':'Phân bố node toàn cầu','暂无节点数据':'Chưa có dữ liệu node','工单':'Ticket','技术支持与问题反馈':'Hỗ trợ kỹ thuật và phản hồi vấn đề','创建工单':'Tạo ticket','主题':'Chủ đề','级别':'Mức độ','最后回复':'Phản hồi cuối','流量统计':'Thống kê lưu lượng','详细的使用数据分析':'Phân tích dữ liệu sử dụng chi tiết','今日流量':'Lưu lượng hôm nay','本月流量':'Lưu lượng tháng này','流量使用趋势':'Xu hướng sử dụng lưu lượng','上传':'Tải lên','记录时间':'Thời gian ghi nhận','流量明细记录':'Bản ghi chi tiết lưu lượng','上传流量':'Lưu lượng tải lên','下载流量':'Lưu lượng tải xuống','服务器倍率':'Hệ số máy chủ','暂无流量记录':'Chưa có bản ghi lưu lượng','余额':'Số dư','订单':'Đơn hàng','交易记录与订单管理':'Lịch sử giao dịch và quản lý đơn hàng','全部':'Tất cả','待支付':'Chờ thanh toán','开通中':'Đang kích hoạt','已取消':'Đã hủy','已完成':'Đã hoàn tất','已折抵':'Đã khấu trừ','暂无订单':'Chưa có đơn hàng','您还没有任何订单记录':'Bạn chưa có lịch sử đơn hàng nào'
  });
  var tw = {};
  Object.keys(en).forEach(function (key) { tw[key] = en[key]; });
  Object.assign(tw, { '仪表板':'儀表板','首页':'首頁','用户中心':'用戶中心','个人资料':'個人資料','使用文档':'使用文件','节点列表':'節點列表','订阅套餐':'訂閱方案','订单管理':'訂單管理','工单系统':'工單系統','流量明细':'流量明細','邀请推广':'邀請推廣','登录':'登入','立即登录':'立即登入','立即注册':'立即註冊','退出登录':'登出','修改密码':'修改密碼','客户端下载':'用戶端下載','重要通知':'重要通知','暂无通知':'暫無通知','订阅链接':'訂閱連結','复制链接':'複製連結','二维码':'QR 碼','保存二维码':'儲存 QR 碼','充值':'儲值','优惠券':'優惠券','确认':'確認','取消':'取消','状态':'狀態','金额':'金額','操作':'操作','创建时间':'建立時間','套餐详情':'方案詳情','支持':'支援','联系':'聯絡','价格':'價格','产品':'產品','优势':'優勢','特性':'特色' });
  var ru = {};
  Object.keys(en).forEach(function (key) { ru[key] = en[key]; });
  Object.assign(ru, { '仪表板':'Панель управления','首页':'Главная','用户中心':'Центр пользователя','个人资料':'Профиль','使用文档':'Документация','节点列表':'Список узлов','订阅套餐':'Тарифы','订单管理':'Заказы','工单系统':'Поддержка','流量明细':'Трафик','邀请推广':'Реферальная программа','登录':'Войти','立即登录':'Войти сейчас','立即注册':'Зарегистрироваться','退出登录':'Выйти','修改密码':'Изменить пароль','客户端下载':'Скачать клиент','重要通知':'Важные уведомления','暂无通知':'Нет уведомлений','订阅链接':'Ссылки подписки','复制链接':'Копировать ссылку','二维码':'QR-код','保存二维码':'Сохранить QR-код','充值':'Пополнить','优惠券':'Купон','确认':'Подтвердить','取消':'Отмена','状态':'Статус','金额':'Сумма','操作':'Действия','创建时间':'Создано','套餐详情':'Детали тарифа','支持':'Поддержка','联系':'Контакты','价格':'Цены','产品':'Продукт','优势':'Преимущества','特性':'Возможности' });
  var ja = {}; Object.keys(en).forEach(function (key) { ja[key] = en[key]; });
  Object.assign(ja, { '仪表板':'ダッシュボード','账户概览与服务状态':'アカウント概要とサービス状態','首页':'ホーム','主要功能':'主な機能','节点列表':'ノード一覧','订阅套餐':'プラン','订单管理':'注文管理','工单系统':'サポートチケット','流量明细':'トラフィック詳細','邀请推广':'紹介','个人资料':'プロフィール','使用文档':'ドキュメント','用户中心':'ユーザーセンター','登录':'ログイン','立即登录':'ログイン','立即注册':'今すぐ登録','注册':'登録','退出登录':'ログアウト','修改密码':'パスワード変更','重要通知':'重要なお知らせ','暂无通知':'お知らせはありません','客户端下载':'クライアントをダウンロード','订阅链接':'サブスクリプションリンク','复制链接':'リンクをコピー','二维码':'QRコード','保存二维码':'QRコードを保存','支付':'支払う','立即支付':'今すぐ支払う','取消':'キャンセル','确认':'確認','详情':'詳細','状态':'状態','类型':'種類','金额':'金額','操作':'操作','创建时间':'作成日時','优惠券':'クーポン','充值':'チャージ','安全设置':'セキュリティ設定','当前密码':'現在のパスワード','新密码':'新しいパスワード','确认密码':'パスワード確認','通知设置':'通知設定','订阅管理':'サブスクリプション管理','重置订阅链接':'サブスクリプションリンクをリセット','邀请':'紹介','我的邀请':'紹介一覧','当前剩余佣金':'利用可能なコミッション','划转到余额':'残高へ振替','已注册用户':'登録済みユーザー','佣金比例':'コミッション率','邀请码管理':'紹介コード管理','生成邀请码':'紹介コードを作成','暂无邀请码':'紹介コードはありません','暂无佣金记录':'コミッション履歴はありません','支持':'サポート','联系':'お問い合わせ','语言':'言語' });
  var ko = {}; Object.keys(en).forEach(function (key) { ko[key] = en[key]; });
  Object.assign(ko, { '仪表板':'대시보드','账户概览与服务状态':'계정 개요 및 서비스 상태','首页':'홈','主要功能':'주요 기능','节点列表':'노드 목록','订阅套餐':'요금제','订单管理':'주문 관리','工单系统':'지원 티켓','流量明细':'트래픽 상세','邀请推广':'추천','个人资料':'프로필','使用文档':'문서','用户中心':'사용자 센터','登录':'로그인','立即登录':'지금 로그인','立即注册':'지금 가입','注册':'가입','退出登录':'로그아웃','修改密码':'비밀번호 변경','重要通知':'중요 알림','暂无通知':'알림 없음','客户端下载':'클라이언트 다운로드','订阅链接':'구독 링크','复制链接':'링크 복사','二维码':'QR 코드','保存二维码':'QR 코드 저장','支付':'결제','立即支付':'지금 결제','取消':'취소','确认':'확인','详情':'상세','状态':'상태','类型':'유형','金额':'금액','操作':'작업','创建时间':'생성 시간','优惠券':'쿠폰','充值':'충전','安全设置':'보안 설정','当前密码':'현재 비밀번호','新密码':'새 비밀번호','确认密码':'비밀번호 확인','通知设置':'알림 설정','订阅管理':'구독 관리','重置订阅链接':'구독 링크 재설정','邀请':'추천','我的邀请':'내 추천','当前剩余佣金':'사용 가능 수수료','划转到余额':'잔액으로 이체','已注册用户':'가입한 사용자','佣金比例':'수수료 비율','邀请码管理':'추천 코드 관리','生成邀请码':'추천 코드 생성','暂无邀请码':'추천 코드 없음','暂无佣金记录':'수수료 기록 없음','支持':'지원','联系':'문의','语言':'언어' });
  var fa = {}; Object.keys(en).forEach(function (key) { fa[key] = en[key]; });
  Object.assign(fa, { '仪表板':'داشبورد','账户概览与服务状态':'نمای کلی حساب و وضعیت سرویس','首页':'خانه','主要功能':'امکانات اصلی','节点列表':'فهرست گره‌ها','订阅套餐':'طرح‌ها','订单管理':'مدیریت سفارش‌ها','工单系统':'تیکت‌های پشتیبانی','流量明细':'جزئیات ترافیک','邀请推广':'دعوت','个人资料':'پروفایل','使用文档':'مستندات','用户中心':'مرکز کاربر','登录':'ورود','立即登录':'اکنون وارد شوید','立即注册':'اکنون ثبت‌نام کنید','注册':'ثبت‌نام','退出登录':'خروج','修改密码':'تغییر گذرواژه','重要通知':'اعلان‌های مهم','暂无通知':'اعلانی نیست','客户端下载':'دریافت برنامه','订阅链接':'پیوند اشتراک','复制链接':'کپی پیوند','二维码':'کد QR','保存二维码':'ذخیره کد QR','支付':'پرداخت','立即支付':'اکنون پرداخت کنید','取消':'لغو','确认':'تأیید','详情':'جزئیات','状态':'وضعیت','类型':'نوع','金额':'مبلغ','操作':'عملیات','创建时间':'زمان ایجاد','优惠券':'کوپن','充值':'شارژ حساب','安全设置':'تنظیمات امنیتی','当前密码':'گذرواژه فعلی','新密码':'گذرواژه جدید','确认密码':'تأیید گذرواژه','通知设置':'تنظیمات اعلان','订阅管理':'مدیریت اشتراک','重置订阅链接':'بازنشانی پیوند اشتراک','邀请':'دعوت','我的邀请':'دعوت‌های من','当前剩余佣金':'کمیسیون در دسترس','划转到余额':'انتقال به موجودی','已注册用户':'کاربران ثبت‌نام‌شده','佣金比例':'نرخ کمیسیون','邀请码管理':'مدیریت کد دعوت','生成邀请码':'ایجاد کد دعوت','暂无邀请码':'کد دعوتی نیست','暂无佣金记录':'سابقه کمیسیونی نیست','支持':'پشتیبانی','联系':'تماس','语言':'زبان' });
  Object.assign(tw, {
    '创建新账户':'建立新帳戶','Email地址':'電子郵件地址','还没有账号？':'還沒有帳戶？','已经有账户？':'已經有帳戶？',
    '邀请码':'邀請碼','邀请码（可选）':'邀請碼（選填）','邀请码 (可选)':'邀請碼（選填）','至少8位':'至少 8 個字元',
    '（至少8位）':'（至少 8 個字元）','(至少8位)':'（至少 8 個字元）','注册即代表同意':'註冊即表示同意',
    '重置密码':'重設密碼','重置':'重設','Email验证':'電子郵件驗證','获取验证码':'取得驗證碼',
    '请输入验证码':'請輸入驗證碼','请输入Email':'請輸入電子郵件','请输入Email地址':'請輸入電子郵件地址'
  });
  Object.assign(ja, {
    '创建新账户':'新しいアカウントを作成','Email地址':'メールアドレス','还没有账号？':'アカウントをお持ちでないですか？','已经有账户？':'アカウントをお持ちですか？',
    '邀请码':'招待コード','邀请码（可选）':'招待コード（任意）','邀请码 (可选)':'招待コード（任意）','至少8位':'8文字以上',
    '（至少8位）':'（8文字以上）','(至少8位)':'（8文字以上）','注册即代表同意':'登録することで同意します',
    '重置密码':'パスワードをリセット','重置':'リセット','Email验证':'メール認証','获取验证码':'認証コードを取得',
    '请输入验证码':'認証コードを入力','请输入Email':'メールアドレスを入力','请输入Email地址':'メールアドレスを入力'
  });
  Object.assign(ko, {
    '创建新账户':'새 계정 만들기','Email地址':'이메일 주소','还没有账号？':'아직 계정이 없나요?','已经有账户？':'이미 계정이 있나요?',
    '邀请码':'초대 코드','邀请码（可选）':'초대 코드 (선택 사항)','邀请码 (可选)':'초대 코드 (선택 사항)','至少8位':'8자 이상',
    '（至少8位）':'(8자 이상)','(至少8位)':'(8자 이상)','注册即代表同意':'가입하면 다음에 동의하는 것입니다',
    '重置密码':'비밀번호 재설정','重置':'재설정','Email验证':'이메일 인증','获取验证码':'인증 코드 받기',
    '请输入验证码':'인증 코드를 입력하세요','请输入Email':'이메일을 입력하세요','请输入Email地址':'이메일 주소를 입력하세요'
  });
  Object.assign(fa, {
    '创建新账户':'ایجاد حساب جدید','Email地址':'نشانی ایمیل','还没有账号؟':'حساب ندارید؟','已经有账户？':'حساب دارید؟',
    '邀请码':'کد دعوت','邀请码（可选）':'کد دعوت (اختیاری)','邀请码 (可选)':'کد دعوت (اختیاری)','至少8位':'حداقل ۸ نویسه',
    '（至少8位）':'(حداقل ۸ نویسه)','(至少8位)':'(حداقل ۸ نویسه)','注册即代表同意':'با ثبت‌نام موافق هستید با',
    '重置密码':'بازنشانی گذرواژه','重置':'بازنشانی','Email验证':'تأیید ایمیل','获取验证码':'دریافت کد تأیید',
    '请输入验证码':'کد تأیید را وارد کنید','请输入Email':'ایمیل را وارد کنید','请输入Email地址':'نشانی ایمیل را وارد کنید'
  });
  Object.assign(ru, {
    '创建新账户':'Создать новый аккаунт','Email地址':'Адрес электронной почты','还没有账号？':'Нет аккаунта?','已经有账户？':'Уже есть аккаунт?',
    '邀请码':'Код приглашения','邀请码（可选）':'Код приглашения (необязательно)','邀请码 (可选)':'Код приглашения (необязательно)','至少8位':'не менее 8 символов',
    '（至少8位）':'(не менее 8 символов)','(至少8位)':'(не менее 8 символов)','注册即代表同意':'Регистрируясь, вы соглашаетесь с',
    '重置密码':'Сбросить пароль','重置':'Сбросить','Email验证':'Подтверждение email','获取验证码':'Получить код',
    '请输入验证码':'Введите код подтверждения','请输入Email':'Введите email','请输入Email地址':'Введите адрес email'
  });
  // Node-list labels are emitted by the pre-built theme and are not part of
  // its normal translation keys. Keep them here so no Chinese header leaks
  // through when the user selects another supported language.
  Object.assign(en, {
    '节点名称': 'Node name', '地址': 'Address', '倍率': 'Rate', '标签': 'Tags',
    '解锁': 'Unlock', '延迟RTT': 'RTT latency', 'HTTP延迟': 'HTTP latency',
    '创建新账户': 'Create a new account', 'Email地址': 'Email address',
    '还没有账号？': 'No account yet?', '已经有账户？': 'Already have an account?',
    '邀请码': 'Invitation code', '邀请码（可选）': 'Invitation code (optional)',
    '邀请码 (可选)': 'Invitation code (optional)', '至少8位': 'at least 8 characters',
    '（至少8位）': '(at least 8 characters)', '(至少8位)': '(at least 8 characters)',
    '注册即代表同意': 'By registering, you agree to', '同意': 'agree',
    '邮箱地址': 'Email address', '重置密码': 'Reset password', '重置': 'Reset',
    'Email验证': 'Email verification', '获取验证码': 'Get verification code',
    '请输入验证码': 'Enter verification code', '请输入Email': 'Enter email',
    '请输入Email地址': 'Enter email address', '流量使用趋势（最近30天）': 'Traffic usage trend (last 30 days)',
    '流量使用趋势(最近30天)': 'Traffic usage trend (last 30 days)', '流量 (GB)': 'Traffic (GB)',
    '流量（GB）': 'Traffic (GB)', '上传流量': 'Upload traffic', '下载流量': 'Download traffic'
  });
  Object.assign(vi, {
    '节点名称': 'Tên node', '地址': 'Địa chỉ', '倍率': 'Tỷ lệ', '标签': 'Nhãn',
    '解锁': 'Mở khóa', '延迟RTT': 'Độ trễ RTT', 'HTTP延迟': 'Độ trễ HTTP',
    '创建新账户': 'Tạo tài khoản mới', 'Email地址': 'Địa chỉ email',
    '还没有账号？': 'Chưa có tài khoản?', '已经有账户？': 'Đã có tài khoản?',
    '邀请码': 'Mã mời', '邀请码（可选）': 'Mã mời (tùy chọn)',
    '邀请码 (可选)': 'Mã mời (tùy chọn)', '至少8位': 'ít nhất 8 ký tự',
    '（至少8位）': '(ít nhất 8 ký tự)', '(至少8位)': '(ít nhất 8 ký tự)',
    '注册即代表同意': 'Đăng ký đồng nghĩa với việc bạn đồng ý với', '同意': 'đồng ý',
    '邮箱地址': 'Địa chỉ email', '重置密码': 'Đặt lại mật khẩu', '重置': 'Đặt lại',
    'Email验证': 'Xác minh email', '获取验证码': 'Lấy mã xác minh',
    '请输入验证码': 'Nhập mã xác minh', '请输入Email': 'Nhập email',
    '请输入Email地址': 'Nhập địa chỉ email', '流量使用趋势（最近30天）': 'Xu hướng sử dụng lưu lượng (30 ngày gần đây)',
    '流量使用趋势(最近30天)': 'Xu hướng sử dụng lưu lượng (30 ngày gần đây)', '流量 (GB)': 'Lưu lượng (GB)',
    '流量（GB）': 'Lưu lượng (GB)', '上传流量': 'Lưu lượng tải lên', '下载流量': 'Lưu lượng tải xuống'
  });
  Object.assign(tw, {
    '节点名称': '節點名稱', '地址': '地址', '倍率': '倍率', '标签': '標籤',
    '解锁': '解鎖', '延迟RTT': '延遲 RTT', 'HTTP延迟': 'HTTP 延遲'
  });
  Object.assign(ja, {
    '节点名称': 'ノード名', '地址': 'アドレス', '倍率': '倍率', '标签': 'タグ',
    '解锁': 'アンロック', '延迟RTT': 'RTT遅延', 'HTTP延迟': 'HTTP遅延'
  });
  Object.assign(ko, {
    '节点名称': '노드 이름', '地址': '주소', '倍率': '배율', '标签': '태그',
    '解锁': '잠금 해제', '延迟RTT': 'RTT 지연', 'HTTP延迟': 'HTTP 지연'
  });
  Object.assign(fa, {
    '节点名称': 'نام گره', '地址': 'آدرس', '倍率': 'ضریب', '标签': 'برچسب‌ها',
    '解锁': 'باز است', '延迟RTT': 'تأخیر RTT', 'HTTP延迟': 'تأخیر HTTP'
  });
  Object.assign(ru, {
    '节点名称': 'Имя узла', '地址': 'Адрес', '倍率': 'Множитель', '标签': 'Метки',
    '解锁': 'Доступ', '延迟RTT': 'Задержка RTT', 'HTTP延迟': 'Задержка HTTP'
  });
  var dictionaries = { 'en-US': en, 'vi-VN': vi, 'ja-JP': ja, 'ko-KR': ko, 'fa-IR': fa, 'zh-TW': tw, 'ru-RU': ru, 'zh-CN': {} };
  // A partially translated locale must never fall back to Chinese.  English is
  // the complete safety net; the locale's own strings take precedence.
  var dictionary = locale === 'zh-CN' ? {} : Object.assign({}, en, dictionaries[locale] || {});

  function translateText(text) {
    var leading = text.match(/^\s*/)[0];
    var trailing = text.match(/\s*$/)[0];
    var core = text.slice(leading.length, text.length - trailing.length);
    var originalCore = core;
    // Dynamic counters are emitted as e.g. "5207天" and therefore need a
    // unit replacement in addition to the exact dictionary lookup.
    if (locale !== 'zh-CN') {
      if (locale === 'vi-VN') core = core.replace(/最近(\d+)天流量总计\s*[:：]?/g, 'Tổng lưu lượng $1 ngày gần đây: ');
      else if (locale === 'en-US') core = core.replace(/最近(\d+)天流量总计\s*[:：]?/g, 'Total traffic for the last $1 days: ');
      if (locale === 'vi-VN') core = core.replace(/每月(\d+)日重置/g, 'Đặt lại vào ngày $1 hàng tháng');
      var dayLabel = locale === 'vi-VN' ? 'ngày' : (locale === 'ja-JP' ? '日' : (locale === 'ko-KR' ? '일' : (locale === 'fa-IR' ? 'روز' : 'day')));
      var daysAgoLabel = locale === 'vi-VN' ? 'ngày trước' : (locale === 'ja-JP' ? '日前' : (locale === 'ko-KR' ? '일 전' : (locale === 'fa-IR' ? 'روز قبل' : 'days ago')));
      core = core.replace(/天前/g, daysAgoLabel).replace(/天/g, dayLabel);
    }
    var translatedCore = Object.prototype.hasOwnProperty.call(dictionary, core) ? dictionary[core] : core;
    if (translatedCore === core && locale !== 'zh-CN') {
      // Pages also contain counters such as “今日流量0 GB”. Replace known
      // Chinese fragments inside those dynamic strings, longest first.
      Object.keys(dictionary).filter(function (key) {
        return key.length >= 2 && /[\u3400-\u9fff]/.test(key) && core.indexOf(key) !== -1;
      }).sort(function (a, b) { return b.length - a.length; }).forEach(function (key) {
        translatedCore = translatedCore.split(key).join(dictionary[key]);
      });
    }
    if (locale === 'vi-VN') {
      translatedCore = translatedCore
        .replace(/ChungLiên kết đăng ký/g, 'Liên kết đăng ký chung')
        .replace(/Dùng ứng dụng để quétMã QRNhập nhanh/g, 'Dùng ứng dụng để quét mã QR để nhập nhanh')
        .replace(/Dùng ứng dụng để quétMã QR/g, 'Dùng ứng dụng để quét mã QR')
        .replace(/Mã QRNhập nhanh/g, 'Mã QR để nhập nhanh');
    }
    return translatedCore !== originalCore ? leading + translatedCore + trailing : text;
  }
  function translate(root) {
    var walker = document.createTreeWalker(root || document.body, NodeFilter.SHOW_TEXT);
    var node;
    while ((node = walker.nextNode())) {
      if (node.parentElement && /^(SCRIPT|STYLE|NOSCRIPT|TEXTAREA)$/.test(node.parentElement.tagName)) continue;
      var next = translateText(node.nodeValue);
      if (next !== node.nodeValue) node.nodeValue = next;
    }
    (root || document).querySelectorAll('[placeholder],[title],[aria-label],[alt]').forEach(function (element) {
      ['placeholder','title','aria-label','alt'].forEach(function (attribute) {
        if (element.hasAttribute(attribute)) element.setAttribute(attribute, translateText(element.getAttribute(attribute)));
      });
    });
    if (locale === 'vi-VN') {
      function normalizeLeaf(element) {
        if (!element || element.nodeType !== 1 || element.childElementCount !== 0) return;
        var text = element.textContent || '';
        var normalized = text
          .replace(/ChungLiên kết đăng ký/g, 'Liên kết đăng ký chung')
          .replace(/Dùng ứng dụng để quétMã QRNhập nhanh/g, 'Dùng ứng dụng để quét mã QR để nhập nhanh')
          .replace(/Dùng ứng dụng để quétMã QR/g, 'Dùng ứng dụng để quét mã QR')
          .replace(/Mã QRNhập nhanh/g, 'Mã QR để nhập nhanh');
        if (normalized !== text) element.textContent = normalized;
      }
      normalizeLeaf(root);
      (root || document).querySelectorAll('h1,h2,h3,p,span,div').forEach(function (element) {
        normalizeLeaf(element);
      });
    }
  }
  function start() {
    translate(document.body);
    new MutationObserver(function (records) {
      records.forEach(function (record) {
        if (record.type === 'characterData' && record.target.parentElement) translate(record.target.parentElement);
        record.addedNodes.forEach(function (node) {
          if (node.nodeType === Node.TEXT_NODE && node.parentElement) translate(node.parentElement);
          if (node.nodeType === Node.ELEMENT_NODE) translate(node);
        });
      });
    }).observe(document.body, { childList: true, characterData: true, subtree: true });
  }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start, { once: true });
})();

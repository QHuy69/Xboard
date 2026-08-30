const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const sandbox = {
  window: {
    LUCK_SERVER_LANGUAGES: ['vi-VN'],
    LUCK_DEFAULT_LANGUAGE: 'vi-VN',
    localStorage: {
      values: {},
      getItem(key) { return this.values[key] || null; },
      setItem(key, value) { this.values[key] = String(value); }
    },
    location: { reloadCount: 0, reload() { this.reloadCount += 1; } }
  },
  navigator: { languages: ['vi-VN'], language: 'vi-VN' },
  document: {
    documentElement: { lang: '', classList: { remove() {} } },
    title: '',
    head: null,
    body: null,
    cookie: '',
    addEventListener() {}
  },
  MutationObserver: function MutationObserver() { this.observe = function observe() {}; },
  Intl,
  console,
  setTimeout,
  clearTimeout
};

const runtimeSource = fs.readFileSync('luck-i18n-v18.js', 'utf8');
vm.runInNewContext(runtimeSource, sandbox);
const translate = sandbox.window.__LUCK_T__;

const expected = {
  '简体中文': 'Tiếng Trung giản thể',
  '繁體中文': 'Tiếng Trung phồn thể',
  '日本語': 'Tiếng Nhật',
  '注册失败': 'Đăng ký thất bại',
  'Đăng kýthất bại': 'Đăng ký thất bại',
  '请检查输入信息': 'Vui lòng kiểm tra thông tin đã nhập',
  '请检查输入Thông tin': 'Vui lòng kiểm tra thông tin đã nhập',
  '免费': '0đ',
  '请输入Chủ đề ticket': 'Nhập chủ đề ticket',
  '订阅链接已重置，请及时更新客户端配置': 'Liên kết đăng ký đã được đặt lại. Vui lòng cập nhật cấu hình ứng dụng.',
  'Liên kết đăng ký已Đặt lại，请及时更新ứng dụng配置': 'Liên kết đăng ký đã được đặt lại. Vui lòng cập nhật cấu hình ứng dụng.',
  '正在Tải图表数据...': 'Đang tải dữ liệu biểu đồ...',
  '正在TảiLưu lượng数据表...': 'Đang tải bảng dữ liệu lưu lượng...',
  '自动续费已开启': 'Đã bật tự động gia hạn',
  'Tự động gia hạn已开启': 'Đã bật tự động gia hạn',
  '流量邮件提醒已开启': 'Đã bật nhắc lưu lượng qua email',
  'Nhắc lưu lượng qua email已开启': 'Đã bật nhắc lưu lượng qua email',
  '到期邮件提醒已开启': 'Đã bật nhắc hạn qua email',
  'Nhắc hạn qua email已开启': 'Đã bật nhắc hạn qua email',
  '续费': 'Gia hạn',
  '确认续费': 'Xác nhận gia hạn',
  'Xác nhận续费': 'Xác nhận gia hạn',
  '套餐对比': 'So sánh gói',
  'Gói对比': 'So sánh gói',
  '使用中': 'Đang sử dụng',
  '即将购买': 'Sắp mua',
  '即将Mua': 'Sắp mua',
  '切换到': 'Chuyển sang',
  '设备': 'Thiết bị',
  '最近30天': '30 ngày gần đây',
  '最近7天': '7 ngày gần đây',
  '流量使用趋势（最近30天）': 'Xu hướng sử dụng lưu lượng (30 ngày gần đây)',
  '流量使用趋势(最近30天)': 'Xu hướng sử dụng lưu lượng (30 ngày gần đây)',
  '流量使用趋势 (最近30天)': 'Xu hướng sử dụng lưu lượng (30 ngày gần đây)',
  '重置成功': 'Đặt lại thành công',
  '确认新密码': 'Xác nhận mật khẩu mới',
  '加载流量明细失败': 'Không tải được chi tiết lưu lượng',
  '密码错误': 'Mật khẩu không đúng',
  '服务器错误': 'Lỗi máy chủ',
  '实付金额': 'Số tiền đã thanh toán',
  '套餐价格': 'Giá gói',
  '加载套餐失败，请重试': 'Không tải được gói, vui lòng thử lại',
  '服务器网关错误，请稍后重试': 'Lỗi cổng máy chủ, vui lòng thử lại sau',
  '支付网关错误，请稍后重试': 'Lỗi cổng thanh toán, vui lòng thử lại sau',
  '订单已取消': 'Đơn hàng đã hủy',
  '取消支付': 'Hủy thanh toán',
  '支付二维码': 'Mã QR thanh toán',
  '使用客户端扫描二维码快速导入': 'Dùng ứng dụng để quét mã QR để nhập nhanh',
  '充值成功！': 'Nạp tiền thành công!',
  '支付成功！': 'Thanh toán thành công!',
  '取消购买': 'Hủy mua',
  '成功重置已用流量': 'Đặt lại lưu lượng đã dùng thành công',
  '重置已用流量': 'Đặt lại lưu lượng đã dùng',
  '刷新用户信息失败:': 'Không thể làm mới thông tin người dùng:',
  '邀请佣金': 'Hoa hồng giới thiệu',
  '邀请二维码': 'Mã QR giới thiệu',
  '服务购买': 'Mua dịch vụ',
  '邀请注册': 'Đăng ký qua lời mời',
  '加载文档失败': 'Không tải được tài liệu',
  '支付成功！订单已完成': 'Thanh toán thành công! Đơn hàng đã hoàn tất.',
  '刷新支付状态失败:': 'Không thể làm mới trạng thái thanh toán:',
  '刷新支付状态失败，请重试': 'Không thể làm mới trạng thái thanh toán, vui lòng thử lại',
  '账户充值': 'Nạp tiền vào tài khoản',
  '充值类型': 'Loại nạp tiền',
  '充值信息': 'Thông tin nạp tiền',
  '节点二维码': 'Mã QR node',
  '协议类型': 'Loại giao thức',
  '流量倍率': 'Hệ số lưu lượng',
  '密码确认错误': 'Mật khẩu xác nhận không đúng',
  '加载工单详情失败': 'Không tải được chi tiết ticket',
  '加载工单详情失败:': 'Không tải được chi tiết ticket:',
  '工单详情': 'Chi tiết ticket',
  '工单主题': 'Chủ đề ticket',
  '加载工单失败:': 'Không tải được ticket:'
  ,'二维码URL为空': 'URL mã QR đang trống'
  ,'二维码URL为空，请重试': 'URL mã QR đang trống, vui lòng thử lại'
  ,'二维码生成失败，请尝试点击下方按钮直接打开支付链接': 'Không tạo được mã QR, hãy thử nhấn nút bên dưới để mở liên kết thanh toán'
  ,'二维码生成失败，请点击下方按钮直接打开支付链接': 'Không tạo được mã QR, hãy nhấn nút bên dưới để mở liên kết thanh toán'
  ,'二维码生成失败，请稍后重试': 'Không tạo được mã QR, vui lòng thử lại sau'
  ,'加载邀请数据失败:': 'Không tải được dữ liệu giới thiệu:'
  ,'加载邀请记录失败:': 'Không tải được lịch sử giới thiệu:'
  ,'复制失败，请手动复制': 'Sao chép thất bại, vui lòng sao chép thủ công'
  ,'复制成功': 'Sao chép thành công'
  ,'如果二维码无法显示，请点击下方链接：': 'Nếu mã QR không hiển thị, hãy nhấn liên kết bên dưới:'
  ,'已禁用': 'Đã vô hiệu hóa'
  ,'已启用': 'Đã bật'
  ,'扫描二维码或保存图片分享给好友': 'Quét mã QR hoặc lưu ảnh để chia sẻ với bạn bè'
  ,'扫描二维码或长按保存图片分享给好友': 'Quét mã QR hoặc nhấn giữ để lưu ảnh và chia sẻ với bạn bè'
  ,'正在加载邀请数据...': 'Đang tải dữ liệu giới thiệu...'
  ,'没有可用的邀请码': 'Không có mã mời khả dụng'
  ,'生成二维码失败:': 'Tạo mã QR thất bại:'
  ,'生成邀请码失败，请稍后重试': 'Không tạo được mã mời, vui lòng thử lại sau'
  ,'访问量:': 'Lượt truy cập:'
  ,'访问量：': 'Lượt truy cập:'
  ,'请使用支付宝扫描上方二维码完成支付': 'Vui lòng dùng Alipay quét mã QR ở trên để hoàn tất thanh toán'
  ,'请使用支付宝扫描二维码完成支付': 'Vui lòng dùng Alipay quét mã QR để hoàn tất thanh toán'
  ,'请复制链接手动分享到微信': 'Vui lòng sao chép liên kết và chia sẻ thủ công qua WeChat'
  ,'请检查邀请码是否正确或联系邀请人': 'Vui lòng kiểm tra mã mời hoặc liên hệ người mời'
  ,'邀请功能已被禁用': 'Tính năng giới thiệu đã bị vô hiệu hóa'
  ,'邀请您注册使用我们的服务': 'Mời bạn đăng ký sử dụng dịch vụ của chúng tôi'
  ,'邀请码无效': 'Mã mời không hợp lệ'
  ,'邀请码生成成功': 'Tạo mã mời thành công'
  ,'邀请链接复制成功': 'Sao chép liên kết mời thành công'
  ,'邀请页面初始化失败:': 'Không thể khởi tạo trang giới thiệu:'
  ,'邮箱已复制到剪贴板': 'Đã sao chép email vào bộ nhớ tạm'
  ,'重试生成二维码失败': 'Thử tạo lại mã QR thất bại'
  ,'邀请链接二维码': 'Mã QR liên kết mời'
  ,'分享给好友，获得推广佣金': 'Chia sẻ với bạn bè để nhận hoa hồng giới thiệu'
  ,'下载二维码': 'Tải mã QR'
  ,'申请提现': 'Yêu cầu rút hoa hồng'
  ,'提交申请': 'Gửi yêu cầu'
  ,'提现方式': 'Phương thức nhận tiền'
  ,'请选择提现方式': 'Chọn phương thức nhận tiền'
  ,'提现账号': 'Tài khoản nhận tiền'
  ,'请输入提现账号': 'Nhập tài khoản nhận tiền'
  ,'请填写提现信息，我们将在1-3个工作日内处理您的申请': 'Vui lòng điền thông tin rút tiền. Yêu cầu sẽ được xử lý trong 1–3 ngày làm việc.'
  ,'请确保账号信息准确，提现后无法修改': 'Hãy kiểm tra chính xác thông tin tài khoản; không thể sửa sau khi gửi.'
  ,'确定要禁用此邀请码吗？': 'Bạn có chắc muốn vô hiệu hóa mã mời này?'
  ,'邀请码已禁用': 'Đã vô hiệu hóa mã mời'
  ,'禁用': 'Vô hiệu hóa'
  ,'邮箱或密码错误': 'Email hoặc mật khẩu không đúng'
  ,'邮箱或密码错误，请检查后重试': 'Email hoặc mật khẩu không đúng. Vui lòng thử lại.'
  ,'登录失败，请检查邮箱和密码': 'Đăng nhập thất bại. Hãy kiểm tra email và mật khẩu.'
  ,'You must use the invitation code to register': 'Bạn phải nhập mã mời để đăng ký'
  ,'必须使用邀请码才可以注册': 'Bạn phải nhập mã mời để đăng ký'
  ,'未知的支付类型，请重试': 'Không xác định được phương thức thanh toán, vui lòng thử lại'
  ,'登录成功，但暂时无法加载账户信息，请重试': 'Đăng nhập thành công nhưng chưa tải được thông tin tài khoản. Vui lòng thử lại.'
  ,'验证码为空': 'Chưa nhập mã xác minh'
  ,'请输入邮箱验证码': 'Nhập mã xác minh email'
  ,'请Xác nhận mật khẩu': 'Vui lòng xác nhận mật khẩu'
  ,'请Xác nhận密码': 'Vui lòng xác nhận mật khẩu'
  ,'新购': 'Mua mới'
  ,'新套餐': 'gói mới'
  ,'新Gói': 'gói mới'
  ,'Gói mới的Hạn mức lưu lượng将立即生效': 'Hạn mức lưu lượng của gói mới có hiệu lực ngay'
  ,'Giới hạn thiết bị和Giới hạn tốc độ将按Gói mới执行': 'Giới hạn thiết bị và tốc độ sẽ áp dụng theo gói mới'
  ,'建议在月初或Lưu lượng即将用完时MuaGói mới，以避免浪费。': 'Nên mua gói mới vào đầu tháng hoặc khi sắp hết lưu lượng để tránh lãng phí.'
  ,'Mua gói khác sẽ ảnh hưởng đếnđặt lại trạng thái lưu lượng hiện tại': 'Mua gói khác sẽ đặt lại trạng thái lưu lượng hiện tại'
  ,'加载中...': 'Đang tải...'
  ,'正在加载主页数据...': 'Đang tải dữ liệu trang chủ...'
  ,'加载套餐列表中...': 'Đang tải danh sách gói...'
  ,'正在加载套餐信息...': 'Đang tải thông tin gói...'
  ,'正在加载节点列表...': 'Đang tải danh sách node...'
  ,'正在加载世界地图...': 'Đang tải bản đồ thế giới...'
  ,'加载订单中...': 'Đang tải đơn hàng...'
  ,'工单内容加载中...': 'Đang tải nội dung ticket...'
  ,'正在加载文档...': 'Đang tải tài liệu...'
  ,'正在加载文档内容...': 'Đang tải nội dung tài liệu...'
  ,'正在加载图表数据...': 'Đang tải dữ liệu biểu đồ...'
  ,'正在加载流量数据表...': 'Đang tải bảng dữ liệu lưu lượng...'
  ,'流量数据加载中...': 'Đang tải dữ liệu lưu lượng...'
  ,'正在加载支付方式，请稍候...': 'Đang tải phương thức thanh toán, vui lòng đợi...'
  ,'正在处理支付...': 'Đang xử lý thanh toán...'
  ,'正在完成余额支付...': 'Đang hoàn tất thanh toán bằng số dư...'
  ,'正在检查支付状态...': 'Đang kiểm tra trạng thái thanh toán...'
  ,'正在激活免费订单...': 'Đang kích hoạt đơn hàng 0đ...'
  ,'正在获取支付方式...': 'Đang lấy phương thức thanh toán...'
  ,'正在跳转支付...': 'Đang chuyển đến trang thanh toán...'
  ,'注册中...': 'Đang đăng ký...'
  ,'重置中...': 'Đang đặt lại...'
  ,'充值中...': 'Đang nạp tiền...'
  ,'Nhập email验证码': 'Nhập mã xác minh email'
};

for (const [source, vietnamese] of Object.entries(expected)) {
  const actual = translate(source);
  assert.strictEqual(actual, vietnamese, `${source} translated incorrectly`);
  assert(!/[\u3400-\u9fff]/.test(actual), `${source} leaked CJK text`);
}

for (const [source, fallback] of Object.entries({
  '正在读取未来版本数据...': 'Đang xử lý dữ liệu...',
  '请输入未来版本字段': 'Vui lòng kiểm tra thông tin bắt buộc',
  '未来版本操作失败': 'Thao tác thất bại, vui lòng thử lại',
  '未来版本通知': 'Thông báo hệ thống'
})) {
  assert.strictEqual(translate(source), fallback, `${source} did not use the CJK safety fallback`);
  assert(!/[\u3400-\u9fff]/.test(translate(source)), `${source} leaked CJK through the safety fallback`);
}

const joinedVietnamese = /(?:Xác nhận|Số dư|Nạp tiền|Đơn hàng|Gói|Thanh toán|Thông tin|Mật khẩu|Đăng ký|Mã QR|Dịch vụ|Tốc độ|Mua|Hủy|Đang|Máy chủ|Trạng thái|Gia hạn|Đã thanh toán|Hỗ trợ|Tải|Chi tiết|Làm mới|Đặt lại)(?=[A-ZÀ-Ỹ])/;
for (const source of Object.keys(expected)) {
  assert(!joinedVietnamese.test(translate(source)), `${source} produced joined Vietnamese words`);
}

const vietnameseBlocks = [];
for (const pattern of [
  /var vi\s*=\s*\{([\s\S]*?)\n\s*\};/g,
  /var extraVi\s*=\s*\{([\s\S]*?)\n\s*\};/g,
  /Object\.assign\(vi,\s*\{([\s\S]*?)\n\s*\}\);/g
]) {
  for (const match of runtimeSource.matchAll(pattern)) vietnameseBlocks.push(match[1]);
}

const vietnameseKeys = new Set();
for (const block of vietnameseBlocks) {
  for (const match of block.matchAll(/'((?:\\.|[^'])*)'\s*:/g)) {
    vietnameseKeys.add(match[1].replace(/\\\\/g, '\\'));
  }
}

for (const source of vietnameseKeys) {
  const actual = translate(source);
  assert(!/[\u3400-\u9fff]/.test(actual), `${source} leaked CJK text from the Vietnamese dictionary`);
  assert(!joinedVietnamese.test(actual), `${source} produced joined words from the Vietnamese dictionary`);
}

assert.strictEqual(sandbox.window.__LUCK_SET_LOCALE__('en-US'), true, 'manual locale selection was rejected');
assert.strictEqual(sandbox.window.localStorage.getItem('luck_locale'), 'en-US');
assert.strictEqual(sandbox.window.localStorage.getItem('luck_locale_manual'), '1');
assert.match(sandbox.document.cookie, /luck_locale_manual=1/);
assert.strictEqual(sandbox.window.location.reloadCount, 1);

// A customer can deliberately use English while located in Viet Nam. The
// Asia/Saigon heuristic is only for first-time automatic detection; after the
// picker marks a choice as manual, that choice must survive the reload.
const manualEnglishSandbox = {
  window: {
    LUCK_SERVER_LANGUAGES: ['vi-VN'],
    LUCK_DEFAULT_LANGUAGE: 'vi-VN',
    localStorage: {
      values: { luck_locale: 'en-US', luck_locale_manual: '1' },
      getItem(key) { return this.values[key] || null; },
      setItem(key, value) { this.values[key] = String(value); }
    },
    location: { reloadCount: 0, reload() { this.reloadCount += 1; } }
  },
  navigator: { languages: ['en-US'], language: 'en-US' },
  document: {
    documentElement: { lang: '', classList: { remove() {} } },
    title: '',
    head: null,
    body: null,
    cookie: 'luck_locale=en-US; luck_locale_manual=1',
    addEventListener() {}
  },
  MutationObserver: function MutationObserver() { this.observe = function observe() {}; },
  Intl: Object.assign({}, Intl, {
    DateTimeFormat: function DateTimeFormat() {
      return { resolvedOptions() { return { timeZone: 'Asia/Saigon' }; } };
    }
  }),
  console,
  setTimeout,
  clearTimeout
};
vm.runInNewContext(runtimeSource, manualEnglishSandbox);
assert.strictEqual(manualEnglishSandbox.document.documentElement.lang, 'en-US');
assert.strictEqual(manualEnglishSandbox.window.V2BOARD_CONFIG.LANGUAGE, 'en-US');
assert.strictEqual(manualEnglishSandbox.window.__LUCK_T__('登录'), 'Log in');

console.log(`Verified ${Object.keys(expected).length} source cases and ${vietnameseKeys.size} Vietnamese dictionary entries.`);

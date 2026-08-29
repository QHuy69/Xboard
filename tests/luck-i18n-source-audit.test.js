const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const sandbox = {
  window: {
    LUCK_SERVER_LANGUAGES: ['vi-VN'],
    LUCK_DEFAULT_LANGUAGE: 'vi-VN'
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
  '续费': 'Gia hạn',
  '确认续费': 'Xác nhận gia hạn',
  'Xác nhận续费': 'Xác nhận gia hạn',
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
};

for (const [source, vietnamese] of Object.entries(expected)) {
  const actual = translate(source);
  assert.strictEqual(actual, vietnamese, `${source} translated incorrectly`);
  assert(!/[\u3400-\u9fff]/.test(actual), `${source} leaked CJK text`);
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

console.log(`Verified ${Object.keys(expected).length} source cases and ${vietnameseKeys.size} Vietnamese dictionary entries.`);

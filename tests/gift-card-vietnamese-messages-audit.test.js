const assert = require('assert');
const fs = require('fs');

const files = [
  'app/Http/Requests/User/GiftCardRedeemRequest.php',
  'app/Http/Controllers/V1/User/GiftCardController.php',
  'app/Services/GiftCardService.php',
  'app/Models/GiftCardCode.php',
  'app/Models/GiftCardTemplate.php'
];

const userFacingChinese = [
  '请输入兑换码', '兑换码不存在', '礼品卡类型已停用', '兑换成功',
  '兑换失败', '记录不存在', '未使用', '已使用', '已过期', '已禁用',
  '通用礼品卡', '套餐礼品卡', '盲盒礼品卡', '未知状态', '未知类型'
];

const sources = files.map((file) => [file, fs.readFileSync(file, 'utf8')]);
for (const sourceText of userFacingChinese) {
  const owner = sources.find(([, source]) => source.includes(`'${sourceText}'`));
  assert(!owner, `${owner?.[0]} still exposes Chinese gift-card text: ${sourceText}`);
}

const combined = sources.map(([, source]) => source).join('\n');
const vietnamese = JSON.parse(fs.readFileSync('resources/lang/vi-VN.json', 'utf8'));
const expectedTranslations = {
  'Gift card code is required': 'Vui lòng nhập mã thẻ quà tặng',
  'Gift card code does not exist': 'Mã thẻ quà tặng không tồn tại',
  'Gift card redeemed successfully': 'Đổi thẻ quà tặng thành công!',
  'Plan gift card': 'Thẻ quà tặng theo gói',
  'Unknown gift card status': 'Trạng thái không xác định'
};
for (const [key, expected] of Object.entries(expectedTranslations)) {
  assert(combined.includes(`__('${key}')`), `Gift-card path does not follow the request locale: ${key}`);
  assert.strictEqual(vietnamese[key], expected, `Incorrect Vietnamese gift-card translation: ${key}`);
}

console.log('Gift-card validation, API errors, status and type labels follow the request locale with Vietnamese coverage.');

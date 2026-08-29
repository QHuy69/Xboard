const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const expectText = (file, needle) => {
  const source = read(file);
  if (!source.includes(needle)) {
    throw new Error(`${file} is missing: ${needle}`);
  }
};

for (const locale of ['zh-CN', 'zh-TW', 'en-US', 'vi-VN', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU']) {
  expectText('app/Models/MailTemplate.php', `'${locale}'`);
}

expectText('database/migrations/2026_08_29_000002_add_language_to_mail_templates.php', "unique(['name', 'language']");
expectText('app/Services/MailService.php', '"mail_template:{$templateName}:{$language}"');
expectText('app/Services/MailService.php', "->where('language', $language)");
expectText('app/Services/MailService.php', "User::byEmail($email)->value('locale')");
expectText('app/Http/Controllers/V2/Admin/MailTemplateController.php', "'languages' => MailTemplate::SUPPORTED_LANGUAGES");
expectText('app/Http/Controllers/V1/Passport/CommController.php', "request->header('content-language')");
expectText('database/migrations/2026_08_29_000003_enable_email_verification_and_set_admin_path.php', "'secure_path' => 'Huy2006'");
expectText('database/migrations/2026_08_29_000003_enable_email_verification_and_set_admin_path.php', "'email_verify' => '1'");

console.log('Verified localized mail templates, locale routing, email verification, and admin path settings.');

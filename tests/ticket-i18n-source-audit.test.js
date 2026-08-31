const assert = require('assert');
const fs = require('fs');

const source = fs.readFileSync('app/Services/TicketService.php', 'utf8');

for (const errorKey of [
  'Ticket does not exist or has been closed',
  'Ticket reply failed',
  'There are other unresolved tickets',
  'Failed to open ticket'
]) {
  assert(source.includes(`__('${errorKey}')`), `Ticket error does not follow the request locale: ${errorKey}`);
}

for (const locale of ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU']) {
  const catalog = JSON.parse(fs.readFileSync(`resources/lang/${locale}.json`, 'utf8'));
  assert(
    typeof catalog['Ticket does not exist or has been closed'] === 'string'
      && catalog['Ticket does not exist or has been closed'].trim(),
    `${locale} is missing the closed-ticket reply error.`
  );
}

for (const localeBranch of ['en', 'zh-TW', 'zh-CN', 'ja', 'ko', 'fa', 'ru', 'vi']) {
  assert(source.includes(`'${localeBranch}'`), `Ticket email copy is missing locale: ${localeBranch}`);
}

assert(source.includes("'language' => $locale"), 'Ticket email does not pass the user locale to the template');
assert(source.includes("'subject' => $copy['subject']"), 'Ticket email subject is still hardcoded');
assert(source.includes("'content' => $copy['content']"), 'Ticket email body is still hardcoded');
assert(source.includes("'content_mode' => 'text'"), 'Ticket email content is not escaped as text');

console.log('Ticket API messages and reply emails follow the user language without Chinese-only copy.');

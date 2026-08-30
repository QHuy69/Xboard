const fs = require('fs');
const path = require('path');

const target = path.resolve(__dirname, '../public/assets/admin/assets/index-CEIYH7i8.js');
let source = fs.readFileSync(target, 'utf8');

const replacements = [
  [
    'plan_id:dy().nullable().default(null),banned:uy().default(!1)',
    'plan_id:dy().nullable().default(null),locale:cy().nullable().default("vi-VN"),banned:uy().default(!1)'
  ],
  [
    'Q.jsx($y,{control:u.control,name:"banned",render:({field:t})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:e("edit.form.account_status")})',
    'Q.jsx($y,{control:u.control,name:"locale",render:({field:t})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:e("edit.form.language")}),Q.jsx(Yy,{children:Q.jsxs(yzt,{value:t.value||"vi-VN",onValueChange:t.onChange,children:[Q.jsx(Czt,{children:Q.jsx(wzt,{})}),Q.jsxs(Nzt,{children:[Q.jsx(Lzt,{value:"vi-VN",children:"Tiếng Việt"}),Q.jsx(Lzt,{value:"en-US",children:"English"}),Q.jsx(Lzt,{value:"zh-CN",children:"简体中文"}),Q.jsx(Lzt,{value:"zh-TW",children:"繁體中文"}),Q.jsx(Lzt,{value:"ja-JP",children:"日本語"}),Q.jsx(Lzt,{value:"ko-KR",children:"한국어"}),Q.jsx(Lzt,{value:"fa-IR",children:"فارسی"}),Q.jsx(Lzt,{value:"ru-RU",children:"Русский"})]})]})})]})}),Q.jsx($y,{control:u.control,name:"banned",render:({field:t})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:e("edit.form.account_status")})'
  ],
  [
    'CZt=py({frontend_theme:cy().nullable(),frontend_theme_sidebar:cy().nullable(),frontend_theme_header:cy().nullable(),frontend_theme_color:cy().nullable(),frontend_background_url:cy().url().nullable()})',
    'CZt=py({frontend_theme:cy().nullable(),frontend_theme_sidebar:cy().nullable(),frontend_theme_header:cy().nullable(),frontend_theme_color:cy().nullable(),frontend_background_url:cy().url().nullable(),crisp_website_id:cy().nullable(),messenger_page_username:cy().nullable()})'
  ],
  [
    'SZt={frontend_theme:"",frontend_theme_sidebar:"",frontend_theme_header:"",frontend_theme_color:"",frontend_background_url:""}',
    'SZt={frontend_theme:"",frontend_theme_sidebar:"",frontend_theme_header:"",frontend_theme_color:"",frontend_background_url:"",crisp_website_id:"",messenger_page_username:""}'
  ],
  [
    'Q.jsx(Xy,{children:"This will be displayed on the admin login page."}),Q.jsx(Qy,{})]})}),Q.jsx(Lf,{type:"submit",children:"Save Settings"})',
    'Q.jsx(Xy,{children:"This will be displayed on the admin login page."}),Q.jsx(Qy,{})]})}),Q.jsx($y,{control:t.control,name:"crisp_website_id",render:({field:e})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:"Crisp Website ID"}),Q.jsx(Yy,{children:Q.jsx(u8e,{placeholder:"UUID trang web Crisp",...e})}),Q.jsx(Xy,{children:"Để trống nếu không dùng Crisp."}),Q.jsx(Qy,{})]})}),Q.jsx($y,{control:t.control,name:"messenger_page_username",render:({field:e})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:"Tên người dùng Messenger"}),Q.jsx(Yy,{children:Q.jsx(u8e,{placeholder:"Ví dụ: zaoguang.support",...e})}),Q.jsx(Xy,{children:"Tên sau m.me/, không nhập toàn bộ URL."}),Q.jsx(Qy,{})]})}),Q.jsx(Lf,{type:"submit",children:"Save Settings"})'
  ],
  [
    'case"string":return Q.jsx(TYt,{control:e.control,name:s,label:i.label||i.description,placeholder:i.placeholder,description:i.label?i.description:void 0,required:i.required},t);case"number"',
    'case"string":case"password":return Q.jsx(TYt,{control:e.control,name:s,label:i.label||i.description,placeholder:i.placeholder,description:i.label?i.description:void 0,required:i.required,type:"password"===i.type?"password":"text"},t);case"number"'
  ]
];

for (const [needle, replacement] of replacements) {
  if (source.includes(replacement)) continue;
  if (!source.includes(needle)) {
    throw new Error(`Admin locale patch anchor not found: ${needle.slice(0, 80)}`);
  }
  source = source.replace(needle, replacement);
}

fs.writeFileSync(target, source);
console.log('Admin user language, support settings and secret plugin fields patched.');

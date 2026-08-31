const fs = require('fs');
const path = require('path');

const target = path.resolve(__dirname, '../public/assets/admin/assets/index-CEIYH7i8.js');

const canonicalSupportFields = 'Q.jsx(Xy,{children:"This will be displayed on the admin login page."}),Q.jsx(Qy,{})]})}),Q.jsx($y,{control:t.control,name:"crisp_website_id",render:({field:e})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:"Crisp Website ID"}),Q.jsx(Yy,{children:Q.jsx(u8e,{placeholder:"xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",...e})}),Q.jsx(Xy,{children:"UUID Website ID from Crisp settings. Leave blank to keep Crisp Chat disabled."}),Q.jsx(Qy,{})]})}),Q.jsx($y,{control:t.control,name:"messenger_page_username",render:({field:e})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:"Facebook Page username"}),Q.jsx(Yy,{children:Q.jsx(u8e,{placeholder:"facebook.page.name",...e})}),Q.jsx(Xy,{children:"Enter the Page username from the end of its m.me link, not the full URL."}),Q.jsx(Qy,{})]})}),Q.jsx(Lf,{type:"submit",children:"Save Settings"})';

const replacements = [
  [
    'plan_id:dy().nullable().default(null),banned:uy().default(!1)',
    'plan_id:dy().nullable().default(null),locale:cy().nullable().default("vi-VN"),banned:uy().default(!1)'
  ],
  [
    'is_admin:uy().default(!1),is_staff:uy().default(!1),remarks:cy().nullable().default(null)',
    'is_admin:uy().default(!1),is_staff:uy().default(!1),is_reseller:uy().default(!1),remarks:cy().nullable().default(null)'
  ],
  [
    'Q.jsx($y,{control:u.control,name:"banned",render:({field:t})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:e("edit.form.account_status")})',
    'Q.jsx($y,{control:u.control,name:"locale",render:({field:t})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:e("edit.form.language")}),Q.jsx(Yy,{children:Q.jsxs(yzt,{value:t.value||"vi-VN",onValueChange:t.onChange,children:[Q.jsx(Czt,{children:Q.jsx(wzt,{})}),Q.jsxs(Nzt,{children:[Q.jsx(Lzt,{value:"vi-VN",children:"Tiếng Việt"}),Q.jsx(Lzt,{value:"en-US",children:"English"}),Q.jsx(Lzt,{value:"zh-CN",children:"简体中文"}),Q.jsx(Lzt,{value:"zh-TW",children:"繁體中文"}),Q.jsx(Lzt,{value:"ja-JP",children:"日本語"}),Q.jsx(Lzt,{value:"ko-KR",children:"한국어"}),Q.jsx(Lzt,{value:"fa-IR",children:"فارسی"}),Q.jsx(Lzt,{value:"ru-RU",children:"Русский"})]})]})})]})}),Q.jsx($y,{control:u.control,name:"banned",render:({field:t})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:e("edit.form.account_status")})'
  ],
  [
    'Q.jsx($y,{control:u.control,name:"is_staff",render:({field:t})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:e("edit.form.is_staff")}),Q.jsx("div",{className:"py-2",children:Q.jsx(Yy,{children:Q.jsx(oZt,{checked:t.value,onCheckedChange:e=>t.onChange(e)})})})]})}),Q.jsx($y,{control:u.control,name:"remarks"',
    'Q.jsx($y,{control:u.control,name:"is_staff",render:({field:t})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:e("edit.form.is_staff")}),Q.jsx("div",{className:"py-2",children:Q.jsx(Yy,{children:Q.jsx(oZt,{checked:t.value,onCheckedChange:e=>t.onChange(e)})})})]})}),Q.jsx($y,{control:u.control,name:"is_reseller",render:({field:t})=>Q.jsxs(Gy,{children:[Q.jsx(Zy,{children:e("edit.form.is_reseller")}),Q.jsx("div",{className:"py-2",children:Q.jsx(Yy,{children:Q.jsx(oZt,{checked:t.value,onCheckedChange:e=>t.onChange(e)})})})]})}),Q.jsx($y,{control:u.control,name:"remarks"'
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
    canonicalSupportFields
  ],
  [
    'case"string":return Q.jsx(TYt,{control:e.control,name:s,label:i.label||i.description,placeholder:i.placeholder,description:i.label?i.description:void 0,required:i.required},t);case"number"',
    'case"string":case"password":return Q.jsx(TYt,{control:e.control,name:s,label:i.label||i.description,placeholder:i.placeholder,description:i.label?i.description:void 0,required:i.required,type:"password"===i.type?"password":"text"},t);case"number"'
  ],
  [
    'onClick:()=>s(e.code),disabled:!e.is_enabled||a,className:"h-7 px-2 text-xs"',
    'onClick:()=>s(e.code),disabled:a,className:"h-7 px-2 text-xs"'
  ],
  [
    'className:"flex items-center justify-end gap-2",children:e.is_installed?',
    'className:"flex flex-wrap items-center justify-end gap-2",children:e.is_installed?'
  ],
  [
    'const ptt=H.forwardRef(({className:e,drawerClassName:t,children:n,...i},r)=>utt()?Q.jsx(stt,{className:Im("flex max-h-[90vh] flex-col",t),children:n}):Q.jsx(pet,{ref:r,className:e,...i,children:n}));',
    'const ptt=H.forwardRef(({className:e,drawerClassName:t,drawerStyle:o,children:n,...i},r)=>utt()?Q.jsx(stt,{ref:r,className:Im("flex max-h-[90vh] flex-col",t),style:o,children:n}):Q.jsx(pet,{ref:r,className:e,...i,children:n}));'
  ],
  [
    [
      'Q.jsxs(ptt,{className:"w-[calc(100vw-2rem)] max-h-[90vh] overflow-hidden sm:max-w-2xl",children:[Q.jsxs(mtt,{children:',
      'Q.jsxs(ptt,{className:"w-[calc(100vw-2rem)] max-h-[90vh] overflow-hidden sm:max-w-2xl",drawerClassName:"max-h-[90vh] overflow-hidden",style:{width:"calc(100vw - 2rem)",maxWidth:"42rem",maxHeight:"calc(100dvh - 2rem)",display:"flex",flexDirection:"column",overflow:"hidden",padding:"clamp(1rem, 2.5vw, 1.5rem)"},children:[Q.jsxs(mtt,{style:{flex:"0 0 auto"},children:'
    ],
    'Q.jsxs(ptt,{className:"w-[calc(100vw-2rem)] max-h-[90vh] overflow-hidden sm:max-w-2xl",drawerClassName:"max-h-[90vh] overflow-hidden",drawerStyle:{width:"100%",maxWidth:"none",maxHeight:"calc(100dvh - 0.5rem)",display:"flex",flexDirection:"column",overflow:"hidden",paddingTop:"1rem",paddingRight:"max(1rem, env(safe-area-inset-right))",paddingBottom:"max(1rem, env(safe-area-inset-bottom))",paddingLeft:"max(1rem, env(safe-area-inset-left))"},style:{width:"calc(100vw - 2rem)",maxWidth:"42rem",maxHeight:"calc(100dvh - 2rem)",display:"flex",flexDirection:"column",overflow:"hidden",padding:"clamp(1rem, 2.5vw, 1.5rem)"},children:[Q.jsxs(mtt,{style:{flex:"0 0 auto"},children:'
  ],
  [
    'className:"flex h-full min-w-0 flex-col overflow-hidden",children:[Q.jsx(Gqt,{className:"max-h-[65vh] min-w-0 flex-1 overflow-auto"',
    'className:"flex h-full min-w-0 flex-col overflow-hidden",style:{minHeight:0,flex:"1 1 auto"},children:[Q.jsx("div",{className:"min-w-0 flex-1 overflow-auto",style:{minHeight:0,maxHeight:"calc(100dvh - 14rem)",overflowY:"auto",overflowX:"hidden",overscrollBehavior:"contain",paddingRight:"0.25rem"}'
  ],
  [
    'className:"mt-6 flex flex-wrap items-center justify-end gap-3 border-t border-border/40 pt-6",children:',
    'className:"mt-6 flex flex-wrap items-center justify-end gap-3 border-t border-border/40 pt-6",style:{position:"sticky",bottom:0,zIndex:1,flex:"0 0 auto",background:"hsl(var(--background))"},children:'
  ],
  [
    'Q.jsxs(Gy,{className:"flex flex-row items-center justify-between space-y-0 rounded-lg border p-4",children:[Q.jsxs("div",{className:"space-y-0.5",children:',
    'Q.jsxs(Gy,{className:"flex flex-row items-center justify-between space-y-0 rounded-lg border p-4",style:{minWidth:0,gap:"0.75rem"},children:[Q.jsxs("div",{className:"space-y-0.5",style:{minWidth:0,flex:"1 1 auto",overflowWrap:"anywhere"},children:'
  ],
  [
    'return Q.jsx("div",{className:i,children:Object.entries(t).map(([e,t])=>o(e,t))})}function RYt',
    'return Q.jsx("div",{className:i,style:{minWidth:0,overflowWrap:"anywhere"},children:Object.entries(t).map(([e,t])=>o(e,t))})}function RYt'
  ],
  [
    'Q.jsxs(_tt,{children:[E?.find(e=>e.code===o)?.name," ",e("config.title")]}),',
    'Q.jsxs(_tt,{children:[e("config.title")," ",E?.find(e=>e.code===o)?.name]}),'
  ]
];

function patchAdminBundle(input) {
  let source = input;

  for (const [needleOrNeedles, replacement] of replacements) {
    if (source.includes(replacement)) continue;
    const needles = Array.isArray(needleOrNeedles) ? needleOrNeedles : [needleOrNeedles];
    const needle = needles.find((candidate) => source.includes(candidate));
    if (!needle) {
      throw new Error(`Admin locale patch anchor not found: ${needles[0].slice(0, 80)}`);
    }
    source = source.replace(needle, replacement);
  }

  return source;
}

if (require.main === module) {
  const source = patchAdminBundle(fs.readFileSync(target, 'utf8'));
  fs.writeFileSync(target, source);
  console.log('Admin language, reseller role, support settings, secret fields and disabled-plugin configuration patched.');
}

module.exports = { patchAdminBundle };

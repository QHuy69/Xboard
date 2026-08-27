# Xboard custom-en: vận hành và cập nhật

## Bản nào nên dùng?

- Repo Xboard của bạn: QHuy69/Xboard, branch **custom-en**.
- Gói giao diện đã dịch Anh + Việt: QHuy69/xboard-admin-dist, branch **custom-en**.
- master/main chỉ giữ luồng đồng bộ. Không triển khai panel từ các branch đó nếu cần bản dịch.

Branch custom-en luôn lấy mã lõi mới từ Xboard chính chủ và gắn đúng revision giao diện đã dịch. Vì thế cập nhật chính chủ không tự ghi đè phần dịch.

## Đồng bộ tự động hoạt động thế nào?

Hai GitHub Actions chạy mỗi ngày theo thứ tự:

1. **Sync official admin assets** trong repo xboard-admin-dist, lúc 09:17 giờ Việt Nam. Nó merge giao diện chính chủ, chạy lại bộ dịch Anh và Việt, rồi kiểm tra:
   - mọi key của zh-CN đều có trong en-US;
   - không còn chuỗi UI tiếng Trung chưa có bản dịch.
   - locale Việt có đủ toàn bộ key của en-US và không chứa ký tự Trung;
   - bundle vẫn có tài nguyên `vi-VN` và mục chọn 🇻🇳 VI.
2. **Sync customized Xboard with upstream** trong repo Xboard, lúc 10:47 giờ Việt Nam. Nó merge lõi Xboard chính chủ và trỏ submodule sang revision giao diện custom-en vừa được kiểm tra.

Sau khi workflow Xboard thành công, workflow **Docker Build and Publish** sẽ build image của branch custom-en.

Bạn có thể chạy ngay thay vì chờ lịch:

1. Vào tab **Actions** của QHuy69/xboard-admin-dist, chọn **Sync official admin assets**, bấm **Run workflow**.
2. Khi xong, vào tab **Actions** của QHuy69/Xboard, chọn **Sync customized Xboard with upstream**, bấm **Run workflow**.
3. Chỉ triển khai khi cả hai workflow đều xanh.

Không cần token hay secret riêng cho hai workflow này; chúng chỉ ghi vào chính repo của bạn.

## Nếu workflow báo lỗi

Đó là chốt an toàn, chưa có thay đổi nào được đẩy sang custom-en.

- **Merge conflict:** Xboard chính chủ đã thay đổi cùng phần bạn custom. Mở log, resolve conflict trên branch custom-en, rồi chạy lại workflow.
- **Untranslated Chinese UI string(s):** bản chính chủ thêm chữ Trung mới. Bổ sung cặp Trung → Anh vào public/assets/admin/scripts/apply_admin_en_customizations.py, chạy script đó, commit và push custom-en, sau đó chạy lại workflow.
- **Missing English locale keys:** thêm key tương ứng vào LOCALE_ADDITIONS trong cùng script. Không nên xóa bước kiểm tra này.
- **Vietnamese locale keys do not match:** chạy `python3 scripts/generate_admin_vi_locale.py`; script chỉ dịch các chuỗi mới, dùng cache cho chuỗi cũ và kiểm tra đủ key trước khi ghi file.
- **Cannot locate i18n initialization/language selector:** bundle chính chủ đã đổi cấu trúc. Cập nhật mẫu ghép trong `scripts/apply_admin_vi_integration.py`, chạy lại và chỉ push sau khi kiểm tra trực tiếp `/admin?lang=vi-VN`.

## Triển khai image fork lên VPS

Panel hiện tại đang dùng mount admin-assets để giữ bản dịch đã sửa trực tiếp. Khi chuyển hẳn sang image fork, nên backup mount đó rồi bỏ mount để image custom-en là nguồn giao diện duy nhất.

Trên VPS Xboard (/opt/Xboard):

1. Chờ workflow Docker Build and Publish của branch custom-en chạy thành công.
2. Backup compose.yaml và thư mục admin-assets.
3. Trong compose.yaml, đổi image Xboard sang:

       image: ghcr.io/qhuy69/xboard:custom-en

4. Xóa dòng volume đã thêm tạm thời:

       - ./admin-assets:/www/public/assets/admin

5. Pull và khởi động lại service Xboard, sau đó mở `/admin`, đăng nhập và kiểm tra cả tiếng Anh lẫn tiếng Việt.

Panel sẽ ưu tiên tiếng Việt trong lần đầu nếu Chrome/hệ điều hành báo ngôn ngữ `vi`. Sau khi người dùng tự đổi ngôn ngữ trong admin, lựa chọn thủ công được giữ lại. Khi kiểm thử cưỡng bức bản Việt, dùng `/admin?lang=vi-VN`.

Nếu GHCR package đang để private, đổi package sang public hoặc đăng nhập registry trước khi pull. Nếu deploy có lỗi, khôi phục compose.yaml backup và bật lại volume admin-assets; dữ liệu panel không nằm trong image nên không bị mất.

## Triển khai bản dịch theme Luck

Theme Luck tải file từ `storage/theme/Luck`. Với Docker, chỉ chép file vào thư mục host đôi khi container vẫn giữ bản cũ; hãy chép vào container rồi xóa cache view:

       docker compose cp luck-dashboard.blade.php xboard:/www/storage/theme/Luck/dashboard.blade.php
       docker compose cp luck-i18n-v18.js xboard:/www/storage/theme/Luck/i18n-v18.js
       docker compose exec -T xboard php artisan view:clear
       docker compose exec -T xboard sh -lc 'cp /www/storage/theme/Luck/i18n-v18.js /www/public/theme/Luck/i18n-v18.js'

Tên `i18n-v18.js` được cố ý version hóa vì CDN có thể bỏ qua query-string cache bust. Khi chỉnh bản dịch tiếp theo, tăng số version trong template và lặp lại bước trên.

## Làm thủ công khi cần

Không chạy git pull trực tiếp trên custom-en rồi deploy ngay. Thay vào đó, giữ thứ tự:

1. Update/verify xboard-admin-dist:custom-en.
2. Update Xboard:custom-en để nhận commit submodule mới.
3. Chờ Docker image của custom-en build thành công.
4. Backup rồi mới deploy.

Mỗi thay đổi dịch giao diện nằm trong xboard-admin-dist; Xboard chính chỉ giữ con trỏ submodule. Cách tách này giúp việc nhận update chính chủ và giữ phần custom không giẫm lên nhau.

## Ghi chú node 8.216.43.70

Node đang chạy ba cổng: Hysteria2 UDP `45157`, VLESS TCP `13101` và AnyTLS TCP `48161`. Hysteria2/AnyTLS phải dùng chứng chỉ công khai khi client đặt `allow_insecure=false`; chứng chỉ tự ký sẽ làm client báo lỗi TLS và không có lưu lượng.

Bản triển khai hiện dùng tên TLS `nt1.zaoguang-vpn.com` (CNAME tới `ntt1.zaoguang-vpn.com`, A trỏ về `8.216.43.70`) và ACME HTTP-01 để tự gia hạn chứng chỉ. Hysteria2/AnyTLS dùng SNI này với `allow_insecure=false`; VLESS Reality dùng đích `www.gov.cn` (cổng thông tin Chính phủ Trung Quốc), uTLS `safari`. Sau khi đổi chứng chỉ hoặc Reality, hãy tải lại subscription để nhận SNI/đích mới.

Kiểm tra nhanh từ máy khách:

- TCP `13101` và `48161` phải kết nối được; Hysteria2 `45157` là UDP nên bài kiểm tra TCP sẽ báo thất bại dù UDP vẫn hoạt động.
- Dùng client hỗ trợ đúng giao thức rồi mở một URL HTTPS để kiểm tra Internet; `ping` ICMP không phải phép thử hợp lệ cho proxy Hysteria2/AnyTLS/VLESS.
- Trên node, log thành công sẽ có `started, 1 users`; lỗi `tls: bad certificate` hoặc `REALITY: processed invalid connection` cho biết subscription đang dùng SNI/key cũ và cần refresh.

# Xboard custom-en: vận hành và cập nhật

## Bản nào nên dùng?

- Repo Xboard của bạn: QHuy69/Xboard, branch **custom-en**.
- Gói giao diện đã dịch: QHuy69/xboard-admin-dist, branch **custom-en**.
- master/main chỉ giữ luồng đồng bộ. Không triển khai panel từ các branch đó nếu cần bản dịch.

Branch custom-en luôn lấy mã lõi mới từ Xboard chính chủ và gắn đúng revision giao diện tiếng Anh. Vì thế cập nhật chính chủ không tự ghi đè phần dịch.

## Đồng bộ tự động hoạt động thế nào?

Hai GitHub Actions chạy mỗi ngày theo thứ tự:

1. **Sync official admin assets** trong repo xboard-admin-dist, lúc 09:17 giờ Việt Nam. Nó merge giao diện chính chủ, chạy lại bộ dịch, rồi kiểm tra:
   - mọi key của zh-CN đều có trong en-US;
   - không còn chuỗi UI tiếng Trung chưa có bản dịch.
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

## Triển khai image fork lên VPS

Panel hiện tại đang dùng mount admin-assets để giữ bản dịch đã sửa trực tiếp. Khi chuyển hẳn sang image fork, nên backup mount đó rồi bỏ mount để image custom-en là nguồn giao diện duy nhất.

Trên VPS Xboard (/opt/Xboard):

1. Chờ workflow Docker Build and Publish của branch custom-en chạy thành công.
2. Backup compose.yaml và thư mục admin-assets.
3. Trong compose.yaml, đổi image Xboard sang:

       image: ghcr.io/qhuy69/xboard:custom-en

4. Xóa dòng volume đã thêm tạm thời:

       - ./admin-assets:/www/public/assets/admin

5. Pull và khởi động lại service Xboard, sau đó mở /admin, đăng nhập và kiểm tra giao diện tiếng Anh.

Nếu GHCR package đang để private, đổi package sang public hoặc đăng nhập registry trước khi pull. Nếu deploy có lỗi, khôi phục compose.yaml backup và bật lại volume admin-assets; dữ liệu panel không nằm trong image nên không bị mất.

## Làm thủ công khi cần

Không chạy git pull trực tiếp trên custom-en rồi deploy ngay. Thay vào đó, giữ thứ tự:

1. Update/verify xboard-admin-dist:custom-en.
2. Update Xboard:custom-en để nhận commit submodule mới.
3. Chờ Docker image của custom-en build thành công.
4. Backup rồi mới deploy.

Mỗi thay đổi dịch giao diện nằm trong xboard-admin-dist; Xboard chính chỉ giữ con trỏ submodule. Cách tách này giúp việc nhận update chính chủ và giữ phần custom không giẫm lên nhau.

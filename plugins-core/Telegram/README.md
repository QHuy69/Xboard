# Plugin Telegram cho XBoard

Phiên bản plugin: **2.3.0**.

Bot Telegram hỗ trợ 8 ngôn ngữ theo ngôn ngữ tài khoản: tiếng Việt, tiếng Anh, tiếng Trung giản thể, tiếng Trung phồn thể, tiếng Nhật, tiếng Hàn, tiếng Ba Tư và tiếng Nga. Khi chưa liên kết tài khoản, bot nhận diện ngôn ngữ Telegram và dùng phương án dự phòng an toàn nếu mã ngôn ngữ không được hỗ trợ. Toàn bộ luồng người dùng cuối dùng nút bấm; khách hàng và cộng tác viên không phải nhớ hoặc nhập lệnh.

## Chức năng người dùng

- Từ dashboard đã đăng nhập, người dùng bấm `Liên kết ngay` để mở đúng bot bằng deep link dùng một lần, có thời hạn ngắn; không phải sao chép mã hoặc liên kết đăng ký vào Telegram.
- Sau khi liên kết, bot hiển thị các nút để xem lưu lượng, lấy liên kết đăng ký và quản lý liên kết tài khoản.
- Xem lưu lượng còn lại và lấy liên kết đăng ký mới nhất.
- Nhận thông báo khi mua mới, gia hạn, đổi gói, đặt lại liên kết đăng ký hoặc đặt lại lưu lượng.
- Hủy liên kết có bước xác nhận và thu hồi mọi deep link còn hiệu lực.

Danh sách lệnh công khai của bot được để trống để giao diện luôn hướng người dùng vào menu nút bấm. Một số slash handler có thể vẫn tồn tại ẩn để tương thích ngược hoặc phục vụ vận hành nội bộ, nhưng không phải là một phần của luồng người dùng cuối.

## Chức năng quản trị và nhóm

- Thông báo ticket và thanh toán cho tài khoản quản trị đã liên kết Telegram.
- `/nodes`: xem trạng thái node và số người đang trực tuyến trên từng node.
- `/setreportgroup`: quản trị viên hoặc nhân viên dùng lệnh này trong nhóm để chọn nhóm nhận báo cáo định kỳ.
- Có thể đặt chat/nhóm đích và ngôn ngữ báo cáo ngay trong cấu hình plugin; `/setreportgroup` vẫn là phương án tương thích khi trường chat đích để trống.
- Chu kỳ báo cáo node mặc định là 15 phút và chỉ nhận ba lựa chọn an toàn: 5, 15 hoặc 60 phút. Tác vụ Laravel chạy độc lập với webhook, có khóa một máy chủ, chống chạy chồng và khóa theo từng khung thời gian.
- Số trực tuyến lấy từ cache telemetry người dùng theo node do node gửi lên gần đây. Khi node ngoại tuyến hoặc không có lần push mới, bot ghi rõ dữ liệu không khả dụng thay vì hiển thị số 0 giả; tên và loại node được escape, báo cáo dài được chia thành nhiều tin an toàn.
- Tự động tạo backup database mỗi ngày, nén và mã hóa AES-256-GCM trước khi gửi vào chat riêng của quản trị viên.
- `/setbackupchat`: đặt chat riêng hiện tại làm nơi nhận backup; `/backupdb`: chạy thử một bản backup ngay.

## Quy trình cộng tác viên

1. Bật chức năng cộng tác viên trong cấu hình plugin. Chỉ tài khoản XBoard đã liên kết Telegram và được đánh dấu rõ ràng bằng `is_reseller` mới được dùng; quyền quản trị viên hoặc nhân viên không tự động cấp quyền cộng tác viên.
2. Cộng tác viên nhấn nút `Cộng tác viên` trong chat riêng với bot.
3. Chọn tạo khách hàng ẩn danh, chọn gói cùng chu kỳ, sau đó nhập mã giảm giá.
4. Bot chỉ chấp nhận mã giảm giá phần trăm đúng 100%, còn hiệu lực và áp dụng được cho gói/chu kỳ đã chọn.
5. Bot tạo khách hàng ẩn danh, đơn hàng 0đ và kích hoạt gói trong một giao dịch; chỉ mã khách hàng cùng liên kết đăng ký được trả về.
6. Danh sách, thông tin, liên kết đăng ký, đặt lại bảo mật, gia hạn và đổi gói chỉ hoạt động với khách hàng có `invite_user_id` thuộc chính cộng tác viên. Tài khoản quản trị, nhân viên hoặc cộng tác viên khác luôn bị loại khỏi phạm vi quản lý.

Mỗi nút chọn gói, chu kỳ, đặt lại bảo mật và hủy liên kết đều có mã xác nhận ngắn hạn. Callback cũ hoặc tự tạo không thể thực hiện thao tác và webhook Telegram gửi trùng không tạo đơn hay đặt lại liên kết lần thứ hai. Không dùng quy trình cộng tác viên trong nhóm.

## Chat hỗ trợ cộng tác viên

- Cộng tác viên nhấn nút `Chat với quản trị viên` trong menu của chính bot mặc định; không cần bot thứ hai, nhóm hỗ trợ hoặc cấu hình chat ID riêng.
- Tin nhắn được lưu bằng hệ thống ticket sẵn có của XBoard, nên lịch sử vẫn còn sau khi PHP, hàng đợi hoặc máy chủ khởi động lại và cũng xem được trong trang quản trị ticket.
- Cùng bot đó chuyển nội dung vào hộp thư hỗ trợ riêng của quản trị viên đã liên kết, kèm mã tham chiếu không chứa thông tin cá nhân. Bot không đưa Telegram ID của cộng tác viên vào nội dung chuyển tiếp.
- Chỉ tài khoản đã liên kết có cờ `is_admin` mới được trả lời hộp thư hỗ trợ; `is_reseller` chỉ cấp công cụ cộng tác viên. Hai vai trò độc lập, và nhân viên thông thường không có quyền trả lời luồng này.
- Có giới hạn độ dài, tần suất, nút mở/đóng/hủy, kiểm tra người gửi, chống xử lý webhook trùng và log chỉ chứa ID nội bộ cùng loại lỗi.

## Cấu hình

- Bật/tắt thông báo ticket và thanh toán.
- Bật/tắt báo cáo node, đặt ID chat/nhóm đích, ngôn ngữ và chu kỳ 5/15/60 phút.
- Bật/tắt quy trình cộng tác viên; quyền sử dụng chỉ lấy từ cờ `is_reseller` của tài khoản XBoard đã liên kết, không suy ra từ quyền quản trị viên hoặc nhân viên.
- Chat hỗ trợ cộng tác viên dùng cùng bot mặc định và hộp thư riêng của quản trị viên đã liên kết, nên không có bot token, group hoặc chat ID hỗ trợ thứ hai trong cấu hình plugin.
- Bật/tắt backup database, đặt giờ chạy, dung lượng tối đa và mật khẩu mã hóa tối thiểu 16 ký tự.

Nên đặt mật khẩu trong biến môi trường `TELEGRAM_DATABASE_BACKUP_PASSWORD` thay vì lưu ở cấu hình plugin. File tạm được cấp quyền riêng, luôn bị xóa sau khi gửi hoặc khi có lỗi và tác vụ có khóa chống chạy trùng.

Để khôi phục file nhận từ Telegram, tải file `.xbenc` về máy chủ, đặt đúng mật khẩu vào biến môi trường rồi chạy:

```bash
php artisan backup:telegram-decrypt backup.sql.gz.xbenc backup.sql.gz
gzip -dc backup.sql.gz > backup.sql
```

Lệnh giải mã từ chối ghi đè file đã tồn tại và không nhận mật khẩu trực tiếp trên dòng lệnh để tránh lộ trong lịch sử shell.

Bot sử dụng dữ liệu `online` hiện có của XBoard. Số này được tổng hợp từ báo cáo thiết bị/IP theo từng node và tự hết hạn khi thiết bị ngừng báo cáo.

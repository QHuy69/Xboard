# Plugin Telegram cho XBoard

Phiên bản plugin: **2.2.0**.

Bot Telegram hỗ trợ 8 ngôn ngữ theo ngôn ngữ tài khoản: tiếng Việt, tiếng Anh, tiếng Trung giản thể, tiếng Trung phồn thể, tiếng Nhật, tiếng Hàn, tiếng Ba Tư và tiếng Nga. Khi chưa liên kết tài khoản, bot nhận diện ngôn ngữ Telegram và dùng phương án dự phòng an toàn nếu mã ngôn ngữ không được hỗ trợ. Người dùng có thể thao tác bằng nút bấm hoặc lệnh.

## Chức năng người dùng

- Liên kết bằng deep link dùng một lần, có thời hạn ngắn, được tạo từ trang XBoard đã đăng nhập; hủy liên kết có bước xác nhận và thu hồi mọi deep link còn hiệu lực.
- Xem lưu lượng còn lại và lấy liên kết đăng ký mới nhất.
- Nhận thông báo khi mua mới, gia hạn, đổi gói, đặt lại liên kết đăng ký hoặc đặt lại lưu lượng.
- Dùng menu thân thiện thay cho việc phải nhớ lệnh.

Các lệnh chính: `/start`, `/menu`, `/bind`, `/traffic`, `/getlatesturl`, `/unbind` và `/cancel`.

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

1. Bật chức năng cộng tác viên trong cấu hình plugin. Chỉ tài khoản XBoard đã liên kết Telegram và có quyền quản trị viên hoặc cờ cộng tác viên mới được dùng.
2. Cộng tác viên nhấn menu `Cộng tác viên` hoặc dùng `/reseller` trong chat riêng với bot.
3. Chọn tạo khách hàng ẩn danh, chọn gói cùng chu kỳ, sau đó nhập mã giảm giá.
4. Bot chỉ chấp nhận mã giảm giá phần trăm đúng 100%, còn hiệu lực và áp dụng được cho gói/chu kỳ đã chọn.
5. Bot tạo khách hàng ẩn danh, đơn hàng 0đ và kích hoạt gói trong một giao dịch; chỉ mã khách hàng cùng liên kết đăng ký được trả về.
6. Danh sách, thông tin, liên kết đăng ký, đặt lại bảo mật, gia hạn và đổi gói chỉ hoạt động với khách hàng có `invite_user_id` thuộc chính cộng tác viên. Tài khoản quản trị, nhân viên hoặc cộng tác viên khác luôn bị loại khỏi phạm vi quản lý.

Mỗi nút chọn gói, chu kỳ, đặt lại bảo mật và hủy liên kết đều có mã xác nhận ngắn hạn. Callback cũ hoặc tự tạo không thể thực hiện thao tác và webhook Telegram gửi trùng không tạo đơn hay đặt lại liên kết lần thứ hai. Không dùng quy trình cộng tác viên trong nhóm.

## Chat hỗ trợ cộng tác viên

- Khi bật cấu hình chat hỗ trợ và đặt chat Telegram đích, menu cộng tác viên có nút `Chat với quản trị viên`.
- Tin nhắn được lưu bằng hệ thống ticket sẵn có của XBoard, nên lịch sử vẫn còn sau khi PHP, hàng đợi hoặc máy chủ khởi động lại và cũng xem được trong trang quản trị ticket.
- Bot chuyển nội dung vào đúng chat hỗ trợ kèm mã tham chiếu được mã hóa. Bot không đưa Telegram ID của cộng tác viên vào nội dung chuyển tiếp.
- Quản trị viên phải liên kết Telegram với tài khoản XBoard có cờ `is_admin` và trả lời trực tiếp tin nhắn bot đã chuyển tiếp. Nhân viên thông thường không có quyền trả lời luồng này.
- Có giới hạn độ dài, tần suất, nút mở/đóng/hủy, kiểm tra chat nguồn, chống xử lý webhook trùng và log chỉ chứa ID nội bộ cùng loại lỗi.

## Cấu hình

- Bật/tắt thông báo ticket và thanh toán.
- Bật/tắt báo cáo node, đặt ID chat/nhóm đích, ngôn ngữ và chu kỳ 5/15/60 phút.
- Bật/tắt quy trình cộng tác viên; quyền sử dụng lấy từ vai trò quản trị viên hoặc cờ cộng tác viên của tài khoản XBoard đã liên kết.
- Bật/tắt chat hỗ trợ cộng tác viên và đặt ID chat riêng của quản trị viên hoặc ID âm của nhóm quản trị nhận tin.
- Bật/tắt backup database, đặt giờ chạy, dung lượng tối đa và mật khẩu mã hóa tối thiểu 16 ký tự.

Nên đặt mật khẩu trong biến môi trường `TELEGRAM_DATABASE_BACKUP_PASSWORD` thay vì lưu ở cấu hình plugin. File tạm được cấp quyền riêng, luôn bị xóa sau khi gửi hoặc khi có lỗi và tác vụ có khóa chống chạy trùng.

Để khôi phục file nhận từ Telegram, tải file `.xbenc` về máy chủ, đặt đúng mật khẩu vào biến môi trường rồi chạy:

```bash
php artisan backup:telegram-decrypt backup.sql.gz.xbenc backup.sql.gz
gzip -dc backup.sql.gz > backup.sql
```

Lệnh giải mã từ chối ghi đè file đã tồn tại và không nhận mật khẩu trực tiếp trên dòng lệnh để tránh lộ trong lịch sử shell.

Bot sử dụng dữ liệu `online` hiện có của XBoard. Số này được tổng hợp từ báo cáo thiết bị/IP theo từng node và tự hết hạn khi thiết bị ngừng báo cáo.

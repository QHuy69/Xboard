# Plugin Telegram cho XBoard

Bot Telegram hỗ trợ tiếng Việt, tiếng Anh và tiếng Trung theo ngôn ngữ tài khoản. Người dùng có thể thao tác bằng nút bấm hoặc lệnh.

## Chức năng người dùng

- Liên kết hoặc hủy liên kết tài khoản XBoard.
- Xem lưu lượng còn lại và lấy liên kết đăng ký mới nhất.
- Nhận thông báo khi mua mới, gia hạn, đổi gói, đặt lại liên kết đăng ký hoặc đặt lại lưu lượng.
- Dùng menu thân thiện thay cho việc phải nhớ lệnh.

Các lệnh chính: `/start`, `/menu`, `/bind`, `/traffic`, `/getlatesturl`, `/unbind` và `/cancel`.

## Chức năng quản trị và nhóm

- Thông báo ticket và thanh toán cho tài khoản quản trị đã liên kết Telegram.
- `/nodes`: xem trạng thái node và số người đang trực tuyến trên từng node.
- `/setreportgroup`: quản trị viên hoặc nhân viên dùng lệnh này trong nhóm để chọn nhóm nhận báo cáo định kỳ.
- Có thể cấu hình chu kỳ báo cáo node là 5, 15 hoặc 60 phút.

## Quy trình cộng tác viên

1. Bật chức năng cộng tác viên trong cấu hình plugin và khai báo Telegram ID được phép.
2. Cộng tác viên nhấn menu `Cộng tác viên` hoặc dùng `/reseller` trong chat riêng với bot.
3. Nhập email khách, chọn gói và chu kỳ, sau đó nhập mã giảm giá.
4. Bot chỉ chấp nhận mã giảm giá phần trăm đúng 100%, còn hiệu lực và áp dụng được cho gói/chu kỳ đã chọn.
5. Bot tạo tài khoản, đơn hàng 0đ và kích hoạt gói trong một giao dịch; mật khẩu chỉ được gửi trong chat riêng và không ghi vào log.

Quy trình được giới hạn cho quản trị viên, nhân viên hoặc Telegram ID đã được cấu hình. Không dùng trong nhóm.

## Cấu hình

- Bật/tắt thông báo ticket và thanh toán.
- Bật/tắt báo cáo node theo nhóm và chọn chu kỳ báo cáo.
- Bật/tắt quy trình cộng tác viên và danh sách Telegram ID được phép.

Bot sử dụng dữ liệu `online` hiện có của XBoard. Số này được tổng hợp từ báo cáo thiết bị/IP theo từng node và tự hết hạn khi thiết bị ngừng báo cáo.

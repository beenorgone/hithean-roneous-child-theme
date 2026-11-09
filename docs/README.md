# Tài liệu repo — mục lục

Mỗi tài liệu viết cho **một** loại người đọc. Tìm theo việc bạn đang làm.

## Đọc / sửa code (dev, AI agent)

| Tài liệu | Dùng khi |
|---|---|
| [`../CLAUDE.md`](../CLAUDE.md) | Chỉ mục + luật từng module — agent nạp tự động. `AGENTS.md` là bản chép (sinh tự động) |
| [`architecture.md`](architecture.md) | Request đi đâu, tpc_loader, **bảng route**, tầng, module, thêm tính năng ở đâu |
| [`code-map/`](code-map/README.md) | Tìm hàm, AJAX, admin-post, REST, shortcode, cron, hook của từng khu — **sinh tự động** |
| [`erp-mesh.md`](erp-mesh.md) | Kho dùng chung với Theanmarket, MCP liên site |
| [`order-portal.md`](order-portal.md), [`order-board-performance.md`](order-board-performance.md) | `/quan-ly-don/`, hiệu năng board "Cần xử lý" |

---

## Tự cập nhật

Cùng cơ chế với ivarvietnam và theanmarket (`tools/docs-sync.php` giống hệt nhau giữa theanmarket và hithean; phần riêng
của site ở `tools/docs-sync.config.php`). Ba loại tài liệu, ba cách giữ cho đúng:

| Loại | Ví dụ | Cơ chế |
|---|---|---|
| **Sinh từ code** | `code-map/*`, khối `<!-- AUTO:… -->` trong `architecture.md`, `AGENTS.md` | `tools/docs-sync.php` dựng lại; git hook chạy mỗi commit và stage kết quả |
| **Viết tay, máy kiểm** | bảng route trong `architecture.md` | `docs-sync --check` **đỏ** khi code có route chưa ghi |
| **Viết tay, máy nhắc** | `architecture.md` (và `domain/*.md` khi có) | `applies_to` + `reviewed`: hook in danh sách cần đọc lại khi code liên quan đổi; `--check` cảnh báo khi code có commit mới hơn `reviewed` |

### Lệnh

```bash
git config core.hooksPath .githooks                 # bật hook — một lần mỗi bản clone
php tools/docs-sync.php                             # dựng lại phần sinh tự động
php tools/docs-sync.php --check                     # chỉ kiểm (CI / test); exit 1 nếu lệch
php tools/docs-sync.php --changed a.php b.php       # tài liệu viết tay nào nói về a.php, b.php
```

Sửa `AGENTS.md`? Đừng — sửa `CLAUDE.md`, hook chép sang.

### Front-matter

```yaml
---
audience: dev | domain | ops
owner: <vai trò>                       # ai chịu trách nhiệm cập nhật
reviewed: 2026-11-09                   # ngày đọc lại gần nhất, so với git log của applies_to
applies_to: [custom-functions/core/module-loader.php, custom-functions/marketing/offers/]   # file hoặc thư mục (kết thúc /)
---
```

### Muốn bản đồ code có ích hơn

Bản đồ chỉ hay bằng docblock. Cột *Docblock* trong [`code-map/README.md`](code-map/README.md) cho biết khu nào
đang mù. Docblock đầu file chính của module = mô tả module ở mọi nơi; dòng đầu docblock của hàm = mô tả hàm.

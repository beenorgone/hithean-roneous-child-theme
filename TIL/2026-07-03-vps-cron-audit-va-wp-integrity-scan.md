# TIL: Kiểm tra/làm sạch cron VPS + cron quét toàn vẹn WordPress (verify-checksums)

**Ngày:** 2026-07-03
**Repo:** hithean-roneous-child-theme
**Tag:** `vps` `cron` `wordpress` `wp-cli` `security` `verify-checksums` `www-data`

> **Site của repo này:** hithean.com — VPS z.com 1 (`163.44.206.218`), webroot `/var/www/hithean.com/html`.

---

## Bối cảnh

Rà soát toàn bộ cron trên 2 VPS z.com, dọn các cron rác, và thêm **cron quét toàn vẹn file WordPress hằng ngày** bằng `wp core/plugin verify-checksums` để phát hiện file core/plugin bị sửa (dấu hiệu webshell/hack).

**Bản đồ site ↔ VPS:**

| Site | VPS | IP | Webroot |
|---|---|---|---|
| ivarvietnam.com | z.com 1 | `163.44.206.218` | `/var/www/ivarvietnam.com/html` |
| hithean.com | z.com 1 | `163.44.206.218` | `/var/www/hithean.com/html` |
| theanmarket.com | z.com 2 | `163.44.206.41` | `/var/www/theanmarket.com/html` |

---

## 1. Lệnh rà soát toàn bộ cron + tool bảo mật

Cron nằm ở nhiều nơi, không chỉ `crontab -l`:

```bash
# Cron của user hiện tại
crontab -l

# Cron hệ thống + cron.d
grep -v '^#' /etc/crontab
for f in /etc/cron.d/*; do echo "--- $f ---"; grep -v '^#' "$f"; done
ls /etc/cron.daily /etc/cron.hourly /etc/cron.weekly /etc/cron.monthly

# Cron của root / user khác (cần sudo)
sudo crontab -l
sudo crontab -u www-data -l

# systemd timer (Debian/Ubuntu hiện đại dùng cái này nhiều)
systemctl list-timers --all --no-pager

# Tool bảo mật đã cài chưa?
for t in fail2ban-client rkhunter chkrootkit clamscan maldet lynis aide; do
  command -v "$t" >/dev/null && echo "FOUND: $t" || echo "no: $t"
done
systemctl is-active fail2ban unattended-upgrades
```

**Kết quả rà soát:** cả 2 VPS chỉ có `fail2ban` (chống brute-force) + `unattended-upgrades` (tự vá gói) — **không có cái nào quét file mã độc** cho web dir. Đó là lý do cần thêm cron verify-checksums.

---

## 2. Các cron rác đã dọn

- **URL wp-cron thiếu TLD** (`https://theanorganics/wp-cron.php` — thiếu `.com`, site không tồn tại):
  ```bash
  crontab -l | grep -v 'theanorganics' | crontab -
  ```
- **wp-cron chạy trùng** (vừa php-cli mỗi 5', vừa wget mỗi giờ) → giữ php-cli, bỏ wget:
  ```bash
  crontab -l | grep -v 'wget -q -O - https://.*wp-cron.php' | crontab -
  ```
- **Tắt WP_CRON nội bộ** để WP không tự spawn cron mỗi request (đã có cron ngoài):
  ```bash
  grep DISABLE_WP_CRON wp-config.php \
    || sed -i "/That's all, stop editing/i define('DISABLE_WP_CRON', true);" wp-config.php
  ```

---

## 3. Mật khẩu MySQL trong cron → chuyển sang `~/.my.cnf`

Cron dạng `mysql -uUSER -p'PASS' db -e "..."` để lộ mật khẩu ở crontab **và** bash history. Cách sạch:

```bash
# Tạo file cấu hình client (dùng printf cho chắc, tránh lỗi thụt lề của heredoc)
printf '[client]\nuser=USER\npassword=PASS\n' > ~/.my.cnf
chmod 600 ~/.my.cnf          # BẮT BUỘC 600, nếu không mysql sẽ bỏ qua file
mysql db -e "SELECT 1;"      # test: không cần -u/-p nữa
```

Sau đó sửa cron bỏ `-uUSER -p'PASS'`, chỉ còn `mysql db -e "..."`.

> Lỗi thường gặp: `Found option without preceding group ... at line 2` = dòng 1 không phải `[client]` (heredoc bị thụt lề / ký tự lạ). Kiểm tra bằng `cat -A ~/.my.cnf` (phải thấy `[client]$` ở dòng 1).

**Dọn mật khẩu đã lỡ vào bash history:**
```bash
sed -i '/PASS/d' ~/.bash_history   # xóa dòng chứa mật khẩu trong file đã lưu
history -c                          # xóa history trong RAM của phiên hiện tại
# KHÔNG chạy 'history -w' sau đó (sẽ ghi đè lại file bằng RAM cũ)
```

---

## 4. Cron quét toàn vẹn WordPress (phần chính)

### Vì sao phải chạy dưới `www-data`, không phải user thường

- File web thuộc `www-data`.
- `wp core verify-checksums` quét cả `wp-content/uploads` (kể cả `--skip-plugins`).
- Trên ivarvietnam có thư mục `uploads/print-invoices-packing-slip-labels-for-woocommerce` mode **`2700`** (`drwx--S---`) → **nhóm không đọc được**. User `beenorgone` dù đã ở trong nhóm `www-data` vẫn bị `Permission denied` (mode 2700 không cho nhóm đọc).
- ⇒ Chạy dưới **chính `www-data`** (chủ sở hữu) là cách duy nhất đọc được mọi file mà không phải nới lỏng quyền thư mục hóa đơn nhạy cảm.

> Kiểm tra nhanh: `id` (xem có trong nhóm `www-data` chưa), `ls -ld <dir>` (xem mode). Mode `drwx--S---` = owner-only, vào nhóm cũng vô ích.

### Script `/usr/local/bin/wp-checksum-scan.sh` (giống nhau trên mọi VPS)

```bash
sudo tee /usr/local/bin/wp-checksum-scan.sh >/dev/null <<'EOF'
#!/usr/bin/env bash
# Daily WordPress integrity scan (WP-CLI verify-checksums). Run as www-data.
# Usage: wp-checksum-scan.sh /path/site1/html [/path/site2/html ...]
set -u
export HOME=/tmp
export WP_CLI_CACHE_DIR=/tmp/.wp-cli-cache

LOG_DIR=/var/log/wp-checksum
mkdir -p "$LOG_DIR" 2>/dev/null
STAMP=$(date '+%F %T')
DAYLOG="$LOG_DIR/scan-$(date +%F).log"
ALERT="$LOG_DIR/alerts.log"
had_problem=0
clean() { grep -vaiE 'readline|PHP Startup' || true; }

for site in "$@"; do
  [ -f "$site/wp-load.php" ] || { echo "[$STAMP] SKIP (not WP): $site" >>"$DAYLOG"; continue; }
  name=$(basename "$(dirname "$site")")

  core_raw=$(wp --path="$site" --skip-plugins --skip-themes core verify-checksums 2>&1); core_rc=$?
  plug_raw=$(wp --path="$site" --skip-plugins --skip-themes plugin verify-checksums --all 2>&1)

  {
    echo "===== [$STAMP] $name ====="
    echo "--- core (rc=$core_rc) ---";  printf '%s\n' "$core_raw" | clean
    echo "--- plugins ---";             printf '%s\n' "$plug_raw" | clean
    echo
  } >>"$DAYLOG"

  # Core: rc != 0 = file core bị đổi/thiếu/lỗi
  if [ "$core_rc" -ne 0 ]; then
    { echo "[$STAMP] CORE FAIL @ $name"; printf '%s\n' "$core_raw" | clean; echo; } >>"$ALERT"
    had_problem=1
  fi
  # Plugin: chỉ báo động khi có dấu hiệu file bị sửa/thêm/thiếu
  # (bỏ qua "Could not retrieve checksums" của plugin premium không có trên wp.org)
  if printf '%s\n' "$plug_raw" | grep -qiE "does ?n['\`]?t verify|File was added|File is missing|checksum does not match"; then
    { echo "[$STAMP] PLUGIN FAIL @ $name"; printf '%s\n' "$plug_raw" | clean | grep -iE "does ?n['\`]?t verify|added|missing"; echo; } >>"$ALERT"
    had_problem=1
  fi
done

find "$LOG_DIR" -name 'scan-*.log' -mtime +30 -delete 2>/dev/null

if [ "$had_problem" -ne 0 ]; then
  echo "WordPress integrity scan found issues on $(hostname). See $ALERT"
  exit 1
fi
EOF

sudo chmod +x /usr/local/bin/wp-checksum-scan.sh
sudo mkdir -p /var/log/wp-checksum
sudo chown www-data:www-data /var/log/wp-checksum
```

### Chạy thử (dưới www-data) trước khi cài cron

```bash
# VPS z.com 1 (163.44.206.218)
sudo -u www-data /usr/local/bin/wp-checksum-scan.sh \
  /var/www/ivarvietnam.com/html /var/www/hithean.com/html

# VPS z.com 2 (163.44.206.41)
sudo -u www-data /usr/local/bin/wp-checksum-scan.sh /var/www/theanmarket.com/html

cat /var/log/wp-checksum/scan-$(date +%F).log   # core phải hiện "verifies against checksums"
```

### Cài cron vào crontab của www-data (03:30 — né cleanup 03:00 / backup 02:00)

```bash
# VPS 1 (cả ivarvietnam + hithean trên cùng VPS)
( sudo crontab -u www-data -l 2>/dev/null; \
  echo '30 3 * * * /usr/local/bin/wp-checksum-scan.sh /var/www/ivarvietnam.com/html /var/www/hithean.com/html' \
) | sudo crontab -u www-data -

# VPS 2
( sudo crontab -u www-data -l 2>/dev/null; \
  echo '30 3 * * * /usr/local/bin/wp-checksum-scan.sh /var/www/theanmarket.com/html' \
) | sudo crontab -u www-data -

sudo crontab -u www-data -l   # kiểm tra
```

> Dùng `( crontab -l; echo ... ) | crontab -` để **nối thêm**, không ghi đè crontab hiện có.

---

## 5. Xem kết quả

```bash
sudo tail -n 50 /var/log/wp-checksum/alerts.log   # RỖNG = sạch
ls -la /var/log/wp-checksum/                        # log đầy đủ theo ngày, tự xóa sau 30 ngày
```

Muốn nhận **email** khi có sự cố: nếu server có MTA (postfix), thêm dòng đầu crontab www-data:
`MAILTO="you@example.com"` — script đã in cảnh báo ra stdout khi phát hiện vấn đề nên cron sẽ tự gửi mail.

---

## Điều học được

1. **Cron ở nhiều nơi**: `crontab -l` chỉ là 1 chỗ. Phải soi thêm `/etc/crontab`, `/etc/cron.d`, `cron.{daily,hourly,...}`, crontab của user khác, và **systemd timer**.
2. **verify-checksums quét cả uploads** → cần quyền đọc toàn bộ webroot ⇒ chạy dưới `www-data`, không phải user thường.
3. **Mode `2700` (`drwx--S---`)** chỉ cho owner; vào nhóm `www-data` vẫn không đọc được. Đừng nhầm "vào nhóm là đủ".
4. **Chọn user đúng > nới quyền**: chạy đúng owner sạch hơn `chmod g+rx` lên thư mục nhạy cảm (hóa đơn).
5. **Bí mật trong cron/history**: chuyển mật khẩu MySQL sang `~/.my.cnf` (chmod 600) và dọn `~/.bash_history` bằng `sed` + `history -c` (không `history -w` sau đó).
6. **Đã có** fail2ban + unattended-upgrades; **còn thiếu** quét file mã độc — verify-checksums lấp phần lõi (core+plugin từ wp.org), nhẹ, không cài thêm gì. Nếu cần sâu hơn: maldet (webshell) / rkhunter (rootkit).

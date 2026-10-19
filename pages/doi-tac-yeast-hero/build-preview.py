"""Build a browser preview without requiring a local WordPress instance."""

from html import escape
from pathlib import Path
import re
from urllib.request import Request, urlopen

from bs4 import BeautifulSoup


HERE = Path(__file__).resolve().parent
source = (HERE / "doi-tac-yeast-hero.html").read_text()

products = [
    (
        "Original",
        "HƯƠNG VỊ TRUNG TÍNH",
        "Một điểm bắt đầu linh hoạt cho khách hàng muốn tự chọn cách pha và cách dùng.",
        "Thử phản hồi về vị, cách pha và tỷ lệ mua lại.",
        "https://hithean.com/wp-content/uploads/2024/08/Yeast-Hero-Protein-Powder-Original-1-600x600.png",
        "#e9ded0",
    ),
    (
        "Vị Bơ Matcha",
        "BƠ VƯỜN RỪNG VÀ MATCHA HỮU CƠ",
        "Phiên bản kết hợp protein từ nấm men, bơ vườn rừng và matcha hữu cơ.",
        "Thử mức quan tâm với hương vị mới trong kênh bán.",
        "https://hithean.com/wp-content/uploads/2026/04/Label-sachet-Yeast-Hero-Protein-Avocado-Matcha-front-1-600x600.png",
        "#d8e2cf",
    ),
    (
        "Overnight",
        "LẤY CẢM HỨNG TỪ BỮA SÁNG",
        "Một hướng tư vấn cho khách thích món ăn tiện chuẩn bị và kết cấu đậm hơn đồ uống protein thông thường.",
        "Đo mức quan tâm từ khách hàng tìm sản phẩm cho bữa sáng hoặc bữa phụ.",
        "https://hithean.com/wp-content/uploads/2024/11/Label-sachet-Yeast-Hero-Overnight-Protein-Powder-front-600x600.png",
        "#e4d7c6",
    ),
    (
        "Midnight Silk",
        "CÂU CHUYỆN HƯƠNG VỊ KHÁC BIỆT",
        "Hương vị có câu chuyện riêng, phù hợp để thử cách trưng bày và nội dung giới thiệu mới.",
        "Thử thông điệp, dùng mẫu và theo dõi phản hồi về hương vị.",
        "https://hithean.com/wp-content/uploads/2026/06/Label-Sachet-Midnight-Silk-Yeast-Hero-Protein-Powder-front-600x600.png",
        "#ddd4df",
    ),
]

tabs = "".join(
    f'<button type="button" class="yh-preview-tab{" is-active" if index == 0 else ""}" '
    f'role="tab" aria-selected="{"true" if index == 0 else "false"}" '
    f'aria-controls="yh-preview-panel-{index}" id="yh-preview-tab-{index}" data-preview-tab="{index}">'
    f'<img src="{escape(image)}" alt="" width="48" height="48" loading="lazy">{escape(name)}</button>'
    for index, (name, _, _, _, image, _) in enumerate(products)
)
panels = "".join(
    f'<article id="yh-preview-panel-{index}" class="yh-preview-panel" role="tabpanel" '
    f'aria-labelledby="yh-preview-tab-{index}"{" hidden" if index else ""}>'
    f'<div class="yh-preview-art" style="--preview-product-bg:{color}">'
    f'<img src="{escape(image)}" alt="Yeast Hero {escape(name)}" width="600" height="600" loading="lazy"></div>'
    f'<div class="yh-preview-copy"><p class="yh-eyebrow">{escape(badge)}</p>'
    f'<h3>Yeast Hero {escape(name)}</h3><p>{escape(intro)}</p>'
    f'<ul><li>Protein từ nấm men</li><li>Thông tin thành phần và dinh dưỡng theo nhãn hiện hành</li></ul>'
    f'<p class="yh-preview-pilot"><strong>Gợi ý pilot</strong><br>{escape(pilot)}</p>'
    f'<a class="yh-button yh-button--dark" href="#partnership-contact">Trao đổi SKU pilot <span aria-hidden="true">→</span></a></div>'
    f'</article>'
    for index, (name, badge, intro, pilot, image, color) in enumerate(products)
)
product_html = (
    '<section id="anc-products" class="yh-preview-products" aria-label="Dòng sản phẩm Yeast Hero">'
    '<div class="yh-shell"><div class="yh-preview-tabs" role="tablist" aria-label="Chọn sản phẩm Yeast Hero">'
    + tabs + '</div>' + panels + '</div></section>'
)

# Clone the already rendered B2B slide for a faithful local preview. The source
# page itself still uses the local WordPress shortcodes above. If the public site
# cannot be reached, the lightweight fallback remains available for review.
try:
    request = Request(
        "https://hithean.com/an-new-chapter-b2b/",
        headers={"User-Agent": "Mozilla/5.0 (local landing preview)"},
    )
    with urlopen(request, timeout=12) as response:
        live = BeautifulSoup(response.read(), "html.parser")
    slide = live.select_one("#anc-products")
    if slide is None or len(slide.select(".anc-pf-panel")) != 4:
        raise ValueError("The public product slide is incomplete")
    for image in slide.select("img[data-lazy-src]"):
        image["src"] = image["data-lazy-src"]
        image.attrs.pop("srcset", None)
    for unwanted in slide.select("noscript, .anc-product-price, .anc-pf-cta"):
        unwanted.decompose()
    for link in slide.select(".anc-b2b-product-cta"):
        link["href"] = "#partnership-contact"
        link.string = "Trao đổi SKU pilot →"
    product_html = str(slide)
except Exception as error:
    print(f"Using simple product preview: {error}")

eyebrow = re.search(r'\[anc_product_switcher[^\n]*? eyebrow="([^"]+)"', source)
if eyebrow:
    preview_slide = BeautifulSoup(product_html, "html.parser")
    heading_container = preview_slide.select_one('.anc-pf-switcher, .yh-shell')
    if heading_container:
        label = preview_slide.new_tag('p', attrs={'class': 'anc-section-eyebrow'})
        label.string = eyebrow.group(1)
        heading_container.insert(0, label)
        product_html = str(preview_slide)

source, count = re.subn(
    r'\[anc_product_switcher[^\n]*\][\s\S]*?\[/anc_product_switcher\]',
    lambda _: product_html,
    source,
    count=1,
)
if count != 1:
    raise RuntimeError("Expected exactly one product switcher shortcode")

source = source.replace(
    '[company_info field="email_sales" link="yes"]',
    '<a href="mailto:sales@ivarvietnam.com">sales@ivarvietnam.com</a>',
)
source, count = re.subn(
    r'      <iframe id="yh-signup-frame"[^\n]*</iframe>',
    '''      <div class="yh-preview-form" aria-label="Minh họa form đăng ký đối tác">
        <p class="yh-preview-form-note">BẢN XEM TRƯỚC · Form minh họa, không gửi dữ liệu</p>
        <div class="yh-preview-field">Họ và tên <span>*</span></div>
        <div class="yh-preview-field">Số điện thoại <span>*</span></div>
        <div class="yh-preview-field">Email công việc</div>
        <div class="yh-preview-field">Tên doanh nghiệp / cửa hàng</div>
        <div class="yh-preview-field yh-preview-field--large">Lời nhắn / quy mô dự kiến</div>
        <a class="yh-button yh-button--dark" href="https://ivarvietnam.com/dang-ky-dai-ly/?src=hithean" target="_blank" rel="noopener noreferrer">Mở form thật <span aria-hidden="true">↗</span></a>
      </div>''',
    source,
    count=1,
)
if count != 1:
    raise RuntimeError("Expected exactly one CRM iframe")

preview_css = """
<style>
.yh-preview-products {padding: 8px 0 64px; background: var(--yh-cream)}
.yh-preview-tabs {display: flex; justify-content: center; flex-wrap: wrap; gap: 12px; margin: 0 0 28px}
.yh-preview-tab {display: flex; align-items: center; gap: 9px; min-height: 60px; padding: 5px 17px 5px 7px; border: 1px solid #d9dfd8; border-radius: 100px; background: #fff; color: var(--yh-ink); font: 600 13px 'Be Vietnam Pro', sans-serif; cursor: pointer}
.yh-preview-tab.is-active {border-color: var(--yh-teal); box-shadow: 0 0 0 2px rgba(15,118,110,.12)}
.yh-preview-tab img {width: 48px; height: 48px; object-fit: contain; border-radius: 50%; background: #f3eee7}
.yh-preview-panel {display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); align-items: center; gap: clamp(30px,6vw,82px); padding: 26px; border-radius: 12px; background: #fff; box-shadow: 0 15px 40px rgba(8,45,58,.07)}
.yh-preview-panel[hidden] {display: none}
.yh-preview-art {display: grid; place-items: center; min-height: 430px; border-radius: 10px; background: radial-gradient(circle at 50% 50%, #fff 0%, var(--preview-product-bg) 76%)}
.yh-preview-art img {display: block; width: min(100%, 440px); height: auto; object-fit: contain}
.yh-preview-copy {padding: 20px 30px 20px 0}
.yh-preview-copy h3 {margin: 12px 0 15px; font-size: clamp(36px,4vw,56px); font-weight: 500; line-height: 1.1; text-transform: uppercase}
.yh-preview-copy p {color: var(--yh-muted)}
.yh-preview-copy ul {padding-left: 20px; margin: 24px 0; color: var(--yh-ink)}
.yh-preview-copy li {margin: 8px 0}
.yh-preview-pilot {padding: 16px; border-left: 3px solid var(--yh-teal); background: #f2f8f4}
.yh-preview-copy .yh-button {margin-top: 14px}
.yh-preview-form {padding: 22px; border: 1px solid #dce6e4; border-radius: 8px; background: #fbfdfc}
.yh-preview-form-note {margin: 0 0 17px; color: var(--yh-teal); font-size: 11px!important; font-weight: 700; letter-spacing: .05em}
.yh-preview-field {display: flex; justify-content: space-between; align-items: center; min-height: 54px; margin: 0 0 13px; padding: 12px 15px; border: 1px solid #d3ddda; border-radius: 5px; background: #fff; color: #617573; font-size: 13px}
.yh-preview-field span {color: #a64036}
.yh-preview-field--large {min-height: 105px; align-items: flex-start}
.yh-preview-form .yh-button {width: 100%; margin-top: 4px}
@media(max-width:740px) {.yh-preview-panel {grid-template-columns: 1fr; gap: 12px; padding: 15px}.yh-preview-art {min-height: 280px}.yh-preview-art img {width: min(100%,320px)}.yh-preview-copy {padding: 10px 10px 20px}.yh-preview-tabs {justify-content: flex-start; flex-wrap: nowrap; overflow-x: auto; padding: 4px 2px 10px}.yh-preview-tab {flex: none}}
</style>
"""
source = source.replace('</head>', preview_css + '</head>')
source = source.replace(
    '</body>',
    '''<script>
document.querySelectorAll('[data-preview-tab]').forEach((tab) => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('[data-preview-tab]').forEach((item) => {
      const active = item === tab;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', String(active));
      document.getElementById('yh-preview-panel-' + item.dataset.previewTab).hidden = !active;
    });
  });
});
</script>
</body>''',
)

(HERE / "preview.html").write_text(source)
print(HERE / "preview.html")

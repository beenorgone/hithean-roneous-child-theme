<?php
/**
 * Template Name: Landing Page
 *
 * Blank landing page — không dùng .main-container, không có nav, không có footer.
 * CSS + JS được enqueue riêng theo page slug trong functions.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo( 'charset' ); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <?php wp_head(); ?>
</head>
<body <?php body_class( 'anc-page' ); ?>>
<?php wp_body_open(); ?>


<!-- ============================================================
     HERO
     ============================================================ -->
<section id="anc-hero">
  <div class="anc-hero-content anc-fade-in">

    <div class="anc-hero-brand">
      <img src="https://hithean.com/wp-content/uploads/2024/08/logo-thean-w200-no-paddings.png" alt="The An Organics" class="anc-hero-logo" />
      <div class="anc-cert-badge">
        <span class="anc-cert-badge-org">CERTIFIED ORGANIC</span>
        <span class="anc-cert-badge-num">CU 916118</span>
      </div>
    </div>

    <h1 class="anc-hero-title">"AN" NEW CHAPTER</h1>
    <p class="anc-hero-subtitle">Khởi nguồn bản địa, bắt nhịp thế giới</p>

    <div class="anc-hero-ctas">
      <a href="#anc-certification" class="button--light-blue">Chứng nhận hữu cơ</a>
      <a href="#anc-products" class="button--light-green">Sản phẩm mới</a>
    </div>

    <span class="anc-scroll-arrow" aria-hidden="true">↓</span>

  </div>
</section>


<!-- ============================================================
     CERTIFICATION
     ============================================================ -->
<section id="anc-certification">
  <div class="anc-cert-section-inner">

    <div class="anc-cert-badge-large anc-fade-in">
      <span class="anc-badge-leaf">🌿</span>
      <span class="anc-badge-org">CERTIFIED ORGANIC</span>
      <span class="anc-badge-cert">NHÀ MÁY SẢN XUẤT</span>
      <span class="anc-badge-num">CU 916118</span>
    </div>

    <h2 class="anc-section-title anc-fade-in">CHỨNG NHẬN HỮU CƠ</h2>

    <p class="anc-cert-desc anc-fade-in">
      Nhà máy sản xuất của IVAR chính thức tiếp nhận chứng nhận hữu cơ <strong>CU 916118</strong> — cột mốc xác nhận toàn bộ quy trình sản xuất đạt tiêu chuẩn hữu cơ quốc tế, từ nguyên liệu đầu vào đến thành phẩm.
    </p>

    <div class="anc-cert-stats anc-fade-in-children">

      <div class="stat-box">
        <div class="stat-number">CU 916118</div>
        <p class="stat-text">Mã chứng nhận hữu cơ quốc tế</p>
      </div>

      <div class="stat-box">
        <div class="stat-number">100%</div>
        <p class="stat-text">Quy trình sản xuất được chứng nhận hữu cơ</p>
      </div>

      <div class="stat-box">
        <div class="stat-number">2026</div>
        <p class="stat-text">Năm đầu tiên nhà máy nhận chứng nhận</p>
      </div>

    </div>

    <p class="anc-cert-bridge anc-fade-in">Chứng nhận hữu cơ là mảnh ghép mới nhất trong hệ thống sản xuất được xây dựng trên nền tảng khoa học và minh bạch của The An Organics.</p>

  </div>
</section>


<!-- ============================================================
     QC / PRODUCTION SYSTEM
     ============================================================ -->
<section id="anc-qc">
  <div class="anc-qc-inner">

    <h2 class="anc-section-title anc-fade-in">HỆ THỐNG SẢN XUẤT</h2>
    <p class="anc-qc-desc anc-fade-in">Mọi sản phẩm The An Organics được xây dựng trên nền tảng khoa học và minh bạch — từ nguyên liệu đầu vào đến thành phẩm trên kệ.</p>

    <div class="anc-qc-grid anc-fade-in-children">

      <div class="anc-qc-item">
        <span class="anc-qc-icon" aria-hidden="true">🏭</span>
        <div class="anc-qc-label">Nhà máy</div>
        <div class="anc-qc-value">đặt tại Cụm sản xuất dịch vụ, thôn Văn Khê, xã Nghĩa Hương, Hà Nội, Việt Nam</div>
      </div>

      <div class="anc-qc-item">
        <span class="anc-qc-icon" aria-hidden="true">📋</span>
        <div class="anc-qc-label">Tiêu chuẩn sản xuất</div>
        <div class="anc-qc-value">ISO 22000 · HACCP · GMP<br><small>Mã: TQC.03.6137 · Cấp bởi TQC CGLOBAL</small></div>
      </div>

      <div class="anc-qc-item">
        <span class="anc-qc-icon" aria-hidden="true">🇺🇸</span>
        <div class="anc-qc-label">FDA Registration (Hoa Kỳ)</div>
        <div class="anc-qc-value">18772617074<br><small>CGLOBAL.FDA.9269</small></div>
      </div>

      <div class="anc-qc-item">
        <span class="anc-qc-icon" aria-hidden="true">🌿</span>
        <div class="anc-qc-label">Chứng nhận hữu cơ</div>
        <div class="anc-qc-value">USDA · EU · CU 916118</div>
      </div>

      <div class="anc-qc-item">
        <span class="anc-qc-icon" aria-hidden="true">🔬</span>
        <div class="anc-qc-label">Đối tác kiểm nghiệm</div>
        <div class="anc-qc-value">Eurofins — 900+ lab tại 50+ quốc gia</div>
      </div>

      <div class="anc-qc-item">
        <span class="anc-qc-icon" aria-hidden="true">✅</span>
        <div class="anc-qc-label">Quy trình kiểm tra</div>
        <div class="anc-qc-value">2 lần: nguyên liệu đầu vào &amp; thành phẩm</div>
      </div>

    </div>

    <a href="https://hithean.com/ve-chung-toi/kiem-soat-chat-luong/" class="button--light-blue anc-qc-link anc-fade-in" target="_blank" rel="noopener">Xem đầy đủ hệ thống kiểm soát chất lượng →</a>

  </div>
</section>


<!-- ============================================================
     NEW PRODUCTS
     ============================================================ -->
<section id="anc-products">

  <h2 class="anc-section-title anc-fade-in">SẢN PHẨM MỚI</h2>
  <p class="anc-section-subtitle anc-fade-in">Ra mắt nhân dịp chứng nhận hữu cơ CU 916118</p>

  <div class="anc-products-grid anc-fade-in-children">

    <!-- Card 1: Yeast Hero Matcha Bơ -->
    <div class="anc-product-card">
      <span class="anc-product-badge">ƯU ĐÃI RA MẮT</span>
      <img class="anc-product-img" src="https://hithean.com/wp-content/uploads/2026/04/Label-sachet-Yeast-Hero-Protein-Avocado-Matcha-front-and-back-1.png" alt="Yeast Hero Protein Powder Avocado Matcha" />
      <div class="anc-product-body">
        <h3>Yeast Hero Matcha Bơ</h3>
        <ul class="anc-product-highlights">
          <li>22g protein hoàn chỉnh / khẩu phần (thành phần Yeast Protein có điểm chất lượng protein PDCAAS hoàn hảo = 1)</li>
          <li>Matcha ceremonial hữu cơ &amp; bơ vườn rừng sấy thăng hoa (~40g)</li>
          <li>5g chất xơ · ít hơn 1g đường · 160 kcal/khẩu phần</li>
          <li>Làm ngọt tự nhiên bằng la hán quả hữu cơ</li>
          <li>Không gluten · Không GMO · Không sữa</li>
        </ul>
        <div class="anc-product-price">
          <span class="anc-price-sale">960.000₫</span>
          <span class="anc-price-original">1.280.000₫</span>
          <span class="anc-price-tag">-25%</span>
        </div>
        <p class="anc-price-note">✦ Miễn phí vận chuyển đến 20/06</p>
        <a href="https://hithean.com/san-pham/protein/yeast-hero-protein-powder-avocado-matcha/" class="button--light-green" target="_blank" rel="noopener">Xem &amp; Mua ngay</a>
      </div>
    </div>

    <!-- Card 2: Bột Matcha Hữu Cơ The An Organics -->
    <div class="anc-product-card">
      <span class="anc-product-badge badge-soon">SẮP RA MẮT</span>
      <span class="anc-product-badge badge-organic">Chứng nhận hữu cơ USDA &amp; EU ORGANIC</span>
      <img class="anc-product-img" src="https://hithean.com/wp-content/uploads/2026/06/Organic-Matcha-Powder-The-An-Organics-1-600x600.jpg" alt="Bột Matcha Hữu Cơ The An Organics" />
      <div class="anc-product-body">
        <h3>Bột Matcha Hữu Cơ The An Organics</h3>
        <ul class="anc-product-highlights">
          <li>Matcha hữu cơ nguyên chất từ nhà máy CU 916118</li>
          <li>Đạt tiêu chuẩn hữu cơ EU / USDA</li>
          <li>Pha với nước 70–80°C để giữ nguyên dưỡng chất</li>
          <li>ISO 22000 · HACCP · GMP · Kiểm nghiệm Eurofins</li>
        </ul>
        <div class="anc-product-price">
          <span class="anc-price-sale">430.000₫</span>
        </div>
        <a href="https://hithean.com/san-pham/sieu-thuc-pham/bot-matcha-huu-co/" class="button--light-blue" target="_blank" rel="noopener">Xem thêm &amp; Đặt trước</a>
      </div>
    </div>

    <!-- Card 3: Bột Inulin Hữu Cơ -->
    <div class="anc-product-card">
      <span class="anc-product-badge badge-organic">Chứng nhận hữu cơ USDA &amp; EU ORGANIC</span>
      <img class="anc-product-img" src="https://hithean.com/wp-content/uploads/2025/09/Bot-inulin-huu-co-The-An-Organics-Organic-Inulin-8-1-600x600.png" alt="Bột Inulin Hữu Cơ The An Organics" />
      <div class="anc-product-body">
        <h3>Bột Inulin Hữu Cơ The An Organics</h3>
        <ul class="anc-product-highlights">
          <li>Chất xơ hòa tan từ rễ chicory hữu cơ</li>
          <li>Prebiotics nuôi dưỡng lợi khuẩn đường ruột</li>
          <li>Vị ngọt nhẹ tự nhiên, hòa tan dễ — không ảnh hưởng mùi vị</li>
          <li><strong>Chính thức được phép dùng logo USDA Organic &amp; EU Organic</strong></li>
        </ul>
        <div class="anc-product-price">
          <span class="anc-price-sale">350.000₫</span>
          <span class="anc-price-unit">/ hộp 200g</span>
        </div>
        <p class="anc-price-note">✦ Mua 2: -5% · Mua 3+: -10%</p>
        <a href="https://hithean.com/san-pham/sieu-thuc-pham/bot-inulin-huu-co/" class="button--light-green" target="_blank" rel="noopener">Xem sản phẩm</a>
      </div>
    </div>

  </div>
</section>


<!-- ============================================================
     BRAND STORY
     ============================================================ -->
<section id="anc-story">
  <div class="anc-story-inner anc-fade-in">
    <p class="anc-story-lead">Nhưng Yeast Hero Matcha Bơ không chỉ là một hương vị mới của dòng sản phẩm Yeast Hero Protein</p>
    <p class="anc-story-body">Đây là một dấu mốc của The An Organics trong hành trình đưa những nguyên liệu đặc trưng của Việt Nam bước vào thế hệ sản phẩm dinh dưỡng hiện đại đang được ưa chuộng trên toàn thế giới.</p>
    <p class="anc-story-body">Từ những trái bơ được canh tác theo mô hình vườn rừng đến ly protein mỗi ngày, chúng tôi mong muốn tạo ra nhiều giá trị hơn cho người tiêu dùng và cho nông dân địa phương.</p>
    <span class="anc-story-en">A New Chapter: Rooted Locally, Inspired Globally.</span>
  </div>
</section>


<!-- ============================================================
     YEAST HERO UPDATES
     ============================================================ -->
<section id="anc-updates">

  <h2 class="anc-section-title anc-fade-in">NÂNG CẤP YEAST HERO</h2>
  <span class="anc-updates-label anc-fade-in">Áp dụng từ tháng 3/2026</span>

  <div class="anc-updates-grid anc-fade-in-children">

    <div class="anc-update-card">
      <span class="anc-update-icon" aria-hidden="true">⚗️</span>
      <h3>Công thức mới</h3>
      <p>Nâng cấp công thức giúp <strong>tan tốt hơn</strong>, mang đến trải nghiệm vị ấn tượng hơn — dễ pha, dễ uống, không bị vón cục.</p>
    </div>

    <div class="anc-update-card">
      <span class="anc-update-icon" aria-hidden="true">📦</span>
      <h3>Bao bì HP Indigo ElectroInk</h3>
      <p>Chuyển sang mực in <strong>HP Indigo ElectroInk</strong> — tuân thủ quy định US FDA về tiếp xúc gián tiếp với thực phẩm.</p>
      <details class="anc-update-detail">
        <summary>Chi tiết về an toàn thực phẩm ↓</summary>
        <p class="anc-detail-body">HP Indigo ElectroInk tuân thủ các quy định của FDA Hoa Kỳ (US FDA Compliance) về tiếp xúc gián tiếp với thực phẩm, cụ thể là đối với việc in trên bề mặt không tiếp xúc với thực phẩm của bao bì. Các loại mực này an toàn cho nhiều loại thực phẩm khác nhau trong các điều kiện sử dụng cụ thể.</p>
      </details>
    </div>

  </div>
</section>


<!-- ============================================================
     PROTEIN CALCULATOR
     ============================================================ -->
<section id="anc-calculator">
  <div class="anc-calc-card anc-fade-in">

    <h2>TÍNH LƯỢNG PROTEIN MỖI NGÀY</h2>
    <p class="anc-calc-desc">Nhập thông tin để tính nhu cầu protein phù hợp với cơ thể và mục tiêu của bạn.</p>

    <form id="anc-calc-form" novalidate>

      <div class="anc-field">
        <label for="anc-weight">Cân nặng (kg)</label>
        <input type="number" id="anc-weight" name="weight" placeholder="Ví dụ: 60" min="30" max="200" required />
      </div>

      <div class="anc-field">
        <label for="anc-activity">Mức độ vận động</label>
        <select id="anc-activity" name="activity">
          <option value="sedentary">Ít vận động (ngồi nhiều)</option>
          <option value="light">Vận động nhẹ (1–3 ngày/tuần)</option>
          <option value="moderate" selected>Vận động vừa (3–5 ngày/tuần)</option>
          <option value="active">Vận động nhiều (6–7 ngày/tuần)</option>
          <option value="very">Vận động rất nhiều (2 buổi/ngày)</option>
        </select>
      </div>

      <div class="anc-field">
        <label for="anc-goal">Mục tiêu</label>
        <select id="anc-goal" name="goal">
          <option value="lose">Giảm cân / Cắt mỡ</option>
          <option value="maintain" selected>Duy trì sức khỏe</option>
          <option value="gain">Tăng cơ / Bulking</option>
        </select>
      </div>

      <button type="submit" class="button--nuocepkytu-light-green anc-calc-btn">TÍNH NGAY →</button>

    </form>

    <div id="anc-calc-result">
      <span class="anc-result-number" id="anc-result-num">--</span>
      <div class="anc-result-unit">gram protein / ngày</div>
      <p class="anc-result-note">Ước tính dựa trên cân nặng và mục tiêu. Tham khảo chuyên gia dinh dưỡng để được tư vấn cụ thể hơn.</p>
      <div id="anc-suggestion-container"></div>
    </div>

  </div>
</section>


<!-- ============================================================
     CTA BAND
     ============================================================ -->
<section id="anc-cta-band">
  <h2 class="anc-fade-in">"AN" NEW CHAPTER</h2>
  <p class="anc-fade-in">Khởi nguồn bản địa, bắt nhịp thế giới — trao quyền cho sức khỏe cá nhân, cộng đồng và hành tinh.</p>
  <a href="https://hithean.com/" class="button--light-blue anc-fade-in" target="_blank" rel="noopener">XEM TẤT CẢ SẢN PHẨM →</a>
</section>


<?php wp_footer(); ?>
</body>
</html>

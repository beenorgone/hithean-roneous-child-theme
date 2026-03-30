<?php
/*
 * Shortcode: [protein_calculator]
 * Tính lượng protein cần thiết theo khuyến nghị VN và Mỹ (2026-2030)
 */

add_shortcode('protein_calculator', 'render_protein_calculator');

function render_protein_calculator($atts)
{
    $atts = shortcode_atts([
        'products_default'      => '4690,3977',
        'products_sedentary'    => '',
        'products_light'        => '',
        'products_moderate'     => '',
        'products_active'       => '',
        'products_very_active'  => '',
        'products_pregnant_t1'  => '',
        'products_pregnant_t2'  => '',
        'products_pregnant_t3'  => '',
        'products_columns'      => '2',
    ], $atts, 'protein_calculator');

    $sanitize_ids = static function ($ids, $fallback = '') {
        $ids = is_string($ids) ? $ids : '';
        $parts = array_filter(array_map('trim', explode(',', $ids)));
        $parts = array_map(static function ($id) {
            return preg_replace('/\D+/', '', (string) $id);
        }, $parts);
        $parts = array_filter($parts);
        $parts = array_values(array_unique($parts));

        if (empty($parts) && $fallback !== '') {
            return $fallback;
        }

        return implode(',', $parts);
    };

    $product_columns = max(1, absint($atts['products_columns']));
    $default_ids = $sanitize_ids($atts['products_default'], '4690,3977');
    $product_ids_by_case = [
        'default'      => $default_ids,
        'sedentary'    => $sanitize_ids($atts['products_sedentary'], $default_ids),
        'light'        => $sanitize_ids($atts['products_light'], $default_ids),
        'moderate'     => $sanitize_ids($atts['products_moderate'], $default_ids),
        'active'       => $sanitize_ids($atts['products_active'], $default_ids),
        'very_active'  => $sanitize_ids($atts['products_very_active'], $default_ids),
        'pregnant_t1'  => $sanitize_ids($atts['products_pregnant_t1'], $default_ids),
        'pregnant_t2'  => $sanitize_ids($atts['products_pregnant_t2'], $default_ids),
        'pregnant_t3'  => $sanitize_ids($atts['products_pregnant_t3'], $default_ids),
    ];

    ob_start();
?>
    <style>
        /* All rules namespaced under .protein-calc-wrapper to prevent style bleeding */

        /* ---- Wrapper ---- */
        .protein-calc-wrapper {
            background: #fff;
            padding: 0;
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,.10), 0 1px 2px rgba(0,0,0,.08);
            max-width: 960px;
            margin: 0 auto;
            font-family: "Be Vietnam", sans-serif;
            border: 1px solid #e6e0d5;
        }

        /* ---- Sections ---- */
        .protein-calc-wrapper .pc-section {
            padding: 28px 30px;
            background: #fff;
        }

        .protein-calc-wrapper .pc-section-hd {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 22px;
            padding-bottom: 14px;
            border-bottom: 1px solid #e6e0d5;
        }

        .protein-calc-wrapper .pc-step-badge {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--product-nuocepkytu-light-green, #00843d);
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            font-family: "Oswald", sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            line-height: 1;
        }

        .protein-calc-wrapper .pc-section-title {
            font-family: "Oswald", sans-serif;
            font-size: 18px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--default-color-dark-brown, #2e1203);
            margin: 0;
            line-height: 1.2;
        }

        /* ---- Animations ---- */
        @keyframes pcBounceDown {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(5px); }
        }

        @keyframes pcFadeSlideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ---- Section connectors ---- */
        .protein-calc-wrapper .pc-connector {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 16px;
            background: var(--default-color-beige, #f7f6f2);
            border-top: 1px solid #e6e0d5;
            border-bottom: 1px solid #e6e0d5;
            gap: 3px;
            text-align: center;
            transition: background .15s;
        }

        .protein-calc-wrapper .pc-connector-arrow {
            font-size: 13px;
            color: #ccc;
            line-height: 1;
            display: inline-block;
        }

        .protein-calc-wrapper .pc-connector-label {
            font-size: 12px;
            color: #bbb;
            font-style: italic;
        }

        .protein-calc-wrapper .pc-connector.is-active {
            cursor: pointer;
        }

        .protein-calc-wrapper .pc-connector.is-active .pc-connector-arrow {
            color: var(--product-nuocepkytu-light-green, #00843d);
            animation: pcBounceDown 1.1s ease-in-out infinite;
        }

        .protein-calc-wrapper .pc-connector.is-active .pc-connector-label {
            color: var(--product-nuocepkytu-light-green, #00843d);
            font-style: normal;
            font-weight: 500;
        }

        .protein-calc-wrapper .pc-connector.is-active:hover {
            background: #ede8df;
        }

        /* ---- Visibility ---- */
        .protein-calc-wrapper .pc-followup-section {
            display: none;
        }

        .protein-calc-wrapper .pc-followup-section.is-visible {
            display: block;
            animation: pcFadeSlideIn .35s ease;
        }

        /* ---- Form ---- */
        .protein-calc-wrapper #proteinCalcForm {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0 20px;
        }

        .protein-calc-wrapper .pc-form-group {
            margin-bottom: 16px;
        }

        .protein-calc-wrapper .pc-form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--default-color-dark-brown, #2e1203);
            font-size: 14px;
        }

        .protein-calc-wrapper .pc-form-control {
            width: 100% !important;
            height: 46px;
            padding: 0 12px;
            border: 1px solid #ccc;
            border-radius: 0;
            font-size: 15px;
            font-family: "Be Vietnam", sans-serif;
            background: #fff;
            color: var(--default-color-black, #323232);
            box-sizing: border-box;
            transition: border-color .15s;
        }

        .protein-calc-wrapper .pc-form-control:focus {
            outline: none;
            border-color: var(--product-nuocepkytu-light-green, #00843d);
        }

        .protein-calc-wrapper .pc-btn {
            width: 100%;
            cursor: pointer;
            font-size: 16px;
            grid-column: 1 / -1;
            min-height: 48px;
            margin-top: 4px;
        }

        /* ---- Result box ---- */
        .protein-calc-wrapper .pc-result-box {
            margin-top: 24px;
            padding: 20px;
            background: var(--default-color-beige, #f7f6f2);
            border-radius: 4px;
            display: none;
            border: 1px solid #e6e0d5;
        }

        .protein-calc-wrapper .pc-result-header {
            font-weight: 500;
            color: var(--default-color-dark-brown, #2e1203);
            margin-bottom: 16px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-family: "Oswald", sans-serif;
        }

        .protein-calc-wrapper .pc-result-item {
            margin-bottom: 14px;
            padding: 14px;
            background: #fff;
            border-radius: 4px;
            border: 1px solid #e6e0d5;
        }

        .protein-calc-wrapper .pc-result-item:last-child {
            margin-bottom: 0;
        }

        .protein-calc-wrapper .pc-label {
            font-weight: 600;
            display: block;
            margin-bottom: 4px;
            color: #555;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .protein-calc-wrapper .pc-val {
            font-weight: 700;
            color: var(--product-nuocepkytu-light-green, #00843d);
            font-size: 22px;
            display: block;
            margin-bottom: 6px;
            font-family: "Oswald", sans-serif;
        }

        .protein-calc-wrapper .pc-note {
            font-size: 13px;
            color: #777;
            line-height: 1.5;
            margin-top: 4px;
        }

        .protein-calc-wrapper .pc-note a {
            color: var(--default-color-dark-brown, #2e1203);
            text-decoration: underline;
        }

        /* ---- Diet intro ---- */
        .protein-calc-wrapper .pc-followup-intro {
            margin: 0 0 20px;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        /* ---- Diet entries list ---- */
        .protein-calc-wrapper .pc-diet-entries-list {
            margin-bottom: 0;
        }

        .protein-calc-wrapper .pc-diet-entry {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 0;
            border-bottom: 1px solid #e6e0d5;
            font-size: 14px;
        }

        .protein-calc-wrapper .pc-diet-entry-info {
            flex: 1;
            color: var(--default-color-black, #323232);
        }

        .protein-calc-wrapper .pc-diet-entry-protein {
            color: var(--product-nuocepkytu-light-green, #00843d);
            font-weight: 600;
            white-space: nowrap;
            font-size: 13px;
        }

        .protein-calc-wrapper .pc-diet-remove {
            background: none;
            border: none;
            color: #ccc;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
            padding: 0 2px;
            min-height: auto;
            flex-shrink: 0;
        }

        .protein-calc-wrapper .pc-diet-remove:hover {
            color: var(--default-color-red, #bf1f33);
        }

        /* ---- Diet summary ---- */
        .protein-calc-wrapper .pc-diet-summary {
            margin-top: 14px;
            padding: 16px;
            background: var(--default-color-beige, #f7f6f2);
            border-radius: 4px;
            border: 1px solid #e6e0d5;
        }

        .protein-calc-wrapper .pc-diet-summary .pc-val {
            font-size: 20px;
            margin-bottom: 8px;
        }

        /* Comparison status rows */
        .protein-calc-wrapper #pcDietCompareVN,
        .protein-calc-wrapper #pcDietCompareUS {
            font-style: normal;
            font-size: 13px;
            color: var(--default-color-black, #323232);
            margin-top: 5px;
            line-height: 1.6;
        }

        /* Status chips */
        .protein-calc-wrapper .pc-chip {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 2px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            vertical-align: middle;
            line-height: 1.9;
        }

        .protein-calc-wrapper .pc-chip--deficit {
            background: var(--default-color-red, #bf1f33);
            color: #fff;
        }

        .protein-calc-wrapper .pc-chip--excess {
            background: var(--default-color-yellow, #fbb917);
            color: #4a2d00;
        }

        .protein-calc-wrapper .pc-chip--ok {
            background: var(--product-nuocepkytu-light-green, #00843d);
            color: #fff;
        }

        /* Disclaimer */
        .protein-calc-wrapper .pc-disclaimer {
            margin-top: 10px;
            padding: 8px 0 0;
            border-top: 1px solid #e6e0d5;
            color: #aaa;
            font-style: normal;
            font-size: 12px;
            line-height: 1.5;
        }

        /* ---- Add food form ---- */
        .protein-calc-wrapper .pc-diet-add-form {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #e6e0d5;
        }

        .protein-calc-wrapper .pc-diet-add-form > label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--default-color-dark-brown, #2e1203);
            margin-bottom: 8px;
        }

        .protein-calc-wrapper .pc-food-input-area {
            margin-top: 10px;
            padding: 14px;
            background: var(--default-color-beige, #f7f6f2);
            border-radius: 4px;
            border: 1px solid #e6e0d5;
        }

        .protein-calc-wrapper .pc-food-selected-name {
            display: block;
            font-weight: 600;
            color: var(--default-color-dark-brown, #2e1203);
            margin-bottom: 3px;
            font-size: 14px;
        }

        .protein-calc-wrapper .pc-food-meta {
            display: block;
            color: #888;
            font-size: 12px;
            margin-bottom: 10px;
            line-height: 1.5;
        }

        .protein-calc-wrapper .pc-food-input-row {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .protein-calc-wrapper .pc-food-input-row .pc-form-control {
            flex: 1;
            min-width: 0;
            width: auto !important;
        }

        .protein-calc-wrapper .pc-food-unit {
            color: #666;
            font-size: 14px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .protein-calc-wrapper .pc-add-food-btn {
            white-space: nowrap;
            min-height: 46px;
            padding: 0 20px;
            flex-shrink: 0;
        }

        /* ---- Reference list ---- */
        .protein-calc-wrapper .pc-reference-list {
            margin-top: 20px;
            font-size: 12px;
            color: #aaa;
            line-height: 1.7;
            padding-top: 16px;
            border-top: 1px solid #e6e0d5;
        }

        .protein-calc-wrapper .pc-reference-list a {
            color: var(--default-color-dark-brown, #2e1203);
            text-decoration: underline;
        }

        /* ---- Products ---- */
        .protein-calc-wrapper .pc-product-slot {
            display: none;
        }

        .protein-calc-wrapper .pc-product-slot.is-active {
            display: block;
        }

        /* ---- Responsive ---- */
        @media (max-width: 767px) {
            .protein-calc-wrapper {
                max-width: 600px;
            }

            .protein-calc-wrapper .pc-section {
                padding: 20px;
            }

            .protein-calc-wrapper #proteinCalcForm {
                grid-template-columns: 1fr;
            }

            .protein-calc-wrapper .pc-food-input-row {
                flex-wrap: wrap;
            }

            .protein-calc-wrapper .pc-add-food-btn {
                width: 100%;
            }
        }

        @media (min-width: 1024px) {
            .protein-calc-wrapper .pc-section {
                padding: 36px 42px;
            }

            .protein-calc-wrapper .pc-section-title {
                font-size: 22px;
            }

            .protein-calc-wrapper .pc-form-group label {
                font-size: 15px;
            }

            .protein-calc-wrapper .pc-form-control {
                height: 52px;
                font-size: 16px;
            }

            .protein-calc-wrapper .pc-val {
                font-size: 26px;
            }
        }
    </style>

    <div class="protein-calc-wrapper">

        <section class="pc-section">
        <div class="pc-section-hd">
            <span class="pc-step-badge">1</span>
            <h3 class="pc-section-title">Tính Nhu Cầu Protein Của Bạn</h3>
        </div>
        <form id="proteinCalcForm">
            <div class="pc-form-group">
                <label>Giới tính</label>
                <select class="pc-form-control" id="pc_gender">
                    <option value="male">Nam</option>
                    <option value="female">Nữ</option>
                </select>
            </div>
            <div class="pc-form-group">
                <label>Độ tuổi</label>
                <input type="number" class="pc-form-control" id="pc_age" placeholder="Ví dụ: 25" required min="1" max="120">
            </div>
            <div class="pc-form-group">
                <label>Cân nặng (kg)</label>
                <input type="number" class="pc-form-control" id="pc_weight" placeholder="Ví dụ: 60" required min="10" step="0.1">
            </div>
            <div class="pc-form-group">
                <label>Mức độ vận động</label>
                <select class="pc-form-control" id="pc_activity">
                    <option value="sedentary">Ít vận động (Làm văn phòng, ít tập)</option>
                    <option value="light">Vận động nhẹ (Tập 1-3 ngày/tuần)</option>
                    <option value="moderate">Vận động vừa (Tập 3-5 ngày/tuần)</option>
                    <option value="active">Vận động nhiều (Tập 6-7 ngày/tuần)</option>
                    <option value="very_active">Rất nhiều (VĐV, lao động nặng)</option>
                </select>
            </div>
            <div class="pc-form-group" id="pc_condition_group">
                <label>Tình trạng đặc biệt</label>
                <select class="pc-form-control" id="pc_condition">
                    <option value="normal">Không mang thai</option>
                    <option value="pregnant_t1">Mẹ bầu 3 tháng đầu</option>
                    <option value="pregnant_t2">Mẹ bầu 3 tháng giữa</option>
                    <option value="pregnant_t3">Mẹ bầu 3 tháng cuối</option>
                </select>
            </div>
            <button type="submit" class="button button--nuocepkytu-light-green pc-btn">TÍNH NGAY</button>
        </form>

        <div id="pcResult" class="pc-result-box">
            <div class="pc-result-header">Kết Quả Của Bạn</div>

            <div class="pc-result-item">
                <span class="pc-label">Mức phù hợp cho người Việt (tham chiếu WHO)</span>
                <span class="pc-val" id="res_vn"></span>
                <div class="pc-note" id="res_vn_activity_note"></div>
                <div class="pc-note" id="res_vn_age_note"></div>
                <div class="pc-note" id="res_vn_condition_note"></div>
                <div class="pc-note" id="res_vn_ref_note"></div>
            </div>

            <div class="pc-result-item">
                <span class="pc-label">Khuyến nghị mới — Mỹ 2026–2030</span>
                <span class="pc-val" id="res_us"></span>
                <div class="pc-note" id="res_us_goal_note"></div>
                <div class="pc-note" id="res_us_age_note"></div>
                <div class="pc-note" id="res_us_condition_note"></div>
                <div class="pc-note" id="res_meal_note"></div>
                <div class="pc-note" id="res_us_ref_note"></div>
            </div>
        </div>
        </section>

        <div class="pc-connector" id="pcConnector12" data-target="pcDietEstimator">
            <span class="pc-connector-arrow">↓</span>
            <span class="pc-connector-label">Tính xong → ước tính protein từ khẩu phần ăn của bạn</span>
        </div>

        <section id="pcDietEstimator" class="pc-section pc-followup-section">
        <div class="pc-section-hd">
            <span class="pc-step-badge">2</span>
            <h3 class="pc-section-title">Ước Tính Protein Từ Khẩu Phần Ăn</h3>
        </div>
            <p class="pc-followup-intro">
                Sau khi biết nhu cầu protein mỗi ngày, bạn có thể tự nhập lượng thực phẩm mình ăn trong 1 ngày để ước tính lượng protein đang nạp.
                Đây là công cụ ước tính nhanh dựa trên dữ liệu thành phần thực phẩm phổ biến; nên đối chiếu thêm với nhãn dinh dưỡng của sản phẩm bạn thực tế sử dụng.
            </p>

            <div id="pcDietEntriesList" class="pc-diet-entries-list"></div>

            <div class="pc-diet-summary">
                <span class="pc-label">Tổng protein ước tính</span>
                <span class="pc-val" id="pcDietTotal">0g / ngày</span>
                <div id="pcDietCompareVN"></div>
                <div id="pcDietCompareUS"></div>
                <div class="pc-disclaimer" id="pcDietAccuracyNote">Đây là ước tính nhanh dựa trên giá trị trung bình. Hàm lượng thực tế có thể khác tùy thương hiệu, cách chế biến và khẩu phần thực tế.</div>
            </div>

            <div class="pc-diet-add-form">
                <label for="pcFoodSelect">Thêm thực phẩm vào khẩu phần</label>
                <select id="pcFoodSelect" class="pc-form-control">
                    <option value="">+ Chọn thực phẩm để thêm...</option>
                    <option value="eggs">Trứng gà</option>
                    <option value="chicken">Ức gà chín</option>
                    <option value="fish">Cá chín</option>
                    <option value="beef">Thịt bò nạc chín</option>
                    <option value="pork">Thịt heo nạc chín</option>
                    <option value="shrimp">Tôm chín</option>
                    <option value="milk">Sữa</option>
                    <option value="soymilk">Sữa đậu nành</option>
                    <option value="yogurt">Sữa chua Greek</option>
                    <option value="tofu">Đậu phụ cứng</option>
                    <option value="beans">Đậu, đỗ, đậu lăng đã nấu</option>
                    <option value="peanuts">Đậu phộng / lạc rang</option>
                    <option value="whey">Whey protein</option>
                    <option value="custom">Protein từ món khác</option>
                </select>
                <div id="pcFoodInputArea" class="pc-food-input-area" style="display:none">
                    <strong id="pcSelectedFoodName" class="pc-food-selected-name"></strong>
                    <span id="pcSelectedFoodMeta" class="pc-food-meta"></span>
                    <div class="pc-food-input-row">
                        <input type="number" id="pcFoodAmountInput" class="pc-form-control" min="0" placeholder="Nhập lượng...">
                        <span id="pcFoodUnit" class="pc-food-unit"></span>
                        <button type="button" id="pcAddFoodBtn" class="button button--nuocepkytu-light-green pc-add-food-btn">Thêm</button>
                    </div>
                </div>
            </div>

            <div class="pc-reference-list">
                Tài liệu đối chiếu:
                <br>1. <a href="https://fdc.nal.usda.gov/" target="_blank" rel="noopener noreferrer">USDA FoodData Central</a> - cơ sở dữ liệu thành phần dinh dưỡng để kiểm tra protein của từng thực phẩm.
                <br>2. <a href="https://www.myplate.gov/eathealthy/protein-foods/protein-foods-nutrients-health?post=08132019a" target="_blank" rel="noopener noreferrer">USDA MyPlate - Protein Foods Group</a> - quy đổi khẩu phần household measures như 1 quả trứng, 1/4 cup đậu, 1 ounce thịt/cá.
                <br>3. <a href="https://www.fda.gov/food/nutrition-facts-label/how-understand-and-use-nutrition-facts-label" target="_blank" rel="noopener noreferrer">FDA - How to Understand and Use the Nutrition Facts Label</a> - dùng để đối chiếu thực phẩm đóng gói vì công thức/nhãn từng hãng có thể lệch so với giá trị trung bình.
            </div>
        </section>

        <div class="pc-connector pc-followup-section" id="pcConnector23" data-target="pcProductsSection">
            <span class="pc-connector-arrow">↓</span>
            <span class="pc-connector-label">Thêm thực phẩm xong → xem gợi ý sản phẩm phù hợp</span>
        </div>

        <section id="pcProductsSection" class="pc-section pc-followup-section">
        <div class="pc-section-hd">
            <span class="pc-step-badge">3</span>
            <h3 class="pc-section-title">Gợi Ý Sản Phẩm Cho Bạn</h3>
        </div>
            <?php foreach ($product_ids_by_case as $case => $ids) : ?>
                <div class="pc-product-slot<?php echo $case === 'default' ? ' is-active' : ''; ?>" data-product-case="<?php echo esc_attr($case); ?>">
                    <?php
                    if ($ids !== '') {
                        echo do_shortcode(sprintf('[products ids="%s" columns="%d"]', esc_attr($ids), $product_columns));
                    }
                    ?>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var form = document.getElementById('proteinCalcForm');
            if (!form) return;

            var genderEl      = document.getElementById('pc_gender');
            var conditionEl   = document.getElementById('pc_condition');
            var conditionGroup = document.getElementById('pc_condition_group');
            var activityEl    = document.getElementById('pc_activity');
            var productSlots  = document.querySelectorAll('.pc-product-slot');
            var dietEstimator    = document.getElementById('pcDietEstimator');
            var productsSection  = document.getElementById('pcProductsSection');
            var dietTotalEl      = document.getElementById('pcDietTotal');
            var dietCompareVNEl  = document.getElementById('pcDietCompareVN');
            var dietCompareUSEl  = document.getElementById('pcDietCompareUS');
            var lastProteinTarget = null;

            function showProductsSection() {
                if (productsSection && dietEntries.length > 0) {
                    productsSection.classList.add('is-visible');
                    var conn23 = document.getElementById('pcConnector23');
                    if (conn23) { conn23.classList.add('is-visible'); conn23.classList.add('is-active'); }
                }
            }

            // Click connector → scroll to target section
            document.querySelectorAll('.protein-calc-wrapper .pc-connector').forEach(function(conn) {
                conn.addEventListener('click', function() {
                    if (!this.classList.contains('is-active')) return;
                    var targetId = this.getAttribute('data-target');
                    if (!targetId) return;
                    var target = document.getElementById(targetId);
                    if (target && target.classList.contains('is-visible')) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });

            // ---- Food data ----
            var FOOD_DATA = [
                { id: 'eggs',    label: 'Trứng gà',                  meta: 'Quy đổi: 1 quả lớn luộc/chín ≈ 6.3g protein',    unit: 'quả',     protein: 6.3,  base: 1,   step: 1,   placeholder: 'Ví dụ: 2' },
                { id: 'chicken', label: 'Ức gà chín',                 meta: 'Quy đổi: 100g ≈ 31g protein',                    unit: 'g',       protein: 31,   base: 100, step: 10,  placeholder: 'Ví dụ: 150' },
                { id: 'fish',    label: 'Cá chín',                    meta: 'Quy đổi: 100g ≈ 22g protein',                    unit: 'g',       protein: 22,   base: 100, step: 10,  placeholder: 'Ví dụ: 120' },
                { id: 'beef',    label: 'Thịt bò nạc chín',           meta: 'Quy đổi: 100g ≈ 26g protein',                    unit: 'g',       protein: 26,   base: 100, step: 10,  placeholder: 'Ví dụ: 100' },
                { id: 'pork',    label: 'Thịt heo nạc chín',          meta: 'Quy đổi: 100g ≈ 27g protein',                    unit: 'g',       protein: 27,   base: 100, step: 10,  placeholder: 'Ví dụ: 100' },
                { id: 'shrimp',  label: 'Tôm chín',                   meta: 'Quy đổi: 100g ≈ 24g protein',                    unit: 'g',       protein: 24,   base: 100, step: 10,  placeholder: 'Ví dụ: 120' },
                { id: 'milk',    label: 'Sữa',                        meta: 'Quy đổi: 240ml ≈ 8g protein',                    unit: 'ml',      protein: 8,    base: 240, step: 50,  placeholder: 'Ví dụ: 240' },
                { id: 'soymilk', label: 'Sữa đậu nành',               meta: 'Quy đổi: 240ml ≈ 7g protein',                    unit: 'ml',      protein: 7,    base: 240, step: 50,  placeholder: 'Ví dụ: 240' },
                { id: 'yogurt',  label: 'Sữa chua Greek',             meta: 'Quy đổi: 1 hũ 170g ≈ 17g protein',              unit: 'hũ',      protein: 17,   base: 1,   step: 1,   placeholder: 'Ví dụ: 1' },
                { id: 'tofu',    label: 'Đậu phụ cứng',               meta: 'Quy đổi: 100g ≈ 14g protein',                    unit: 'g',       protein: 14,   base: 100, step: 10,  placeholder: 'Ví dụ: 200' },
                { id: 'beans',   label: 'Đậu, đỗ, đậu lăng đã nấu',  meta: 'Quy đổi: 100g ≈ 9g protein',                     unit: 'g',       protein: 9,    base: 100, step: 10,  placeholder: 'Ví dụ: 150' },
                { id: 'peanuts', label: 'Đậu phộng / lạc rang',       meta: 'Quy đổi: 30g ≈ 7g protein',                      unit: 'g',       protein: 7,    base: 30,  step: 10,  placeholder: 'Ví dụ: 30' },
                { id: 'whey',    label: 'Whey protein',               meta: 'Quy đổi: 1 muỗng chuẩn ≈ 24g protein',           unit: 'muỗng',   protein: 24,   base: 1,   step: 0.5, placeholder: 'Ví dụ: 1' },
                { id: 'custom',  label: 'Protein từ món khác',        meta: 'Tự nhập trực tiếp gram protein từ nhãn sản phẩm', unit: 'g protein', protein: 1, base: 1,   step: 1,   placeholder: 'Ví dụ: 12' }
            ];

            // ---- Diet entries ----
            var dietEntries = [];

            function escHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function saveDietEntries() {
                localStorage.setItem('protein_calculator_diet_entries', JSON.stringify(dietEntries));
            }

            function getDietTotal() {
                var total = 0;
                dietEntries.forEach(function(e) { total += e.protein || 0; });
                return Math.round(total * 10) / 10;
            }

            function syncDropdownDisabled() {
                if (!foodSelectEl) return;
                var usedIds = {};
                dietEntries.forEach(function(e) { if (e.foodId !== 'custom') usedIds[e.foodId] = true; });
                foodSelectEl.querySelectorAll('option[value]').forEach(function(opt) {
                    if (!opt.value || opt.value === 'custom') return;
                    opt.disabled = !!usedIds[opt.value];
                });
            }

            function renderDietEntries() {
                var listEl = document.getElementById('pcDietEntriesList');
                if (!listEl) return;
                if (!dietEntries.length) { listEl.innerHTML = ''; syncDropdownDisabled(); return; }
                var html = '';
                dietEntries.forEach(function(entry, idx) {
                    var pVal = Math.round((entry.protein || 0) * 10) / 10;
                    html += '<div class="pc-diet-entry">'
                        + '<span class="pc-diet-entry-info">' + escHtml(entry.label) + ': ' + escHtml(String(entry.amount)) + '\u00a0' + escHtml(entry.unit) + '</span>'
                        + '<span class="pc-diet-entry-protein">\u2248\u00a0' + pVal + 'g protein</span>'
                        + '<button type="button" class="pc-diet-remove" data-idx="' + idx + '" aria-label="Xo\u00e1">\u00d7</button>'
                        + '</div>';
                });
                listEl.innerHTML = html;
                listEl.querySelectorAll('.pc-diet-remove').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        dietEntries.splice(parseInt(this.getAttribute('data-idx'), 10), 1);
                        saveDietEntries();
                        renderDietEntries();
                        updateDietEstimatorSummary();
                    });
                });
                syncDropdownDisabled();
            }

            function pcChip(type, text) {
                return '<span class="pc-chip pc-chip--' + type + '">' + text + '</span>';
            }

            function updateDietEstimatorSummary() {
                if (!dietTotalEl) return;
                var total = getDietTotal();
                dietTotalEl.innerText = '\u2248\u00a0' + (total % 1 === 0 ? total : total.toFixed(1)) + 'g / ng\u00e0y';
                if (!dietCompareVNEl || !dietCompareUSEl) return;
                if (!lastProteinTarget) {
                    dietCompareVNEl.innerHTML = 'H\u00e3y t\u00ednh nhu c\u1ea7u protein tr\u01b0\u1edbc \u0111\u1ec3 so s\u00e1nh v\u1edbi kh\u1ea9u ph\u1ea7n hi\u1ec7n t\u1ea1i.';
                    dietCompareUSEl.innerHTML = '';
                    return;
                }
                // VN (WHO)
                if (total < lastProteinTarget.min) {
                    var vnMiss = Math.round((lastProteinTarget.min - total) * 10) / 10;
                    dietCompareVNEl.innerHTML = '<strong>VN/WHO</strong> ' + pcChip('deficit', 'Thi\u1ebfu ' + vnMiss + 'g') + ' \u2014 ch\u01b0a \u0111\u1ea1t t\u1ed1i thi\u1ec3u ' + lastProteinTarget.min + 'g/ng\u00e0y.';
                } else if (total > lastProteinTarget.max) {
                    var vnExcess = Math.round((total - lastProteinTarget.max) * 10) / 10;
                    dietCompareVNEl.innerHTML = '<strong>VN/WHO</strong> ' + pcChip('excess', 'V\u01b0\u1ee3t ' + vnExcess + 'g') + ' \u2014 tr\u00ean c\u1eadn tr\u00ean ' + lastProteinTarget.max + 'g/ng\u00e0y.';
                } else {
                    dietCompareVNEl.innerHTML = '<strong>VN/WHO</strong> ' + pcChip('ok', '\u0110\u1ea1t m\u1ee5c ti\u00eau \u2713') + ' ' + lastProteinTarget.min + '\u2013' + lastProteinTarget.max + 'g/ng\u00e0y.';
                }
                // US 2026-2030
                if (total < lastProteinTarget.usMin) {
                    var usMiss = Math.round((lastProteinTarget.usMin - total) * 10) / 10;
                    dietCompareUSEl.innerHTML = '<strong>M\u1ef9 2026\u20132030</strong> ' + pcChip('deficit', 'Thi\u1ebfu ' + usMiss + 'g') + ' \u2014 ch\u01b0a \u0111\u1ea1t t\u1ed1i thi\u1ec3u ' + lastProteinTarget.usMin + 'g/ng\u00e0y.';
                } else if (total > lastProteinTarget.usMax) {
                    var usExcess = Math.round((total - lastProteinTarget.usMax) * 10) / 10;
                    dietCompareUSEl.innerHTML = '<strong>M\u1ef9 2026\u20132030</strong> ' + pcChip('excess', 'V\u01b0\u1ee3t ' + usExcess + 'g') + ' \u2014 tr\u00ean c\u1eadn tr\u00ean ' + lastProteinTarget.usMax + 'g/ng\u00e0y.';
                } else {
                    dietCompareUSEl.innerHTML = '<strong>M\u1ef9 2026\u20132030</strong> ' + pcChip('ok', '\u0110\u1ea1t m\u1ee5c ti\u00eau \u2713') + ' ' + lastProteinTarget.usMin + '\u2013' + lastProteinTarget.usMax + 'g/ng\u00e0y.';
                }
            }

            // ---- Food select UI ----
            var foodSelectEl      = document.getElementById('pcFoodSelect');
            var foodInputAreaEl   = document.getElementById('pcFoodInputArea');
            var selectedFoodNameEl = document.getElementById('pcSelectedFoodName');
            var selectedFoodMetaEl = document.getElementById('pcSelectedFoodMeta');
            var foodAmountInputEl = document.getElementById('pcFoodAmountInput');
            var foodUnitEl        = document.getElementById('pcFoodUnit');
            var addFoodBtnEl      = document.getElementById('pcAddFoodBtn');
            var currentFoodItem   = null;

            function addFoodEntry() {
                if (!currentFoodItem) return;
                var amount = parseFloat(foodAmountInputEl.value);
                if (!amount || amount <= 0) { foodAmountInputEl.focus(); return; }
                var protein = Math.round((amount / currentFoodItem.base) * currentFoodItem.protein * 10) / 10;
                dietEntries.push({ foodId: currentFoodItem.id, label: currentFoodItem.label, amount: amount, unit: currentFoodItem.unit, protein: protein });
                saveDietEntries();
                renderDietEntries();
                updateDietEstimatorSummary();
                showProductsSection();
                foodAmountInputEl.value = '';
                foodSelectEl.value = '';
                foodInputAreaEl.style.display = 'none';
                currentFoodItem = null;
                foodSelectEl.focus();
            }

            if (foodSelectEl) {
                foodSelectEl.addEventListener('change', function() {
                    var selectedId = this.value;
                    if (!selectedId) { foodInputAreaEl.style.display = 'none'; currentFoodItem = null; return; }
                    var food = null;
                    for (var i = 0; i < FOOD_DATA.length; i++) { if (FOOD_DATA[i].id === selectedId) { food = FOOD_DATA[i]; break; } }
                    if (!food) return;
                    currentFoodItem = food;
                    selectedFoodNameEl.innerText = food.label;
                    selectedFoodMetaEl.innerText = food.meta;
                    foodAmountInputEl.placeholder = food.placeholder;
                    foodAmountInputEl.step = food.step;
                    foodUnitEl.innerText = food.unit;
                    foodInputAreaEl.style.display = '';
                    foodAmountInputEl.value = '';
                    foodAmountInputEl.focus();
                });
            }

            if (foodAmountInputEl) {
                foodAmountInputEl.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); addFoodEntry(); }
                });
            }

            if (addFoodBtnEl) {
                addFoodBtnEl.addEventListener('click', addFoodEntry);
            }

            // Load saved diet entries
            var savedEntries = localStorage.getItem('protein_calculator_diet_entries');
            if (savedEntries) {
                try {
                    var parsed = JSON.parse(savedEntries);
                    if (Array.isArray(parsed)) dietEntries = parsed;
                } catch (e) {}
            }
            renderDietEntries();
            updateDietEstimatorSummary();
            showProductsSection();

            // ---- Products ----
            function updateSuggestedProducts() {
                if (!productSlots.length || !genderEl || !conditionEl || !activityEl) return;
                var selectedCase = activityEl.value || 'default';
                if (genderEl.value === 'female' && conditionEl.value && conditionEl.value !== 'normal') {
                    selectedCase = conditionEl.value;
                }
                var hasActiveCase = false;
                productSlots.forEach(function(slot) {
                    var isActive = slot.getAttribute('data-product-case') === selectedCase;
                    slot.classList.toggle('is-active', isActive);
                    if (isActive) hasActiveCase = true;
                });
                if (!hasActiveCase) {
                    productSlots.forEach(function(slot) {
                        slot.classList.toggle('is-active', slot.getAttribute('data-product-case') === 'default');
                    });
                }
            }

            function syncActivityField() {
                if (!genderEl || !conditionEl || !activityEl) return;
                var isPregnant = genderEl.value === 'female' && conditionEl.value && conditionEl.value !== 'normal';
                if (isPregnant) { activityEl.value = 'sedentary'; activityEl.disabled = true; return; }
                activityEl.disabled = false;
            }

            function toggleConditionField() {
                if (!genderEl || !conditionEl || !conditionGroup) return;
                if (genderEl.value === 'female') {
                    conditionGroup.style.display = '';
                    syncActivityField();
                    updateSuggestedProducts();
                    return;
                }
                conditionGroup.style.display = 'none';
                conditionEl.value = 'normal';
                syncActivityField();
                updateSuggestedProducts();
            }

            // LOCAL STORAGE: LOAD form values
            var saved = localStorage.getItem('protein_calculator_data');
            if (saved) {
                try {
                    var data = JSON.parse(saved);
                    if (data.gender)    document.getElementById('pc_gender').value    = data.gender;
                    if (data.age)       document.getElementById('pc_age').value       = data.age;
                    if (data.weight)    document.getElementById('pc_weight').value    = data.weight;
                    if (data.activity)  document.getElementById('pc_activity').value  = data.activity;
                    if (data.condition) document.getElementById('pc_condition').value = data.condition;
                    if (data.weight && data.age) {
                        setTimeout(function() { var btn = form.querySelector('button[type="submit"]'); if (btn) btn.click(); }, 50);
                    }
                } catch (e) { console.error('L\u1ed7i khi t\u1ea3i d\u1eef li\u1ec7u \u0111\u00e3 l\u01b0u:', e); }
            }

            toggleConditionField();
            syncActivityField();

            if (genderEl)    genderEl.addEventListener('change', toggleConditionField);
            if (conditionEl) conditionEl.addEventListener('change', function() { syncActivityField(); updateSuggestedProducts(); });
            if (activityEl)  activityEl.addEventListener('change', updateSuggestedProducts);

            updateSuggestedProducts();

            // ---- Main form submit ----
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                var weight    = parseFloat(document.getElementById('pc_weight').value);
                var activity  = document.getElementById('pc_activity').value;
                var condition = document.getElementById('pc_condition').value;
                var age       = parseInt(document.getElementById('pc_age').value, 10);
                var gender    = document.getElementById('pc_gender').value;

                if (gender === 'female' && condition !== 'normal') {
                    activity = 'sedentary';
                    document.getElementById('pc_activity').value = activity;
                }
                if (!weight || weight <= 0) return;

                localStorage.setItem('protein_calculator_data', JSON.stringify({ gender: gender, age: age, weight: weight, activity: activity, condition: condition }));

                var activityMap = {
                    sedentary:   { vnMin: 0.8, vnMax: 1.0, label: '\u00CDt v\u1eadn \u0111\u1ed9ng',   detail: 'N\u00ean duy tr\u00ec \u0111\u1ea1m \u1edf m\u1ee9c c\u01a1 b\u1ea3n \u0111\u1ec3 b\u1ea3o to\u00e0n kh\u1ed1i c\u01a1 v\u00e0 ph\u1ee5c h\u1ed3i h\u1eb1ng ng\u00e0y.' },
                    light:       { vnMin: 1.0, vnMax: 1.2, label: 'V\u1eadn \u0111\u1ed9ng nh\u1eb9',   detail: 'Nhu c\u1ea7u \u0111\u1ea1m t\u0103ng nh\u1eb9 \u0111\u1ec3 h\u1ed7 tr\u1ee3 ph\u1ee5c h\u1ed3i sau c\u00e1c bu\u1ed5i t\u1eadp/\u0111i b\u1ed9 nh\u1eb9.' },
                    moderate:    { vnMin: 1.2, vnMax: 1.4, label: 'V\u1eadn \u0111\u1ed9ng v\u1eeba',   detail: 'M\u1ee9c ph\u00f9 h\u1ee3p cho ng\u01b0\u1eddi t\u1eadp \u0111\u1ec1u \u0111\u1eb7n 3-5 bu\u1ed5i/tu\u1ea7n, c\u1ea7n ph\u1ee5c h\u1ed3i c\u01a1 t\u1ed1t h\u01a1n.' },
                    active:      { vnMin: 1.4, vnMax: 1.6, label: 'V\u1eadn \u0111\u1ed9ng nhi\u1ec1u',  detail: 'Ph\u00f9 h\u1ee3p ng\u01b0\u1eddi t\u1eadp n\u1eb7ng, c\u1ea7n t\u0103ng \u0111\u1ea1m \u0111\u1ec3 duy tr\u00ec v\u00e0 ph\u00e1t tri\u1ec3n kh\u1ed1i c\u01a1.' },
                    very_active: { vnMin: 1.6, vnMax: 1.8, label: 'R\u1ea5t nhi\u1ec1u',              detail: 'D\u00e0nh cho v\u1eadn \u0111\u1ed9ng vi\u00ean ho\u1eb7c lao \u0111\u1ed9ng th\u1ec3 l\u1ef1c cao, nhu c\u1ea7u ph\u1ee5c h\u1ed3i r\u1ea5t l\u1edbn.' }
                };
                var activityInfo = activityMap[activity] || activityMap.sedentary;
                var vn_min = activityInfo.vnMin;
                var vn_max = activityInfo.vnMax;
                var vnAgeDetail = '', usAgeDetail = '';
                var vnConditionDetail = 'Kh\u00f4ng c\u00f3 \u0111i\u1ec1u ch\u1ec9nh \u0111\u1eb7c bi\u1ec7t.';
                var usConditionDetail = 'Kh\u00f4ng c\u00f3 \u0111i\u1ec1u ch\u1ec9nh \u0111\u1eb7c bi\u1ec7t.';

                if (age < 18) {
                    vn_min = Math.max(vn_min, 1.0); vn_max = Math.max(vn_max, 1.3);
                    vnAgeDetail = 'Nh\u00f3m d\u01b0\u1edbi 18 tu\u1ed5i: \u01b0u ti\u00ean \u0111\u1ea1m ch\u1ea5t l\u01b0\u1ee3ng \u0111\u1ec3 h\u1ed7 tr\u1ee3 t\u0103ng tr\u01b0\u1edfng, kh\u00f4ng n\u00ean c\u1eaft gi\u1ea3m \u0111\u1ea1m qu\u00e1 th\u1ea5p.';
                    usAgeDetail = 'Khuy\u1ebfn ngh\u1ecb M\u1ef9 ch\u1ee7 y\u1ebfu \u00e1p d\u1ee5ng cho ng\u01b0\u1eddi tr\u01b0\u1edfng th\u00e0nh, v\u1edbi tr\u1ebb em c\u1ea7n theo h\u01b0\u1edbng d\u1eabn ri\u00eang theo tu\u1ed5i.';
                } else if (age <= 49) {
                    vnAgeDetail = 'Nh\u00f3m 18-49 tu\u1ed5i: \u00e1p d\u1ee5ng tr\u1ef1c ti\u1ebfp theo m\u1ee9c v\u1eadn \u0111\u1ed9ng \u0111\u1ec3 duy tr\u00ec s\u1ee9c kh\u1ecfe v\u00e0 th\u00e0nh ph\u1ea7n c\u01a1 th\u1ec3.';
                    usAgeDetail = 'Nh\u00f3m tr\u01b0\u1edfng th\u00e0nh: c\u00f3 th\u1ec3 d\u00f9ng m\u1ee9c 1.2-1.6g/kg khi c\u1ea7n t\u1ed1i \u01b0u c\u01a1 b\u1eafp ho\u1eb7c ki\u1ec3m so\u00e1t c\u00e2n n\u1eb7ng.';
                } else if (age <= 64) {
                    vn_min += 0.1; vn_max += 0.2;
                    vnAgeDetail = 'Nh\u00f3m 50-64 tu\u1ed5i: t\u0103ng nh\u1eb9 \u0111\u1ea1m \u0111\u1ec3 h\u1ed7 tr\u1ee3 ch\u1ed1ng m\u1ea5t c\u01a1 li\u00ean quan tu\u1ed5i t\u00e1c.';
                    usAgeDetail = 'Tu\u1ed5i trung ni\u00ean: c\u00f3 th\u1ec3 \u01b0u ti\u00ean m\u1ee9c g\u1ea7n c\u1eadn tr\u00ean khi v\u1eadn \u0111\u1ed9ng th\u01b0\u1eddng xuy\u00ean ho\u1eb7c \u0111ang gi\u1ea3m m\u1ee1.';
                } else {
                    vn_min += 0.2; vn_max += 0.3;
                    vnAgeDetail = 'Nh\u00f3m t\u1eeb 65 tu\u1ed5i: n\u00ean t\u0103ng \u0111\u1ea1m v\u00e0 chia \u0111\u1ec1u trong ng\u00e0y \u0111\u1ec3 h\u1ed7 tr\u1ee3 v\u1eadn \u0111\u1ed9ng v\u00e0 h\u1ea1n ch\u1ebf suy gi\u1ea3m c\u01a1.';
                    usAgeDetail = 'Nh\u00f3m l\u1edbn tu\u1ed5i: n\u00ean d\u00f9ng m\u1ee9c cao v\u1eeba ph\u1ea3i, \u0111\u1ed3ng th\u1eddi theo d\u00f5i ch\u1ee9c n\u0103ng th\u1eadn v\u00e0 b\u1ec7nh n\u1ec1n.';
                }

                var us_min = 1.2, us_max = 1.6;
                if (activity === 'active') { us_max = 1.7; }
                else if (activity === 'very_active') { us_max = 1.8; }

                var pregnancyExtra = 0;
                if (condition !== 'normal' && gender === 'female') {
                    if (condition === 'pregnant_t1')      { pregnancyExtra = 1; }
                    else if (condition === 'pregnant_t2') { pregnancyExtra = 9; }
                    else if (condition === 'pregnant_t3') { pregnancyExtra = 31; }
                    vn_min = Math.max(vn_min, 1.1); vn_max = Math.max(vn_max, 1.3);
                    us_min = Math.max(us_min, 1.1); us_max = Math.max(us_max, 1.6);
                    vnConditionDetail = 'M\u1eb9 b\u1ea7u \u0111\u01b0\u1ee3c c\u1ed9ng th\u00eam ' + pregnancyExtra + 'g/ng\u00e0y theo tam c\u00e1 nguy\u1ec7t, \u0111\u1ed3ng th\u1eddi \u01b0u ti\u00ean ngu\u1ed3n \u0111\u1ea1m d\u1ec5 ti\u00eau v\u00e0 an to\u00e0n th\u1ef1c ph\u1ea9m.';
                    usConditionDetail = 'V\u1edbi thai k\u1ef3, n\u00ean \u01b0u ti\u00ean \u0111\u1ea1t \u00edt nh\u1ea5t ng\u01b0\u1ee1ng n\u1ec1n 1.1g/kg/ng\u00e0y v\u00e0 theo d\u00f5i t\u0103ng c\u00e2n thai k\u1ef3.';
                } else if (condition !== 'normal' && gender !== 'female') {
                    vnConditionDetail = 'B\u1ea1n ch\u1ecdn m\u1ee5c m\u1eb9 b\u1ea7u nh\u01b0ng gi\u1edbi t\u00ednh hi\u1ec7n t\u1ea1i kh\u00f4ng ph\u1ea3i n\u1eef, h\u1ec7 th\u1ed1ng kh\u00f4ng c\u1ed9ng th\u00eam thai k\u1ef3.';
                    usConditionDetail = vnConditionDetail;
                }

                var vn_total_min = Math.round(weight * vn_min + pregnancyExtra);
                var vn_total_max = Math.round(weight * vn_max + pregnancyExtra);
                var us_total_min = Math.round(weight * us_min + pregnancyExtra);
                var us_total_max = Math.round(weight * us_max + pregnancyExtra);
                var meal_min = Math.max(20, Math.round(us_total_min / 3));
                var meal_max = Math.max(meal_min + 4, Math.round(us_total_max / 3));

                lastProteinTarget = { min: vn_total_min, max: vn_total_max, usMin: us_total_min, usMax: us_total_max };

                document.getElementById('res_vn').innerText           = vn_total_min + ' - ' + vn_total_max + 'g / ng\u00e0y';
                document.getElementById('res_us').innerText           = us_total_min + ' - ' + us_total_max + 'g / ng\u00e0y';
                document.getElementById('res_vn_activity_note').innerText = activityInfo.label + ': ' + activityInfo.detail;
                document.getElementById('res_vn_age_note').innerText  = vnAgeDetail;
                document.getElementById('res_vn_condition_note').innerText = vnConditionDetail;
                document.getElementById('res_us_goal_note').innerText = 'M\u1ef9 2026-2030: 1.2g/kg cho duy tr\u00ec s\u1ee9c kh\u1ecfe v\u00e0 1.6g/kg (ho\u1eb7c cao h\u01a1n \u1edf ng\u01b0\u1eddi r\u1ea5t n\u0103ng \u0111\u1ed9ng) cho m\u1ee5c ti\u00eau t\u0103ng c\u01a1/gi\u1ea3m m\u1ee1.';
                document.getElementById('res_us_age_note').innerText  = usAgeDetail;
                document.getElementById('res_us_condition_note').innerText = usConditionDetail;
                document.getElementById('res_meal_note').innerText    = 'G\u1ee3i \u00fd chia 3 b\u1eefa ch\u00ednh: kho\u1ea3ng ' + meal_min + ' - ' + meal_max + 'g protein m\u1ed7i b\u1eefa \u0111\u1ec3 h\u1ea5p thu v\u00e0 ph\u1ee5c h\u1ed3i t\u1ed1t h\u01a1n.';
                document.getElementById('res_vn_ref_note').innerHTML  = 'Ngu\u1ed3n tham kh\u1ea3o: <a href="https://iris.who.int/server/api/core/bitstreams/b7c5ec43-bc59-4b38-b702-3f0e96a06fa1/content" target="_blank" rel="noopener noreferrer">WHO TRS 935</a>.';
                document.getElementById('res_us_ref_note').innerHTML  = 'Ngu\u1ed3n tham kh\u1ea3o: <a href="https://cdn.realfood.gov/DGA.pdf" target="_blank" rel="noopener noreferrer">Dietary Guidelines for Americans</a>.';

                if (dietEstimator) dietEstimator.classList.add('is-visible');
                var conn12 = document.getElementById('pcConnector12');
                if (conn12) conn12.classList.add('is-active');
                updateDietEstimatorSummary();
                updateSuggestedProducts();

                var resultBox = document.getElementById('pcResult');
                resultBox.style.display = 'block';
                resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        });
    </script>
<?php
    return ob_get_clean();
}

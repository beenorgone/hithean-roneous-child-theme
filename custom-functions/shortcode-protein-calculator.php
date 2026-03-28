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
        /* Inherit variables from custom.css */
        .protein-calc-wrapper {
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: var(--default-box-shadow, 0 4px 6px rgba(0, 0, 0, 0.1));
            max-width: 960px;
            margin: 0 auto;
            font-family: "Be Vietnam", sans-serif;
            border: 1px solid #eee;
        }

        #proteinCalcForm {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 20px;
        }

        .protein-calc-title {
            text-align: center;
            color: var(--default-color-dark-blue, #0047ba);
            margin-bottom: 25px;
            font-family: "Oswald", sans-serif;
            text-transform: uppercase;
            font-size: 24px;
            line-height: 1.3;
        }

        .pc-form-group {
            margin-bottom: 15px;
        }

        .pc-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--default-color-dark-brown, #2e1203);
            font-size: 15px;
        }

        .pc-form-control {
            width: 100% !important;
            height: 46px;
            /* Matches theme input height */
            padding: 0 15px;
            border: 1px solid #ccc;
            border-radius: 0;
            /* Theme style usually square or slight radius */
            font-size: 16px;
            font-family: "Be Vietnam", sans-serif;
            background: #fff;
            color: #333;
        }

        .pc-btn {
            width: 100%;
            margin-top: 15px;
            cursor: pointer;
            font-size: 16px;
            grid-column: 1 / -1;
            min-height: 52px;
        }

        .pc-result-box {
            margin-top: 30px;
            padding: 20px;
            background: var(--default-color-beige, #f7f6f2);
            border-radius: 4px;
            display: none;
            /* Hidden by default */
            border: 1px solid #e0e0e0;
        }

        .pc-result-header {
            font-weight: 500;
            color: var(--default-color-dark-blue, #0047ba);
            margin-bottom: 15px;
            font-size: 18px;
            text-align: center;
            text-transform: uppercase;
            font-family: "Oswald", sans-serif;
        }

        .pc-result-item {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px dashed #ccc;
        }

        .pc-result-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .pc-label {
            font-weight: 600;
            display: block;
            margin-bottom: 5px;
            color: #333;
        }

        .pc-val {
            font-weight: bold;
            color: var(--product-nuocepkytu-light-green, #00843d);
            font-size: 20px;
        }

        .pc-note {
            font-size: 13px;
            font-style: italic;
            margin-top: 5px;
            color: #666;
            line-height: 1.4;
        }

        .pc-note a {
            color: var(--default-color-dark-blue, #0047ba);
            text-decoration: underline;
        }

        .pc-highlight {
            background-color: #fff;
            padding: 15px;
            border-left: 4px solid var(--default-color-dark-blue, #0047ba);
            margin-top: 10px;
        }

        .pc-highlight-vn {
            border-left-color: var(--default-color-dark-brown, #2e1203);
        }

        .pc-products-section {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .pc-products-title {
            text-align: center;
            color: var(--default-color-dark-blue, #0047ba);
            margin-bottom: 20px;
            font-family: "Oswald", sans-serif;
            text-transform: uppercase;
            font-size: 20px;
        }

        .pc-product-slot {
            display: none;
        }

        .pc-product-slot.is-active {
            display: block;
        }

        .pc-followup-section {
            margin-top: 24px;
            padding: 20px;
            border: 1px solid #e6e0d5;
            background: #fffdf8;
            display: none;
        }

        .pc-followup-section.is-visible {
            display: block;
        }

        .pc-followup-intro {
            margin: 0 0 18px;
            font-size: 14px;
            color: #5e5e5e;
            line-height: 1.6;
        }

        .pc-food-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px 16px;
        }

        .pc-food-card {
            padding: 14px;
            border: 1px solid #ece7dc;
            background: #fff;
        }

        .pc-food-card label {
            display: block;
            font-weight: 600;
            color: var(--default-color-dark-brown, #2e1203);
            margin-bottom: 6px;
        }

        .pc-food-meta {
            display: block;
            margin-bottom: 8px;
            color: #6d6d6d;
            font-size: 12px;
            line-height: 1.5;
        }

        .pc-food-card input {
            width: 100% !important;
        }

        .pc-diet-summary {
            margin-top: 18px;
        }

        .pc-reference-list {
            margin-top: 14px;
            font-size: 13px;
            color: #666;
            line-height: 1.6;
        }

        .pc-reference-list a {
            color: var(--default-color-dark-blue, #0047ba);
            text-decoration: underline;
        }

        @media (max-width: 767px) {
            .protein-calc-wrapper {
                max-width: 600px;
            }

            #proteinCalcForm {
                grid-template-columns: 1fr;
            }

            .pc-food-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 1024px) {
            .protein-calc-wrapper {
                padding: 42px;
            }

            .protein-calc-title {
                font-size: 32px;
                margin-bottom: 32px;
            }

            .pc-form-group label {
                font-size: 16px;
            }

            .pc-form-control {
                height: 52px;
                font-size: 17px;
            }

            .pc-result-header {
                font-size: 22px;
            }

            .pc-val {
                font-size: 24px;
            }
        }
    </style>

    <div class="protein-calc-wrapper">
        <h3 class="protein-calc-title">Tính Lượng Protein Cần Thiết Mỗi Ngày</h3>
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

            <div class="pc-result-item pc-highlight pc-highlight-vn">
                <span class="pc-label">Mức phù hợp cho người Việt (tham chiếu WHO):</span>
                <span class="pc-val" id="res_vn"></span>
                <div class="pc-note" id="res_vn_activity_note"></div>
                <div class="pc-note" id="res_vn_age_note"></div>
                <div class="pc-note" id="res_vn_condition_note"></div>
                <div class="pc-note" id="res_vn_ref_note"></div>
            </div>

            <div class="pc-result-item pc-highlight">
                <span class="pc-label" style="color:var(--default-color-dark-blue)">Khuyến nghị mới (Mỹ 2026-2030):</span>
                <span class="pc-val" id="res_us" style="color:var(--default-color-dark-blue)"></span>
                <div class="pc-note" id="res_us_goal_note"></div>
                <div class="pc-note" id="res_us_age_note"></div>
                <div class="pc-note" id="res_us_condition_note"></div>
                <div class="pc-note" id="res_meal_note"></div>
                <div class="pc-note" id="res_us_ref_note"></div>
            </div>
        </div>

        <div id="pcDietEstimator" class="pc-followup-section">
            <div class="pc-result-header">Ước Tính Protein Từ Chế Độ Ăn Hiện Tại</div>
            <p class="pc-followup-intro">
                Sau khi biết nhu cầu protein mỗi ngày, bạn có thể tự nhập lượng thực phẩm mình ăn trong 1 ngày để ước tính lượng protein đang nạp.
                Đây là công cụ ước tính nhanh dựa trên dữ liệu thành phần thực phẩm phổ biến; nên đối chiếu thêm với nhãn dinh dưỡng của sản phẩm bạn thực tế sử dụng.
            </p>

            <div class="pc-food-grid">
                <div class="pc-food-card">
                    <label for="pc_diet_eggs">Trứng gà</label>
                    <span class="pc-food-meta">Nhập số quả bạn ăn trong ngày. Quy đổi: 1 quả lớn luộc/chín = khoảng 6.3g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_eggs" min="0" step="1" value="0" placeholder="Ví dụ: 2" data-protein="6.3" data-base-amount="1">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_chicken">Ức gà chín</label>
                    <span class="pc-food-meta">Nhập tổng gram bạn ăn trong ngày. Quy đổi: 100g = khoảng 31g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_chicken" min="0" step="10" value="0" placeholder="Ví dụ: 150" data-protein="31" data-base-amount="100">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_fish">Cá chín</label>
                    <span class="pc-food-meta">Nhập tổng gram bạn ăn trong ngày. Quy đổi: 100g = khoảng 22g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_fish" min="0" step="10" value="0" placeholder="Ví dụ: 120" data-protein="22" data-base-amount="100">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_beef">Thịt bò nạc chín</label>
                    <span class="pc-food-meta">Nhập tổng gram bạn ăn trong ngày. Quy đổi: 100g = khoảng 26g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_beef" min="0" step="10" value="0" placeholder="Ví dụ: 100" data-protein="26" data-base-amount="100">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_pork">Thịt heo nạc chín</label>
                    <span class="pc-food-meta">Nhập tổng gram bạn ăn trong ngày. Quy đổi: 100g = khoảng 27g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_pork" min="0" step="10" value="0" placeholder="Ví dụ: 100" data-protein="27" data-base-amount="100">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_shrimp">Tôm chín</label>
                    <span class="pc-food-meta">Nhập tổng gram bạn ăn trong ngày. Quy đổi: 100g = khoảng 24g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_shrimp" min="0" step="10" value="0" placeholder="Ví dụ: 120" data-protein="24" data-base-amount="100">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_milk">Sữa</label>
                    <span class="pc-food-meta">Nhập tổng ml bạn uống trong ngày. Quy đổi: 240ml = khoảng 8g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_milk" min="0" step="50" value="0" placeholder="Ví dụ: 240" data-protein="8" data-base-amount="240">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_soymilk">Sữa đậu nành</label>
                    <span class="pc-food-meta">Nhập tổng ml bạn uống trong ngày. Quy đổi: 240ml = khoảng 7g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_soymilk" min="0" step="50" value="0" placeholder="Ví dụ: 240" data-protein="7" data-base-amount="240">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_yogurt">Sữa chua Greek</label>
                    <span class="pc-food-meta">Nhập số hũ bạn ăn trong ngày. Quy đổi: 1 hũ 170g = khoảng 17g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_yogurt" min="0" step="1" value="0" placeholder="Ví dụ: 1" data-protein="17" data-base-amount="1">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_tofu">Đậu phụ cứng</label>
                    <span class="pc-food-meta">Nhập tổng gram bạn ăn trong ngày. Quy đổi: 100g = khoảng 14g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_tofu" min="0" step="10" value="0" placeholder="Ví dụ: 200" data-protein="14" data-base-amount="100">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_beans">Đậu, đỗ, đậu lăng đã nấu</label>
                    <span class="pc-food-meta">Nhập tổng gram bạn ăn trong ngày. Quy đổi: 100g = khoảng 9g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_beans" min="0" step="10" value="0" placeholder="Ví dụ: 150" data-protein="9" data-base-amount="100">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_peanuts">Đậu phộng / lạc rang</label>
                    <span class="pc-food-meta">Nhập tổng gram bạn ăn trong ngày. Quy đổi: 30g = khoảng 7g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_peanuts" min="0" step="10" value="0" placeholder="Ví dụ: 30" data-protein="7" data-base-amount="30">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_whey">Whey protein</label>
                    <span class="pc-food-meta">Nhập số muỗng bạn dùng trong ngày. Quy đổi: 1 muỗng chuẩn = khoảng 24g protein</span>
                    <input type="number" class="pc-form-control pc-diet-input" id="pc_diet_whey" min="0" step="0.5" value="0" placeholder="Ví dụ: 1" data-protein="24" data-base-amount="1">
                </div>
                <div class="pc-food-card">
                    <label for="pc_diet_custom">Protein từ món khác</label>
                    <span class="pc-food-meta">Tự nhập trực tiếp gram protein từ món khác hoặc từ nhãn sản phẩm bạn dùng trong ngày</span>
                    <input type="number" class="pc-form-control" id="pc_diet_custom" min="0" step="1" value="0" placeholder="Ví dụ: 12">
                </div>
            </div>

            <div class="pc-result-item pc-highlight pc-diet-summary">
                <span class="pc-label">Tổng protein ước tính từ chế độ ăn hiện tại:</span>
                <span class="pc-val" id="pcDietTotal">0g / ngày</span>
                <div class="pc-note" id="pcDietCompareNote">Nhập lượng thực phẩm bạn thực sự ăn trong 1 ngày để xem mức đang thiếu hay dư.</div>
                <div class="pc-note" id="pcDietAccuracyNote">Mức này là ước tính nhanh, không thay thế cho theo dõi khẩu phần chi tiết hoặc tư vấn dinh dưỡng cá nhân hoá.</div>
            </div>

            <div class="pc-reference-list">
                Tài liệu đối chiếu:
                <br>1. <a href="https://fdc.nal.usda.gov/" target="_blank" rel="noopener noreferrer">USDA FoodData Central</a> - cơ sở dữ liệu thành phần dinh dưỡng để kiểm tra protein của từng thực phẩm.
                <br>2. <a href="https://www.myplate.gov/eathealthy/protein-foods/protein-foods-nutrients-health?post=08132019a" target="_blank" rel="noopener noreferrer">USDA MyPlate - Protein Foods Group</a> - quy đổi khẩu phần household measures như 1 quả trứng, 1/4 cup đậu, 1 ounce thịt/cá.
                <br>3. <a href="https://www.fda.gov/food/nutrition-facts-label/how-understand-and-use-nutrition-facts-label" target="_blank" rel="noopener noreferrer">FDA - How to Understand and Use the Nutrition Facts Label</a> - dùng để đối chiếu thực phẩm đóng gói vì công thức/nhãn từng hãng có thể lệch so với giá trị trung bình.
            </div>
        </div>

        <div class="pc-products-section">
            <h4 class="pc-products-title">Bổ sung protein cho chế độ ăn</h4>
            <?php foreach ($product_ids_by_case as $case => $ids) : ?>
                <div class="pc-product-slot<?php echo $case === 'default' ? ' is-active' : ''; ?>" data-product-case="<?php echo esc_attr($case); ?>">
                    <?php
                    if ($ids !== '') {
                        echo do_shortcode(sprintf('[products ids="%s" columns="%d"]', esc_attr($ids), $product_columns));
                    }
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var form = document.getElementById('proteinCalcForm');
            if (!form) return;
            var genderEl = document.getElementById('pc_gender');
            var conditionEl = document.getElementById('pc_condition');
            var conditionGroup = document.getElementById('pc_condition_group');
            var activityEl = document.getElementById('pc_activity');
            var productSlots = document.querySelectorAll('.pc-product-slot');
            var dietEstimator = document.getElementById('pcDietEstimator');
            var dietInputs = document.querySelectorAll('.pc-diet-input');
            var customDietInput = document.getElementById('pc_diet_custom');
            var dietTotalEl = document.getElementById('pcDietTotal');
            var dietCompareNoteEl = document.getElementById('pcDietCompareNote');
            var lastProteinTarget = null;

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
                        var isDefault = slot.getAttribute('data-product-case') === 'default';
                        slot.classList.toggle('is-active', isDefault);
                    });
                }
            }

            function syncActivityField() {
                if (!genderEl || !conditionEl || !activityEl) return;

                var isPregnant = genderEl.value === 'female' && conditionEl.value && conditionEl.value !== 'normal';

                if (isPregnant) {
                    activityEl.value = 'sedentary';
                    activityEl.disabled = true;
                    return;
                }

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

            function parsePositiveNumber(value) {
                var parsed = parseFloat(value);
                return (!isNaN(parsed) && parsed > 0) ? parsed : 0;
            }

            function loadDietEstimatorData() {
                var savedDiet = localStorage.getItem('protein_calculator_diet_data');
                if (!savedDiet) return;

                try {
                    var dietData = JSON.parse(savedDiet);
                    if (dietInputs.length) {
                        dietInputs.forEach(function(input) {
                            if (typeof dietData[input.id] !== 'undefined') {
                                input.value = dietData[input.id];
                            }
                        });
                    }

                    if (customDietInput && typeof dietData[customDietInput.id] !== 'undefined') {
                        customDietInput.value = dietData[customDietInput.id];
                    }
                } catch (e) {
                    console.error('Lỗi khi tải dữ liệu khẩu phần protein:', e);
                }
            }

            function saveDietEstimatorData() {
                var dietData = {};

                if (dietInputs.length) {
                    dietInputs.forEach(function(input) {
                        dietData[input.id] = input.value;
                    });
                }

                if (customDietInput) {
                    dietData[customDietInput.id] = customDietInput.value;
                }

                localStorage.setItem('protein_calculator_diet_data', JSON.stringify(dietData));
            }

            function updateDietEstimatorSummary() {
                if (!dietTotalEl || !dietCompareNoteEl) return;

                var totalProtein = 0;

                if (dietInputs.length) {
                    dietInputs.forEach(function(input) {
                        var amount = parsePositiveNumber(input.value);
                        var proteinPerServing = parsePositiveNumber(input.getAttribute('data-protein'));
                        var baseAmount = parsePositiveNumber(input.getAttribute('data-base-amount')) || 1;
                        totalProtein += (amount / baseAmount) * proteinPerServing;
                    });
                }

                totalProtein += parsePositiveNumber(customDietInput ? customDietInput.value : 0);
                totalProtein = Math.round(totalProtein * 10) / 10;

                dietTotalEl.innerText = totalProtein.toFixed(1).replace('.0', '') + 'g / ngày';

                if (!lastProteinTarget) {
                    dietCompareNoteEl.innerText = 'Hãy tính nhu cầu protein trước, sau đó công cụ sẽ so sánh khẩu phần hiện tại với mục tiêu của bạn.';
                    return;
                }

                if (totalProtein < lastProteinTarget.min) {
                    var missingMin = Math.round((lastProteinTarget.min - totalProtein) * 10) / 10;
                    dietCompareNoteEl.innerText = 'Bạn đang thiếu khoảng ' + missingMin.toFixed(1).replace('.0', '') + 'g so với ngưỡng tối thiểu ' + lastProteinTarget.min + 'g/ngày. Có thể tăng thêm 1-2 khẩu phần đạm chất lượng cao.';
                    return;
                }

                if (totalProtein > lastProteinTarget.max) {
                    var excess = Math.round((totalProtein - lastProteinTarget.max) * 10) / 10;
                    dietCompareNoteEl.innerText = 'Bạn đang cao hơn khoảng ' + excess.toFixed(1).replace('.0', '') + 'g so với cận trên ' + lastProteinTarget.max + 'g/ngày. Nên rà lại tổng năng lượng nếu mục tiêu là kiểm soát cân nặng.';
                    return;
                }

                dietCompareNoteEl.innerText = 'Ước tính hiện tại đang nằm trong vùng mục tiêu ' + lastProteinTarget.min + ' - ' + lastProteinTarget.max + 'g/ngày.';
            }

            // --- LOCAL STORAGE: LOAD ---
            var saved = localStorage.getItem('protein_calculator_data');
            if (saved) {
                try {
                    var data = JSON.parse(saved);
                    if (data.gender) document.getElementById('pc_gender').value = data.gender;
                    if (data.age) document.getElementById('pc_age').value = data.age;
                    if (data.weight) document.getElementById('pc_weight').value = data.weight;
                    if (data.activity) document.getElementById('pc_activity').value = data.activity;
                    if (data.condition) document.getElementById('pc_condition').value = data.condition;

                    // Auto submit to show result if data is valid
                    if (data.weight && data.age) {
                        setTimeout(function() {
                            var btn = form.querySelector('button[type="submit"]');
                            if (btn) btn.click();
                        }, 50);
                    }
                } catch (e) {
                    console.error('Lỗi khi tải dữ liệu đã lưu:', e);
                }
            }
            loadDietEstimatorData();
            toggleConditionField();
            syncActivityField();
            if (genderEl) {
                genderEl.addEventListener('change', toggleConditionField);
            }
            if (conditionEl) {
                conditionEl.addEventListener('change', function() {
                    syncActivityField();
                    updateSuggestedProducts();
                });
            }
            if (activityEl) {
                activityEl.addEventListener('change', updateSuggestedProducts);
            }
            if (dietInputs.length) {
                dietInputs.forEach(function(input) {
                    input.addEventListener('input', function() {
                        saveDietEstimatorData();
                        updateDietEstimatorSummary();
                    });
                });
            }
            if (customDietInput) {
                customDietInput.addEventListener('input', function() {
                    saveDietEstimatorData();
                    updateDietEstimatorSummary();
                });
            }
            updateSuggestedProducts();
            updateDietEstimatorSummary();

            form.addEventListener('submit', function(e) {
                e.preventDefault();

                var weight = parseFloat(document.getElementById('pc_weight').value);
                var activity = document.getElementById('pc_activity').value;
                var condition = document.getElementById('pc_condition').value;
                var age = parseInt(document.getElementById('pc_age').value);
                var gender = document.getElementById('pc_gender').value;

                if (gender === 'female' && condition !== 'normal') {
                    activity = 'sedentary';
                    document.getElementById('pc_activity').value = activity;
                }

                if (!weight || weight <= 0) return;

                // --- LOCAL STORAGE: SAVE ---
                var dataToSave = {
                    gender: gender,
                    age: age,
                    weight: weight,
                    activity: activity,
                    condition: condition
                };
                localStorage.setItem('protein_calculator_data', JSON.stringify(dataToSave));

                var activityMap = {
                    sedentary: {
                        vnMin: 0.8,
                        vnMax: 1.0,
                        label: 'Ít vận động',
                        detail: 'Nên duy trì đạm ở mức cơ bản để bảo toàn khối cơ và phục hồi hằng ngày.'
                    },
                    light: {
                        vnMin: 1.0,
                        vnMax: 1.2,
                        label: 'Vận động nhẹ',
                        detail: 'Nhu cầu đạm tăng nhẹ để hỗ trợ phục hồi sau các buổi tập/đi bộ nhẹ.'
                    },
                    moderate: {
                        vnMin: 1.2,
                        vnMax: 1.4,
                        label: 'Vận động vừa',
                        detail: 'Mức phù hợp cho người tập đều đặn 3-5 buổi/tuần, cần phục hồi cơ tốt hơn.'
                    },
                    active: {
                        vnMin: 1.4,
                        vnMax: 1.6,
                        label: 'Vận động nhiều',
                        detail: 'Phù hợp người tập nặng, cần tăng đạm để duy trì và phát triển khối cơ.'
                    },
                    very_active: {
                        vnMin: 1.6,
                        vnMax: 1.8,
                        label: 'Rất nhiều',
                        detail: 'Dành cho vận động viên hoặc lao động thể lực cao, nhu cầu phục hồi rất lớn.'
                    }
                };
                var activityInfo = activityMap[activity] || activityMap.sedentary;

                var vn_min = activityInfo.vnMin;
                var vn_max = activityInfo.vnMax;
                var vnAgeDetail = '';
                var usAgeDetail = '';
                var vnConditionDetail = 'Không có điều chỉnh đặc biệt.';
                var usConditionDetail = 'Không có điều chỉnh đặc biệt.';

                if (age < 18) {
                    vn_min = Math.max(vn_min, 1.0);
                    vn_max = Math.max(vn_max, 1.3);
                    vnAgeDetail = 'Nhóm dưới 18 tuổi: ưu tiên đạm chất lượng để hỗ trợ tăng trưởng, không nên cắt giảm đạm quá thấp.';
                    usAgeDetail = 'Khuyến nghị Mỹ chủ yếu áp dụng cho người trưởng thành, với trẻ em cần theo hướng dẫn riêng theo tuổi.';
                } else if (age <= 49) {
                    vnAgeDetail = 'Nhóm 18-49 tuổi: áp dụng trực tiếp theo mức vận động để duy trì sức khỏe và thành phần cơ thể.';
                    usAgeDetail = 'Nhóm trưởng thành: có thể dùng mức 1.2-1.6g/kg khi cần tối ưu cơ bắp hoặc kiểm soát cân nặng.';
                } else if (age <= 64) {
                    vn_min += 0.1;
                    vn_max += 0.2;
                    vnAgeDetail = 'Nhóm 50-64 tuổi: tăng nhẹ đạm để hỗ trợ chống mất cơ liên quan tuổi tác.';
                    usAgeDetail = 'Tuổi trung niên: có thể ưu tiên mức gần cận trên khi vận động thường xuyên hoặc đang giảm mỡ.';
                } else {
                    vn_min += 0.2;
                    vn_max += 0.3;
                    vnAgeDetail = 'Nhóm từ 65 tuổi: nên tăng đạm và chia đều trong ngày để hỗ trợ vận động và hạn chế suy giảm cơ.';
                    usAgeDetail = 'Nhóm lớn tuổi: nên dùng mức cao vừa phải, đồng thời theo dõi chức năng thận và bệnh nền.';
                }

                var vn_total_min = Math.round(weight * vn_min);
                var vn_total_max = Math.round(weight * vn_max);

                // Mỹ 2026-2030: 1.2-1.6g/kg, có thể tăng ở người vận động cao
                var us_min = 1.2;
                var us_max = 1.6;
                if (activity === 'active') {
                    us_max = 1.7;
                } else if (activity === 'very_active') {
                    us_max = 1.8;
                }

                // Mẹ bầu: tăng thêm theo tam cá nguyệt (tham chiếu WHO/FAO/UNU)
                var pregnancyExtra = 0;
                if (condition !== 'normal' && gender === 'female') {
                    if (condition === 'pregnant_t1') {
                        pregnancyExtra = 1;
                    } else if (condition === 'pregnant_t2') {
                        pregnancyExtra = 9;
                    } else if (condition === 'pregnant_t3') {
                        pregnancyExtra = 31;
                    }

                    vn_min = Math.max(vn_min, 1.1);
                    vn_max = Math.max(vn_max, 1.3);
                    us_min = Math.max(us_min, 1.1);
                    us_max = Math.max(us_max, 1.6);

                    vnConditionDetail = 'Mẹ bầu được cộng thêm ' + pregnancyExtra + 'g/ngày theo tam cá nguyệt, đồng thời ưu tiên nguồn đạm dễ tiêu và an toàn thực phẩm.';
                    usConditionDetail = 'Với thai kỳ, nên ưu tiên đạt ít nhất ngưỡng nền 1.1g/kg/ngày và theo dõi tăng cân thai kỳ.';
                } else if (condition !== 'normal' && gender !== 'female') {
                    vnConditionDetail = 'Bạn chọn mục mẹ bầu nhưng giới tính hiện tại không phải nữ, hệ thống không cộng thêm thai kỳ.';
                    usConditionDetail = 'Bạn chọn mục mẹ bầu nhưng giới tính hiện tại không phải nữ, hệ thống không cộng thêm thai kỳ.';
                }

                vn_total_min = Math.round((weight * vn_min) + pregnancyExtra);
                vn_total_max = Math.round((weight * vn_max) + pregnancyExtra);
                var us_total_min = Math.round(weight * us_min);
                var us_total_max = Math.round(weight * us_max);
                us_total_min = Math.round(us_total_min + pregnancyExtra);
                us_total_max = Math.round(us_total_max + pregnancyExtra);
                var meal_min = Math.max(20, Math.round(us_total_min / 3));
                var meal_max = Math.max(meal_min + 4, Math.round(us_total_max / 3));
                lastProteinTarget = {
                    min: vn_total_min,
                    max: vn_total_max,
                    usMin: us_total_min,
                    usMax: us_total_max
                };

                // Hien thi ket qua
                document.getElementById('res_vn').innerText = vn_total_min + ' - ' + vn_total_max + 'g / ngày';
                document.getElementById('res_us').innerText = us_total_min + ' - ' + us_total_max + 'g / ngày';
                document.getElementById('res_vn_activity_note').innerText = activityInfo.label + ': ' + activityInfo.detail;
                document.getElementById('res_vn_age_note').innerText = vnAgeDetail;
                document.getElementById('res_vn_condition_note').innerText = vnConditionDetail;
                document.getElementById('res_us_goal_note').innerText = 'Mỹ 2026-2030: 1.2g/kg cho duy trì sức khỏe và 1.6g/kg (hoặc cao hơn ở người rất năng động) cho mục tiêu tăng cơ/giảm mỡ.';
                document.getElementById('res_us_age_note').innerText = usAgeDetail;
                document.getElementById('res_us_condition_note').innerText = usConditionDetail;
                document.getElementById('res_meal_note').innerText = 'Gợi ý chia 3 bữa chính: khoảng ' + meal_min + ' - ' + meal_max + 'g protein mỗi bữa để hấp thu và phục hồi tốt hơn.';
                document.getElementById('res_vn_ref_note').innerHTML = 'Nguồn tham khảo: <a href="https://iris.who.int/server/api/core/bitstreams/b7c5ec43-bc59-4b38-b702-3f0e96a06fa1/content" target="_blank" rel="noopener noreferrer">WHO TRS 935</a>.';
                document.getElementById('res_us_ref_note').innerHTML = 'Nguồn tham khảo: <a href="https://cdn.realfood.gov/DGA.pdf" target="_blank" rel="noopener noreferrer">Dietary Guidelines for Americans</a>.';
                if (dietEstimator) {
                    dietEstimator.classList.add('is-visible');
                }
                updateDietEstimatorSummary();
                updateSuggestedProducts();

                var resultBox = document.getElementById('pcResult');
                resultBox.style.display = 'block';

                // Scroll to result on mobile
                resultBox.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
            });
        });
    </script>
<?php
    return ob_get_clean();
}

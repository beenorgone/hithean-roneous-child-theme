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
            max-width: 600px;
            margin: 0 auto;
            font-family: "Be Vietnam", sans-serif;
            border: 1px solid #eee;
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
            font-weight: bold;
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
            updateSuggestedProducts();

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

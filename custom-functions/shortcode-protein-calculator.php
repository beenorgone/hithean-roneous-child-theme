<?php
/*
 * Shortcode: [protein_calculator]
 * Tính lượng protein cần thiết theo khuyến nghị VN và Mỹ (2026-2030)
 */

add_shortcode('protein_calculator', 'render_protein_calculator');

function render_protein_calculator($atts)
{
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

        .pc-highlight {
            background-color: #fff;
            padding: 15px;
            border-left: 4px solid var(--default-color-dark-blue, #0047ba);
            margin-top: 10px;
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
            <button type="submit" class="button button--nuocepkytu-light-green pc-btn">TÍNH NGAY</button>
        </form>

        <div id="pcResult" class="pc-result-box">
            <div class="pc-result-header">Kết Quả Của Bạn</div>

            <div class="pc-result-item">
                <span class="pc-label">Khuyến nghị tiêu chuẩn (Việt Nam/WHO):</span>
                <span class="pc-val" id="res_vn"></span>
                <div class="pc-note">
                    Phù hợp với thể trạng người Việt và nhu cầu dinh dưỡng cơ bản (0.8 - 1.5g/kg tùy vận động).
                </div>
            </div>

            <div class="pc-result-item pc-highlight">
                <span class="pc-label" style="color:var(--default-color-dark-blue)">Khuyến nghị mới (Mỹ 2026-2030):</span>
                <span class="pc-val" id="res_us" style="color:var(--default-color-dark-blue)"></span>
                <div class="pc-note">
                    Theo tài liệu: <strong>Hướng dẫn chế độ ăn uống cho người Mỹ giai đoạn 2025–2030 (Dietary Guidelines for Americans – DGAs)</strong> công bố ngày 7/1/2026, Bộ Y tế và Dịch vụ Nhân sinh Hoa Kỳ (HHS) phối hợp với Bộ Nông nghiệp Hoa Kỳ (USDA): <strong>1.2g</strong> (duy trì sức khỏe) đến <strong>1.6g</strong> (tăng cơ/giảm cân) trên mỗi kg cân nặng.
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var form = document.getElementById('proteinCalcForm');
            if (!form) return;

            // --- LOCAL STORAGE: LOAD ---
            var saved = localStorage.getItem('protein_calculator_data');
            if (saved) {
                try {
                    var data = JSON.parse(saved);
                    if (data.gender) document.getElementById('pc_gender').value = data.gender;
                    if (data.age) document.getElementById('pc_age').value = data.age;
                    if (data.weight) document.getElementById('pc_weight').value = data.weight;
                    if (data.activity) document.getElementById('pc_activity').value = data.activity;

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

            form.addEventListener('submit', function(e) {
                e.preventDefault();

                var weight = parseFloat(document.getElementById('pc_weight').value);
                var activity = document.getElementById('pc_activity').value;
                var age = parseInt(document.getElementById('pc_age').value);
                var gender = document.getElementById('pc_gender').value;

                if (!weight || weight <= 0) return;

                // --- LOCAL STORAGE: SAVE ---
                var dataToSave = {
                    gender: gender,
                    age: age,
                    weight: weight,
                    activity: activity
                };
                localStorage.setItem('protein_calculator_data', JSON.stringify(dataToSave));

                /* 
                 * 1. Tinh theo tieu chuan VN/WHO (Standard)
                 * Baseline 0.8g/kg. Tang theo activity.
                 */
                var vn_min = 0.8;
                var vn_max = 1.0;

                switch (activity) {
                    case 'light':
                        vn_min = 1.0;
                        vn_max = 1.2;
                        break;
                    case 'moderate':
                        vn_min = 1.2;
                        vn_max = 1.4;
                        break;
                    case 'active':
                        vn_min = 1.4;
                        vn_max = 1.6;
                        break;
                    case 'very_active':
                        vn_min = 1.6;
                        vn_max = 1.8;
                        break;
                    default:
                        vn_min = 0.8;
                        vn_max = 1.0; // sedentary
                }

                // Nguoi gia (>50) can nhieu protein hon de chong mat co (Sarcopenia)
                if (age > 50) {
                    vn_min += 0.2;
                    vn_max += 0.2;
                }

                var vn_total_min = Math.round(weight * vn_min);
                var vn_total_max = Math.round(weight * vn_max);

                /* 
                 * 2. Tinh theo US 2026 Guidelines 
                 * Range: 1.2 - 1.6g/kg
                 */
                var us_min = 1.2;
                var us_max = 1.6;

                var us_total_min = Math.round(weight * us_min);
                var us_total_max = Math.round(weight * us_max);

                // Hien thi ket qua
                document.getElementById('res_vn').innerText = vn_total_min + ' - ' + vn_total_max + 'g / ngày';
                document.getElementById('res_us').innerText = us_total_min + ' - ' + us_total_max + 'g / ngày';

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

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

        .pc-highlight-who {
            border-left-color: var(--default-color-green-dark, #00843d);
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
                <span class="pc-label">Mức phù hợp cho người Việt:</span>
                <span class="pc-val" id="res_vn"></span>
                <div class="pc-note" id="res_vn_activity_note"></div>
                <div class="pc-note" id="res_vn_age_note"></div>
            </div>

            <div class="pc-result-item pc-highlight pc-highlight-who">
                <span class="pc-label" style="color:var(--default-color-green-dark)">Khuyến nghị tham chiếu WHO:</span>
                <span class="pc-val" id="res_who" style="color:var(--default-color-green-dark)"></span>
                <div class="pc-note" id="res_who_age_note"></div>
                <div class="pc-note" id="res_who_activity_note"></div>
            </div>

            <div class="pc-result-item pc-highlight">
                <span class="pc-label" style="color:var(--default-color-dark-blue)">Khuyến nghị mới (Mỹ 2026-2030):</span>
                <span class="pc-val" id="res_us" style="color:var(--default-color-dark-blue)"></span>
                <div class="pc-note" id="res_us_goal_note"></div>
                <div class="pc-note" id="res_us_age_note"></div>
                <div class="pc-note" id="res_meal_note"></div>
            </div>
        </div>

        <div class="pc-products-section">
            <h4 class="pc-products-title">Bổ sung protein cho chế độ ăn</h4>
            <?php echo do_shortcode('[products ids="4690,3977" columns="2"]'); ?>
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
                var who_min = 0.83;
                var who_max = 1.0;
                var whoAgeDetail = 'WHO xem khoảng 0.83g/kg/ngày là mức đáp ứng nhu cầu tối thiểu ở người trưởng thành khỏe mạnh.';
                var vnAgeDetail = '';
                var usAgeDetail = '';

                if (age < 18) {
                    vn_min = Math.max(vn_min, 1.0);
                    vn_max = Math.max(vn_max, 1.3);
                    who_min = 0.95;
                    who_max = 1.2;
                    vnAgeDetail = 'Nhóm dưới 18 tuổi: ưu tiên đạm chất lượng để hỗ trợ tăng trưởng, không nên cắt giảm đạm quá thấp.';
                    whoAgeDetail = 'Với trẻ vị thành niên, nên tham chiếu mức cao hơn người lớn để hỗ trợ phát triển cơ thể.';
                    usAgeDetail = 'Khuyến nghị Mỹ chủ yếu áp dụng cho người trưởng thành, với trẻ em cần theo hướng dẫn riêng theo tuổi.';
                } else if (age <= 49) {
                    vnAgeDetail = 'Nhóm 18-49 tuổi: áp dụng trực tiếp theo mức vận động để duy trì sức khỏe và thành phần cơ thể.';
                    usAgeDetail = 'Nhóm trưởng thành: có thể dùng mức 1.2-1.6g/kg khi cần tối ưu cơ bắp hoặc kiểm soát cân nặng.';
                } else if (age <= 64) {
                    vn_min += 0.1;
                    vn_max += 0.2;
                    who_min = 1.0;
                    who_max = 1.2;
                    vnAgeDetail = 'Nhóm 50-64 tuổi: tăng nhẹ đạm để hỗ trợ chống mất cơ liên quan tuổi tác.';
                    whoAgeDetail = 'Từ 50 tuổi, thường tham chiếu mức 1.0-1.2g/kg/ngày để duy trì khối cơ tốt hơn.';
                    usAgeDetail = 'Tuổi trung niên: có thể ưu tiên mức gần cận trên khi vận động thường xuyên hoặc đang giảm mỡ.';
                } else {
                    vn_min += 0.2;
                    vn_max += 0.3;
                    who_min = 1.1;
                    who_max = 1.3;
                    vnAgeDetail = 'Nhóm từ 65 tuổi: nên tăng đạm và chia đều trong ngày để hỗ trợ vận động và hạn chế suy giảm cơ.';
                    whoAgeDetail = 'Người lớn tuổi thường cần mức cao hơn chuẩn tối thiểu để duy trì sức cơ và chức năng.';
                    usAgeDetail = 'Nhóm lớn tuổi: nên dùng mức cao vừa phải, đồng thời theo dõi chức năng thận và bệnh nền.';
                }

                var vn_total_min = Math.round(weight * vn_min);
                var vn_total_max = Math.round(weight * vn_max);

                // WHO: tăng nhẹ cận trên nếu vận động cao
                if (activity === 'active' || activity === 'very_active') {
                    who_max += 0.1;
                }
                var who_total_min = Math.round(weight * who_min);
                var who_total_max = Math.round(weight * who_max);

                // Mỹ 2026-2030: 1.2-1.6g/kg, có thể tăng ở người vận động cao
                var us_min = 1.2;
                var us_max = 1.6;
                if (activity === 'active') {
                    us_max = 1.7;
                } else if (activity === 'very_active') {
                    us_max = 1.8;
                }

                var us_total_min = Math.round(weight * us_min);
                var us_total_max = Math.round(weight * us_max);
                var meal_min = Math.max(20, Math.round(us_total_min / 3));
                var meal_max = Math.max(meal_min + 4, Math.round(us_total_max / 3));

                // Hien thi ket qua
                document.getElementById('res_vn').innerText = vn_total_min + ' - ' + vn_total_max + 'g / ngày';
                document.getElementById('res_who').innerText = who_total_min + ' - ' + who_total_max + 'g / ngày';
                document.getElementById('res_us').innerText = us_total_min + ' - ' + us_total_max + 'g / ngày';
                document.getElementById('res_vn_activity_note').innerText = activityInfo.label + ': ' + activityInfo.detail;
                document.getElementById('res_vn_age_note').innerText = vnAgeDetail;
                document.getElementById('res_who_age_note').innerText = whoAgeDetail;
                document.getElementById('res_who_activity_note').innerText = 'WHO thiên về mức tối thiểu an toàn; nếu bạn vận động cao, có thể cần gần cận trên của khoảng tham chiếu.';
                document.getElementById('res_us_goal_note').innerText = 'Mỹ 2026-2030: 1.2g/kg cho duy trì sức khỏe và 1.6g/kg (hoặc cao hơn ở người rất năng động) cho mục tiêu tăng cơ/giảm mỡ.';
                document.getElementById('res_us_age_note').innerText = usAgeDetail;
                document.getElementById('res_meal_note').innerText = 'Gợi ý chia 3 bữa chính: khoảng ' + meal_min + ' - ' + meal_max + 'g protein mỗi bữa để hấp thu và phục hồi tốt hơn.';

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

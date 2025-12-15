<?php
// =======================================
// shelter_edit.php  - 보호소 정보 조회/수정
// =======================================

session_start();
header('Content-Type: text/html; charset=UTF-8');

// 디버그 (개발 끝나면 꺼도 됨)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. 로그인 체크
if (!isset($_SESSION['shelter_id'])) {
    echo "<script>alert('로그인 후 이용해주세요.'); location.href='../login/shelter-login.html';</script>";
    exit;
}

$shelter_id   = $_SESSION['shelter_id'];
$shelter_name = '보호소';   // 사이드바에 쓸 기본 이름

// 2. Oracle DB 접속
$db_username = 'C093299';
$db_password = 'TEST1234';              // 네 비밀번호에 맞게 수정
$db_conn_str = '203.249.87.57/orcl';

$conn = @oci_connect($db_username, $db_password, $db_conn_str, 'AL32UTF8');
if (!$conn) {
    $e = oci_error();
    echo "DB 연결 실패 : " . htmlspecialchars($e['message'], ENT_QUOTES);
    exit;
}

/*
 * 2-1. 사이드바에 표시할 보호소 이름 한 번 조회
 *      (실패하면 기본값 '보호소' 그대로 사용)
 */
$sql_name = "
    SELECT name
    FROM SHELTER
    WHERE shelter_id = :sid
";
$stmt_name = oci_parse($conn, $sql_name);
oci_bind_by_name($stmt_name, ':sid', $shelter_id);
if (oci_execute($stmt_name)) {
    $row_name = oci_fetch_assoc($stmt_name);
    if ($row_name && isset($row_name['NAME'])) {
        $shelter_name = $row_name['NAME'];
    }
}
oci_free_statement($stmt_name);

// 3. mode=load → JSON으로 현재 보호소 정보 반환 (AJAX 용)
$mode = $_GET['mode'] ?? '';

if ($mode === 'load') {
    header('Content-Type: application/json; charset=UTF-8');

    $sql = "
        SELECT 
            s.shelter_id,
            s.name,
            s.phone,
            s.open_time,
            s.close_time,
            s.detail,
            r.city,
            r.district
        FROM SHELTER s
        JOIN REGION r ON s.region_id = r.region_id
        WHERE s.shelter_id = :sid
    ";

    $stmt = oci_parse($conn, $sql);
    oci_bind_by_name($stmt, ':sid', $shelter_id);
    oci_execute($stmt);

    $row = oci_fetch_assoc($stmt);
    oci_free_statement($stmt);
    oci_close($conn);

    if ($row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'SHELTER 정보를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
// 4. 보호소 탈퇴 (POST action=delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {

    // JSON으로 응답할 거라서 헤더 바꿔주기
    header('Content-Type: application/json; charset=UTF-8');

    // 이 보호소에 등록된 강아지가 남아 있으면 FK 때문에 삭제가 안 될 수도 있으니(선택 사항)
    $sid_esc = str_replace("'", "''", $shelter_id);

    $sql_del = "
        DELETE FROM SHELTER
        WHERE shelter_id = '" . $sid_esc . "'
    ";
    $stmt_del = oci_parse($conn, $sql_del);

    if (!oci_execute($stmt_del, OCI_NO_AUTO_COMMIT)) {
        $e = oci_error($stmt_del);
        oci_rollback($conn);
        oci_free_statement($stmt_del);
        oci_close($conn);

        echo json_encode([
            'error'  => '보호소 탈퇴 중 오류가 발생했습니다.',
            'detail' => $e['message'] ?? ''
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    oci_commit($conn);
    oci_free_statement($stmt_del);
    oci_close($conn);

    session_unset();
    session_destroy();

    echo json_encode([
        'success' => true,
        'message' => '보호소 탈퇴가 완료되었습니다.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. POST 요청이면 수정 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 4-1. 폼 데이터 받기 (HTML name이랑 반드시 맞아야 함)
    $password        = trim($_POST['password']        ?? '');
    $phone           = trim($_POST['contact']         ?? '');
    $city            = trim($_POST['addr_city']       ?? '');
    $district        = trim($_POST['addr_district']   ?? '');
    $detail          = trim($_POST['location_detail'] ?? '');
    $open_time       = trim($_POST['start_time']      ?? '');
    $close_time      = trim($_POST['end_time']        ?? '');

    // 4-2. 필수값 체크
    if (
        $phone === '' || $city === '' || $district === '' || $detail === '' ||
        $open_time === '' || $close_time === ''
    ) {
        echo "<script>alert('필수 정보를 모두 입력해주세요.'); history.back();</script>";
        oci_close($conn);
        exit;
    }

    // 4-3. 전화번호 형식 체크 (010-1234-5678)
    $phone_pattern = '/^\d{3}-\d{4}-\d{4}$/';

    if (!preg_match($phone_pattern, $phone)) {
        echo "<script>alert('전화번호는 010-1234-5678 형식으로 입력해주세요.'); history.back();</script>";
        oci_close($conn);
        exit;
    }

    // 5. REGION_ID 구하기 (없으면 INSERT 후 새 region_id 사용)
    $sql_region = "
        SELECT region_id
        FROM REGION
        WHERE city = :city
          AND district = :district
    ";

    $stmt_region = oci_parse($conn, $sql_region);
    oci_bind_by_name($stmt_region, ':city', $city);
    oci_bind_by_name($stmt_region, ':district', $district);
    oci_execute($stmt_region);

    $region_id  = null;
    $row_region = oci_fetch_assoc($stmt_region);

    if ($row_region) {
        // 이미 있는 REGION 사용
        $region_id = $row_region['REGION_ID'];
        oci_free_statement($stmt_region);
    } else {
        // REGION 없으면 새로 INSERT
        oci_free_statement($stmt_region);

        $sql_insert_region = "
            INSERT INTO REGION (city, district)
            VALUES (:city, :district)
            RETURNING region_id INTO :region_id
        ";
        $stmt_insert = oci_parse($conn, $sql_insert_region);
        oci_bind_by_name($stmt_insert, ':city', $city);
        oci_bind_by_name($stmt_insert, ':district', $district);
        oci_bind_by_name($stmt_insert, ':region_id', $region_id, 32);

        $r = oci_execute($stmt_insert, OCI_NO_AUTO_COMMIT);
        if (!$r) {
            $e = oci_error($stmt_insert);
            oci_rollback($conn);
            echo "<script>alert('지역 정보 저장 중 오류가 발생했습니다.'); history.back();</script>";
            oci_free_statement($stmt_insert);
            oci_close($conn);
            exit;
        }
        oci_free_statement($stmt_insert);
    }

    // 6. SHELTER UPDATE (비밀번호 입력 여부에 따라 분기)
    if ($password === '') {
        // 비밀번호 수정 안 함
        $sql_update = "
            UPDATE SHELTER
            SET phone      = :phone,
                open_time  = :open_time,
                close_time = :close_time,
                region_id  = :region_id,
                detail     = :detail
            WHERE shelter_id = :sid
        ";
        $stmt_update = oci_parse($conn, $sql_update);

        oci_bind_by_name($stmt_update, ':phone',      $phone);
        oci_bind_by_name($stmt_update, ':open_time',  $open_time);
        oci_bind_by_name($stmt_update, ':close_time', $close_time);
        oci_bind_by_name($stmt_update, ':region_id',  $region_id);
        oci_bind_by_name($stmt_update, ':detail',     $detail);
        oci_bind_by_name($stmt_update, ':sid',        $shelter_id);

    } else {
        // 비밀번호까지 수정 (해시 사용 중이면 여기서 동일한 해시 함수 적용)
        // 예: $password = hash('sha256', $password);
        $sql_update = "
            UPDATE SHELTER
            SET password   = :password,
                phone      = :phone,
                open_time  = :open_time,
                close_time = :close_time,
                region_id  = :region_id,
                detail     = :detail
            WHERE shelter_id = :sid
        ";
        $stmt_update = oci_parse($conn, $sql_update);

        oci_bind_by_name($stmt_update, ':password',   $password);
        oci_bind_by_name($stmt_update, ':phone',      $phone);
        oci_bind_by_name($stmt_update, ':open_time',  $open_time);
        oci_bind_by_name($stmt_update, ':close_time', $close_time);
        oci_bind_by_name($stmt_update, ':region_id',  $region_id);
        oci_bind_by_name($stmt_update, ':detail',     $detail);
        oci_bind_by_name($stmt_update, ':sid',        $shelter_id);
    }

    // 7. UPDATE 실행
    $r = oci_execute($stmt_update, OCI_NO_AUTO_COMMIT);
    if (!$r) {
        $e = oci_error($stmt_update);
        oci_rollback($conn);
        echo "<script>alert('정보 수정 중 오류가 발생했습니다.'); history.back();</script>";
        oci_free_statement($stmt_update);
        oci_close($conn);
        exit;
    }

    // 8. 커밋 & 마무리
    oci_commit($conn);
    oci_free_statement($stmt_update);
    oci_close($conn);

    echo "<script>alert('보호소 정보가 수정되었습니다.'); location.href='shelter_edit.php';</script>";
    exit;
}

// 여기까지 오면 GET(일반 접속)이고, mode=load도 아님 → HTML 화면 출력
?>
<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <title>회원정보 수정</title>
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="shelter-edit.css">
</head>

<body>

    <div class="page-container">

        <!-- 왼쪽 사이드바 -->
        <aside class="sidebar">
            <div class="sidebar-logo-box">
                <!-- 보호소 로고 (클릭 시 이 페이지로 이동) -->
                <a href="shelter_info.php">
                    <!-- 경로 수정: ../img -->
                    <img src="../img/shelter.png" class="sidebar-logo" alt="로고">
                </a>
            </div>

            <!-- ★ DB에서 가져온 이름 -->
            <div class="sidebar-shelter-name" id="sidebarShelterName">
                <?php echo htmlspecialchars($shelter_name, ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <nav class="sidebar-menu">
                <button class="menu-btn active" onclick="location.href='shelter_edit.php'">회원정보 수정</button>
                <button class="menu-btn" onclick="location.href='dog_list.php'">유기견 관리</button>
                <button class="menu-btn" onclick="location.href='notice_list.php'">공고 관리</button>
            </nav>

            <!-- 로그아웃 -->
            <form class="logout-btn" action="../login/logout.php" method="post">
                <button type="submit" class="logout-font">로그아웃</button>
            </form>
        </aside>

        <!-- 메인 -->
        <main class="main-area">
            <!-- 보호소 정보 수정 폼 -->
            <form class="edit-form" id="shelterEditForm" action="shelter_edit.php" method="post">
                <!-- 아이디 (수정 불가) -->
                <div class="form-row">
                    <label for="username">아이디</label>
                    <input type="text" id="username" name="username" disabled>
                </div>

                <!-- 비밀번호 -->
                <div class="form-row">
                    <label for="password">비밀번호</label>
                    <input type="password" id="password" name="password" placeholder="변경 시에만 입력">
                </div>

                <!-- 보호소명 (수정 불가) -->
                <div class="form-row">
                    <label for="shelter_name">보호소명</label>
                    <input type="text" id="shelter_name" name="shelter_name" disabled>
                </div>

                <!-- 연락처 -->
                <div class="form-row">
                    <label for="contact">연락처</label>
                    <input type="tel" id="contact" name="contact" placeholder="전화번호를 입력해주세요.">
                </div>

                <!-- 위치 (드롭다운 + 상세주소) -->
                <div class="form-row">
                    <label>위치</label>
                    <div class="form-col">
                        <div class="address-group">
                            <select id="addr_city" name="addr_city" required></select>
                            <select id="addr_district" name="addr_district" required></select>
                        </div>
                        <input type="text" id="location_detail" name="location_detail" placeholder="상세주소">
                    </div>
                </div>

                <!-- 영업시간 -->
                <div class="form-row">
                    <label>영업시간</label>
                    <div class="form-col">
                        <div class="time-group">
                            <input type="time" id="start_time" name="start_time" required>
                            <span class="time-separator">~</span>
                            <input type="time" id="end_time" name="end_time" required>
                        </div>
                    </div>
                </div>

                <!-- 버튼 -->
                <div class="btn-area">
                    <button type="submit" class="btn submit-btn">수정</button>
                    <button type="button" class="btn cancel-btn" id="btnDelete">탈퇴</button>
                </div>
            </form>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // 1) 시/도 - 구/군 목록 정의
            const districts = {
                "서울특별시": [
                    "강남구", "강동구", "강북구", "강서구",
                    "관악구", "광진구", "구로구", "금천구",
                    "노원구", "도봉구", "동대문구", "동작구",
                    "마포구", "서대문구", "서초구", "성동구",
                    "성북구", "송파구", "양천구", "영등포구",
                    "용산구", "은평구", "종로구", "중구", "중랑구"
                ],
                "경기도 수원시": ["장안구", "권선구", "팔달구", "영통구"],
                "경기도 고양시": ["덕양구", "일산동구", "일산서구"],
                "대구광역시": ["남구", "달서구", "달성군", "동구", "북구", "서구", "수성구", "중구"]
            };

            const citySelect = document.getElementById('addr_city');
            const districtSelect = document.getElementById('addr_district');

            // 1-1) 드롭다운 기본 옵션 채우기
            if (citySelect && districtSelect) {
                // 시/도 옵션
                citySelect.innerHTML = '<option value="" disabled selected>시 / 도</option>';
                Object.keys(districts).forEach(city => {
                    const opt = document.createElement('option');
                    opt.value = city;
                    opt.textContent = city;
                    citySelect.appendChild(opt);
                });

                // 구/군 기본값
                districtSelect.innerHTML =
                    '<option value="" disabled selected>구 / 군</option>';

                // 시/도 선택 시 구/군 옵션 갱신
                citySelect.addEventListener('change', () => {
                    const selectedCity = citySelect.value;
                    const guList = districts[selectedCity] || [];

                    districtSelect.innerHTML =
                        '<option value="" disabled selected>구 / 군</option>';

                    guList.forEach(gu => {
                        const opt = document.createElement('option');
                        opt.value = gu;
                        opt.textContent = gu;
                        districtSelect.appendChild(opt);
                    });
                });
            }

            // 2) DB에서 보호소 정보 불러와서 폼 채우기
            fetch('shelter_edit.php?mode=load')
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }

                    // 아이디, 보호소명, 연락처, 상세주소, 시간
                    document.getElementById('username').value        = data.SHELTER_ID ?? data.shelter_id;
                    document.getElementById('shelter_name').value    = data.NAME       ?? data.name;
                    document.getElementById('contact').value         = data.PHONE      ?? data.phone;
                    document.getElementById('location_detail').value = data.DETAIL     ?? data.detail;
                    document.getElementById('start_time').value      = data.OPEN_TIME  ?? data.open_time;
                    document.getElementById('end_time').value        = data.CLOSE_TIME ?? data.close_time;

                    // 사이드바 보호소 이름
                    document.getElementById('sidebarShelterName').textContent =
                        data.NAME ?? data.name;

                    // 시/도, 구/군 선택값 세팅
                    if (citySelect && districtSelect) {
                        const city     = data.CITY     ?? data.city;
                        const district = data.DISTRICT ?? data.district;

                        if (city) {
                            citySelect.value = city;

                            // 시/도 바뀐 것처럼 이벤트 발생 → 구/군 옵션 채우기
                            const event = new Event('change');
                            citySelect.dispatchEvent(event);
                        }

                        if (district) {
                            districtSelect.value = district;
                        }
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('보호소 정보를 불러오는 중 오류가 발생했습니다.');
                });
		// 🟢 3) 탈퇴 버튼 클릭 이벤트 연결 — 이게 **필수**
    			const btnDelete = document.getElementById('btnDelete');
   			if (btnDelete) {
       		 	btnDelete.addEventListener('click', handleDelete);
    		}
		
    	    });


// ===========================
//  회원 탈퇴
// ===========================
function handleDelete() {
    if (!confirm('정말로 탈퇴하시겠습니까?\n탈퇴 시 모든 정보가 삭제되며 복구할 수 없습니다.')) {
        return;
    }
    if (!confirm('다시 한 번 확인합니다. 탈퇴하시겠습니까?')) {
        return;
    }

    const formData = new URLSearchParams();
    formData.append('action', 'delete');

    fetch('shelter_edit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                alert(data.error + (data.detail ? "\n\n" + data.detail : ""));
                console.error(data);
                return;
            }
            alert('회원 탈퇴가 완료되었습니다.');
            window.location.href = '../index.html';
        })
        .catch(err => {
            console.error(err);
            alert('회원 탈퇴 처리 중 오류가 발생했습니다.');
        });
}

    </script>
</body>

</html>

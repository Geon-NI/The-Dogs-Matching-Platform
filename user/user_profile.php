<?php
// =======================================
// user_profile.php (회원정보 조회/수정 화면 + API)
// =======================================

session_start();

// -----------------------------
// 0. 로그인 체크
// -----------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}

$login_user_id = $_SESSION['user_id'];
$user_name     = '회원';

// DB 접속 정보
$db_username = 'C093299';
$db_password = 'TEST1234';
$db_conn_str = '203.249.87.57/orcl';

// -----------------------------
// 공통 함수: JSON 응답
// -----------------------------
function send_json($data, int $status_code = 200)
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------
// 공통 함수: DB 연결
// -----------------------------
function get_db_connection()
{
    global $db_username, $db_password, $db_conn_str;

    $conn = @oci_connect($db_username, $db_password, $db_conn_str, 'AL32UTF8');
    if (!$conn) {
        $e = oci_error();
        send_json([
            'error'  => 'DB 접속에 실패했습니다.',
            'detail' => $e['message'] ?? ''
        ], 500);
    }
    return $conn;
}

// -----------------------------
// 0-1. 사이드바용 이름 한 번 가져오기
// -----------------------------
$conn_for_name = @oci_connect($db_username, $db_password, $db_conn_str, 'AL32UTF8');
if ($conn_for_name) {
    $uid = str_replace("'", "''", $login_user_id);

    $sql_name = "
        SELECT name
        FROM USERS
        WHERE user_id = '" . $uid . "'
    ";
    $stmt_name = oci_parse($conn_for_name, $sql_name);

    if (oci_execute($stmt_name)) {
        $row_name = oci_fetch_assoc($stmt_name);
        if ($row_name && isset($row_name['NAME'])) {
            $user_name = $row_name['NAME'];
        }
    }

    oci_free_statement($stmt_name);
    oci_close($conn_for_name);
}

// =======================================
// 1. 회원 정보 로드 (GET ?mode=load)
// =======================================
if (isset($_GET['mode']) && $_GET['mode'] === 'load') {

    $conn = get_db_connection();

    // ORA-01745 피하기 위해 문자열 결합 (과제용)
    $uid = str_replace("'", "''", $login_user_id);

    $sql = "
        SELECT
            u.user_id,
            u.name,
            u.phone,
            u.gender,
            TO_CHAR(u.birthdate, 'YYYY') AS birth_year,
            TO_CHAR(u.birthdate, 'MM')   AS birth_month,
            TO_CHAR(u.birthdate, 'DD')   AS birth_day,
            r.city,
            r.district
        FROM USERS u
        LEFT JOIN REGION r ON u.region_id = r.region_id
        WHERE u.user_id = '" . $uid . "'
    ";

    $stmt = oci_parse($conn, $sql);

    if (!oci_execute($stmt)) {
        $e = oci_error($stmt);
        send_json([
            'error'  => '회원 정보를 불러오는 중 오류가 발생했습니다.',
            'detail' => $e['message'] ?? ''
        ], 500);
    }

    $row = oci_fetch_assoc($stmt);
    if (!$row) {
        send_json(['error' => '회원 정보를 찾을 수 없습니다.'], 404);
    }

    $data = [
        'user_id'     => $row['USER_ID'],
        'name'        => $row['NAME'],
        'phone'       => $row['PHONE'],
        'gender'      => $row['GENDER'],    // 'M' or 'F'
        'birthYear'   => $row['BIRTH_YEAR'],
        'birthMonth'  => $row['BIRTH_MONTH'],
        'birthDay'    => $row['BIRTH_DAY'],
        'city'        => $row['CITY'],
        'district'    => $row['DISTRICT']
    ];

    oci_free_statement($stmt);
    oci_close($conn);

    send_json($data);
}

// =======================================
// 2. 회원 정보 수정 (POST action=update)
// =======================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {

    $password = trim($_POST['password'] ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $gender   = trim($_POST['gender']   ?? '');
    $city     = trim($_POST['city']     ?? '');
    $district = trim($_POST['district'] ?? '');

    if ($phone === '' || $gender === '' || $city === '' || $district === '') {
        send_json(['error' => '전화번호, 성별, 거주지는 필수입니다.'], 400);
    }

    if (!preg_match('/^010-[0-9]{4}-[0-9]{4}$/', $phone)) {
        send_json(['error' => '전화번호 형식이 올바르지 않습니다. (예: 010-1234-5678)'], 400);
    }

    if (!in_array($gender, ['M', 'F'], true)) {
        send_json(['error' => '성별 값이 올바르지 않습니다.'], 400);
    }

    $conn = get_db_connection();

    // 2-1. REGION 조회
    $sql_region_select = "
        SELECT region_id
        FROM REGION
        WHERE city = :city AND district = :district
    ";
    $stmt_sel = oci_parse($conn, $sql_region_select);
    oci_bind_by_name($stmt_sel, ':city', $city);
    oci_bind_by_name($stmt_sel, ':district', $district);

    if (!oci_execute($stmt_sel)) {
        $e = oci_error($stmt_sel);
        send_json([
            'error'  => '거주지 정보를 조회하는 중 오류가 발생했습니다.',
            'detail' => $e['message'] ?? ''
        ], 500);
    }

    $row       = oci_fetch_assoc($stmt_sel);
    $region_id = $row['REGION_ID'] ?? null;
    oci_free_statement($stmt_sel);

    // 2-2. REGION 없으면 새로 삽입
    if (!$region_id) {
        $sql_region_insert = "
            INSERT INTO REGION (city, district)
            VALUES (:city, :district)
            RETURNING region_id INTO :rid
        ";
        $stmt_ins = oci_parse($conn, $sql_region_insert);
        oci_bind_by_name($stmt_ins, ':city', $city);
        oci_bind_by_name($stmt_ins, ':district', $district);
        oci_bind_by_name($stmt_ins, ':rid', $region_id, 32);

        if (!oci_execute($stmt_ins, OCI_NO_AUTO_COMMIT)) {
            $e = oci_error($stmt_ins);
            oci_rollback($conn);
            send_json([
                'error'  => '거주지 정보를 저장하는 중 오류가 발생했습니다.',
                'detail' => $e['message'] ?? ''
            ], 500);
        }
        oci_free_statement($stmt_ins);
    }

    // 2-3. USERS 업데이트 (바인드 변수 없이 — ORA-01745 회피)
    // 문자열 안에 ' 들어가면 깨지니까 한 번 이스케이프
    $uid_esc     = str_replace("'", "''", $login_user_id);
    $phone_esc   = str_replace("'", "''", $phone);
    $gender_esc  = str_replace("'", "''", $gender);
    $region_id_n = (int)$region_id;  // 숫자라 형변환

    if ($password !== '') {
        $pw_esc = str_replace("'", "''", $password);

        $sql_update = "
            UPDATE USERS
            SET password  = '" . $pw_esc    . "',
                phone     = '" . $phone_esc . "',
                gender    = '" . $gender_esc . "',
                region_id = "  . $region_id_n . "
            WHERE user_id = '" . $uid_esc . "'
        ";
    } else {
        $sql_update = "
            UPDATE USERS
            SET phone     = '" . $phone_esc  . "',
                gender    = '" . $gender_esc . "',
                region_id = "  . $region_id_n . "
            WHERE user_id = '" . $uid_esc . "'
        ";
    }

    $stmt_upd = oci_parse($conn, $sql_update);

    if (!oci_execute($stmt_upd, OCI_NO_AUTO_COMMIT)) {
        $e = oci_error($stmt_upd);
        oci_rollback($conn);
        send_json([
            'error'  => '회원 정보를 수정하는 중 오류가 발생했습니다.',
            'detail' => $e['message'] ?? ''
        ], 500);
    }

    oci_commit($conn);
    oci_free_statement($stmt_upd);
    oci_close($conn);

    send_json(['success' => true, 'message' => '회원 정보가 수정되었습니다.']);
}

// =======================================
// 3. 회원 탈퇴 (POST action=delete)
// =======================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {

    $conn = get_db_connection();

    // user_id 안에 ' 들어가면 깨지니까 이스케이프
    $uid_esc = str_replace("'", "''", $login_user_id);

    $sql = "
        DELETE FROM USERS
        WHERE user_id = '" . $uid_esc . "'
    ";
    $stmt = oci_parse($conn, $sql);

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $e = oci_error($stmt);
        oci_rollback($conn);
        send_json([
            'error'  => '회원 탈퇴 중 오류가 발생했습니다.',
            'detail' => $e['message'] ?? ''
        ], 500);
    }

    oci_commit($conn);
    oci_free_statement($stmt);
    oci_close($conn);

    session_unset();
    session_destroy();

    send_json(['success' => true, 'message' => '회원 탈퇴가 완료되었습니다.']);
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>회원정보 수정</title>

    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="user-profile.css">
</head>
<body>
<div class="container">

    <!-- 왼쪽 사이드바 -->
    <aside class="sidebar">
        <div class="profile-section">
            <img src="../img/user.png" alt="사용자 아이콘" class="profile-icon">
            <h2 class="profile-name">
                <?php echo htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8'); ?> 님
            </h2>
            <div class="divider"></div>
        </div>

        <nav class="menu">
            <button class="menu-item active" onclick="location.href='user_profile.php'">회원 정보 수정</button>
            <button class="menu-item" onclick="location.href='dog_list.php'">유기견 조회</button>
            <button class="menu-item" onclick="location.href='shelter_list.php'">보호소 조회</button>
            <button class="menu-item" onclick="location.href='adoption_result.php'">입양 심사 결과</button>
	        </nav>

        <button class="logout-btn" id="logoutBtn">
            로그아웃
        </button>
    </aside>

    <!-- 오른쪽 메인 -->
    <main class="main-content">

        <form id="userProfileForm" class="profile-form">
            <!-- 아이디 -->
            <div class="form-group">
                <label>아이디</label>
                <input id="userId" class="form-input readonly" disabled>
            </div>

            <!-- 비밀번호 -->
            <div class="form-group">
                <label>비밀번호</label>
                <input id="password" name="password" type="password"
                       class="form-input" placeholder="변경 시에만 입력">
            </div>

            <!-- 이름 -->
            <div class="form-group">
                <label>이름</label>
                <input id="name" class="form-input readonly" disabled>
            </div>

            <!-- 전화번호 -->
            <div class="form-group">
                <label>전화번호</label>
                <input id="phone" name="phone" class="form-input"
                       placeholder="010-0000-0000">
            </div>

            <!-- 생일 -->
            <div class="form-group">
                <label>생일</label>
                <div class="input-row">
                    <input id="birthYear"  class="form-input third readonly" disabled>
                    <input id="birthMonth" class="form-input third readonly" disabled>
                    <input id="birthDay"   class="form-input third readonly" disabled>
                </div>
            </div>

            <!-- 거주지 -->
            <div class="form-group">
                <label>거주지</label>
                <div class="input-row">
                    <select id="residence1" name="city" class="form-input half">
                        <option value="">시/도 선택</option>
                    </select>
                    <select id="residence2" name="district" class="form-input half">
                        <option value="">구/군 선택</option>
                    </select>
                </div>
            </div>

            <!-- 성별 -->
            <div class="form-group">
                <label>성별</label>
                <div class="input-row">
                    <button type="button" id="maleBtn"   class="gender-btn">남성</button>
                    <button type="button" id="femaleBtn" class="gender-btn">여성</button>
                </div>
            </div>

            <!-- 버튼 -->
            <div class="button-group">
                <button type="submit" class="submit-btn">수정</button>
                <button type="button" id="deleteBtn" class="delete-btn">탈퇴</button>
            </div>
        </form>
    </main>
</div>

<script src="user-profile.js"></script>
</body>
</html>
